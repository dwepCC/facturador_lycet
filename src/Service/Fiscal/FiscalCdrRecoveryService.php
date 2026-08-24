<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Service\Fiscal\Provider\PseAuthBuilder;
use App\Service\Fiscal\Provider\PseProviderRegistry;
use App\Service\Fiscal\Provider\SunatCdrClassifier;
use App\Service\Fiscal\Provider\SunatValidityClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Greenter\Model\Response\CdrResponse;
use Greenter\Ws\Reader\DomCdrReader;
use Greenter\Ws\Reader\XmlReader;
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\SunatEndpoints;
use Psr\Log\LoggerInterface;

/**
 * Consulta la validez de un comprobante ante SUNAT/PSE y, si existe el CDR,
 * lo descarga, lo almacena y actualiza el estado del documento (aceptado /
 * observado / rechazado). Sincroniza el resultado al ERP vía webhook.
 *
 * Uso principal: resolver el caso "El comprobante fue informado anteriormente"
 * (SUNAT ya aceptó pero no devolvió el CDR) sin reenviar el comprobante.
 */
class FiscalCdrRecoveryService
{
    private EmpresaRepository $empresaRepo;
    private FiscalStorageService $storage;
    private FiscalWebhookService $webhook;
    private FiscalQueueService $queue;
    private PseAuthBuilder $pseAuthBuilder;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    public function __construct(
        EmpresaRepository $empresaRepo,
        FiscalStorageService $storage,
        FiscalWebhookService $webhook,
        FiscalQueueService $queue,
        PseAuthBuilder $pseAuthBuilder,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->empresaRepo = $empresaRepo;
        $this->storage = $storage;
        $this->webhook = $webhook;
        $this->queue = $queue;
        $this->pseAuthBuilder = $pseAuthBuilder;
        $this->em = $em;
        $this->logger = $logger;
    }

    /**
     * Consulta el CDR y, si está disponible, actualiza el documento.
     *
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    /**
     * @param ?string $forceMode 'pse' o 'sunat' para forzar la vía de consulta; null = según el envío del doc.
     *                           Permite validar un comprobante PSE directo en SUNAT cuando el PSE falla.
     */
    public function recover(FiscalDocument $doc, ?string $forceMode = null): array
    {
        $ruc = $this->extractRuc($doc);
        if ($ruc === '') {
            return $this->result(false, false, false, $doc, 'No se pudo determinar el RUC emisor del comprobante.');
        }
        $empresa = $this->empresaRepo->find($ruc);
        if ($empresa === null) {
            return $this->result(false, false, false, $doc, 'Empresa no registrada para el RUC ' . $ruc . '.');
        }

        $forceMode = $forceMode !== null ? strtolower(trim($forceMode)) : null;
        $mode = in_array($forceMode, ['pse', 'sunat', 'sunat_direct'], true)
            ? ($forceMode === 'pse' ? 'pse' : 'sunat')
            : strtolower(trim((string) ($doc->getSendMode() ?? $empresa->getSendMode())));

        try {
            if ($mode === 'pse') {
                $consult = $this->consultPse($empresa, $doc, $ruc);
            } else {
                $consult = $this->consultSunatDirect($empresa, $doc, $ruc);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('fiscal_cdr_recovery_failed', [
                'uuid' => $doc->getDocumentUuid(),
                'send_mode' => $sendMode,
                'error' => $e->getMessage(),
            ]);
            return $this->result(false, false, false, $doc, 'Error consultando el CDR: ' . $e->getMessage());
        }

        $cdrResponse = $consult['cdr_response'] ?? null;
        $cdrZip = $consult['cdr_zip'] ?? null;
        // Respuesta CRUDA del proveedor (SUNAT/PSE) para mostrar exactamente qué respondió.
        $detail = (string) ($consult['provider_detail'] ?? ($consult['message'] ?? ''));

        // 1) SUNAT/PSE devolvió el CDR → estado según el CDR (aceptado/observado/rechazado).
        if ($cdrResponse instanceof CdrResponse && $cdrZip !== null && $cdrZip !== '') {
            return $this->withDetail($this->applyRecoveredCdr($doc, $cdrZip, $cdrResponse), $detail);
        }

        // 2) Sin CDR, pero el proveedor respondió: evaluar la VALIDEZ del comprobante.
        //    Si confirma que existe y fue ACEPTADO, el comprobante es válido aunque no haya
        //    CDR disponible → se marca aceptado igual (queda pendiente de sync manual al tenant).
        //    El PSE puede entregar un veredicto directo (isSuccess); SUNAT directo se clasifica por mensaje.
        $statusCode = $consult['status_code'] ?? null;
        $statusMessage = (string) ($consult['status_message'] ?? '');
        $verdict = $consult['verdict'] ?? SunatValidityClassifier::classify($statusCode, $statusMessage);
        if ($verdict === SunatValidityClassifier::ACCEPTED) {
            return $this->withDetail($this->applyValidWithoutCdr($doc, $statusCode, $statusMessage), $detail);
        }

        // 3) No resuelto (no existe / rechazado sin CDR / en proceso / desconocido): se informa,
        //    no se cambia a estado terminal sin evidencia. El mensaje orienta la acción.
        $msg = $this->describeUnresolved($verdict, $statusMessage, (string) ($consult['message'] ?? ''));
        return $this->withDetail($this->result(false, false, false, $doc, $msg), $detail);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withDetail(array $result, string $detail): array
    {
        $result['detail'] = $detail;

        return $result;
    }

    private function describeUnresolved(string $verdict, string $statusMessage, string $fallback): string
    {
        switch ($verdict) {
            case SunatValidityClassifier::NOT_FOUND:
                return 'SUNAT indica que el comprobante NO existe (no fue recibido). '
                    . 'Podría requerir reenvío.' . ($statusMessage !== '' ? ' Detalle: ' . $statusMessage : '');
            case SunatValidityClassifier::REJECTED:
                return 'SUNAT indica RECHAZO. Reintente la consulta para obtener el CDR de rechazo.'
                    . ($statusMessage !== '' ? ' Detalle: ' . $statusMessage : '');
            case SunatValidityClassifier::IN_PROCESS:
                return 'SUNAT indica que el comprobante está EN PROCESO; reintente más tarde.'
                    . ($statusMessage !== '' ? ' Detalle: ' . $statusMessage : '');
            default:
                return $fallback !== '' ? $fallback : 'SUNAT aún no tiene el CDR disponible para este comprobante.';
        }
    }

    /**
     * @return array{cdr_response: ?CdrResponse, cdr_zip: ?string, status_code: ?string, status_message: string, message: string}
     */
    private function consultSunatDirect(Empresa $empresa, FiscalDocument $doc, string $ruc): array
    {
        if (strtolower(trim($empresa->getAmbiente())) !== 'produccion') {
            return $this->consultNull('La consulta de CDR SUNAT solo está disponible en producción.');
        }
        $solUser = trim($empresa->getSolUser());
        $solPass = trim($empresa->getSolPass());
        // 'PSE' es el marcador que usa el facturador cuando una empresa PSE no tiene SOL real.
        if ($solUser === '' || $solPass === '' || strtoupper($solUser) === 'PSE') {
            return $this->consultNull(
                'Configure el usuario y la clave SOL de la empresa para validar directamente en SUNAT '
                . '(configuración fiscal del tenant en el panel central).'
            );
        }

        $tipo = $doc->getDocumentType();
        $serie = $doc->getSeries();
        $numero = (int) $doc->getNumber();

        $ws = new SoapClient(SunatEndpoints::FE_CONSULTA_CDR . '?wsdl');
        $ws->setCredentials($solUser, $solPass);
        $service = new ConsultCdrService();
        $service->setClient($ws);

        $statusCode = '';
        $statusMessage = '';
        $soapError = null;
        $cdrResponse = null;
        $cdrZip = null;

        // 1) CONSULTA DE VALIDEZ (getStatus): devuelve el estado del comprobante (statusCode + statusMessage)
        //    aunque SUNAT no adjunte el CDR. Es la consulta que dice si el comprobante existe/es válido.
        try {
            $st = $service->getStatus($ruc, $tipo, $serie, $numero);
            $statusCode = (string) ($st->getCode() ?? '');
            $statusMessage = (string) ($st->getMessage() ?? '');
            if (!$st->isSuccess() && method_exists($st, 'getError') && $st->getError()) {
                $soapError = trim((string) $st->getError()->getMessage());
            }
        } catch (\Throwable $e) {
            $soapError = 'getStatus: ' . $e->getMessage();
        }

        // 2) RECUPERAR CDR (getStatusCdr): si SUNAT tiene el CDR, se descarga.
        try {
            $cd = $service->getStatusCdr($ruc, $tipo, $serie, $numero);
            if ($cd->isSuccess()) {
                if ($cd->getCdrResponse() !== null) {
                    $cdrResponse = $cd->getCdrResponse();
                    $cdrZip = $cd->getCdrZip();
                }
                if ($statusCode === '') {
                    $statusCode = (string) ($cd->getCode() ?? '');
                }
                if ($statusMessage === '') {
                    $statusMessage = (string) ($cd->getMessage() ?? '');
                }
            } elseif ($soapError === null && method_exists($cd, 'getError') && $cd->getError()) {
                $soapError = trim((string) $cd->getError()->getMessage());
            }
        } catch (\Throwable $e) {
            if ($soapError === null) {
                $soapError = 'getStatusCdr: ' . $e->getMessage();
            }
        }

        $detail = sprintf(
            'SUNAT · getStatus code=%s · msg="%s" · %s%s',
            $statusCode !== '' ? $statusCode : '(vacío)',
            $statusMessage !== '' ? $statusMessage : '(vacío)',
            $cdrResponse !== null ? 'CDR recuperado' : 'sin CDR',
            $soapError !== null && $soapError !== '' ? ' · fault: ' . $soapError : ''
        );
        $this->logger->info('fiscal_cdr_consult_status', [
            'uuid' => $doc->getDocumentUuid(),
            'tipo' => $tipo, 'serie' => $serie, 'numero' => $numero,
            'status_code' => $statusCode,
            'status_message' => $statusMessage,
            'soap_error' => $soapError,
            'has_cdr' => $cdrResponse !== null,
        ]);

        return [
            'cdr_response' => $cdrResponse,
            'cdr_zip' => $cdrZip,
            'status_code' => $statusCode !== '' ? $statusCode : null,
            'status_message' => $statusMessage,
            'provider_detail' => $detail,
            'message' => $statusMessage !== '' ? $statusMessage : ($soapError ?? 'SUNAT no devolvió estado del comprobante'),
        ];
    }

    /**
     * @return array{cdr_response: null, cdr_zip: null, status_code: null, status_message: string, provider_detail: string, message: string}
     */
    private function consultNull(string $message): array
    {
        return [
            'cdr_response' => null,
            'cdr_zip' => null,
            'status_code' => null,
            'status_message' => '',
            'provider_detail' => $message,
            'message' => $message,
        ];
    }

    /**
     * @return array{cdr_response: ?CdrResponse, cdr_zip: ?string, status_code: ?string, status_message: string, message: string}
     */
    private function consultPse(Empresa $empresa, FiscalDocument $doc, string $ruc): array
    {
        $baseUrl = $empresa->resolvePseBaseUrl();
        if ($baseUrl === '') {
            $baseUrl = PseProviderRegistry::baseUrl((string) ($empresa->getProvider() ?? 'validapse'));
        }
        if ($baseUrl === '') {
            return $this->consultNull('pse_base_url no configurada.');
        }

        $filenameBase = CdrNormalizer::filenameBaseFromDocument($doc);
        $prod = strtolower(trim((string) $doc->getSunatMode())) === 'production'
            || strtolower(trim($empresa->getAmbiente())) === 'produccion';
        $path = ($prod ? '/api/cpe/consultar/' : '/api/cpe/consultar-demo/') . rawurlencode($filenameBase);
        $endpoint = $this->buildEndpoint($baseUrl, $path);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->buildPseHeaders($empresa),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $bodyStr = $body === false ? '' : (string) $body;
        $rawBody = trim(mb_substr($bodyStr, 0, 600));
        $resp = json_decode($bodyStr, true);
        if (!is_array($resp)) {
            return $this->consultNull('PSE respondió HTTP ' . $httpCode . ' sin JSON válido. Body: ' . ($rawBody !== '' ? $rawBody : '(vacío)'));
        }

        // Envelope ValidaPSE: isSuccess (bool), estado (200 ok / 400 error), mensaje | message | errors.
        $isSuccess = filter_var($resp['isSuccess'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $estado = isset($resp['estado']) ? (string) $resp['estado'] : null;
        $pseMsg = $this->pseMessage($resp);
        $statusMessage = trim(($estado !== null && $estado !== '' ? $estado . ' ' : '') . $pseMsg);
        $pseDetail = sprintf(
            'PSE · HTTP %d · isSuccess=%s · estado=%s · body: %s',
            $httpCode,
            $isSuccess ? 'true' : 'false',
            $estado ?? '(vacío)',
            $rawBody !== '' ? $rawBody : '(vacío)'
        );
        $this->logger->info('fiscal_cdr_consult_pse', [
            'uuid' => $doc->getDocumentUuid(),
            'http' => $httpCode,
            'is_success' => $isSuccess,
            'estado' => $estado,
            'mensaje' => $pseMsg,
            'body' => $rawBody,
        ]);

        // El CDR (ApplicationResponse) puede venir en 'cdr' (base64). Algunos PSE lo devuelven en 'xml'
        // en la operación de consulta; NO se asume 'xml' salvo que su contenido sea un ApplicationResponse.
        $cdrField = '';
        foreach (['cdr', 'cdr_base64', 'contenido_cdr'] as $key) {
            if (!empty($resp[$key]) && is_string($resp[$key])) {
                $cdrField = (string) $resp[$key];
                break;
            }
        }
        if ($cdrField === '' && !empty($resp['xml']) && is_string($resp['xml'])) {
            $maybeXml = base64_decode((string) $resp['xml'], true);
            if ($maybeXml !== false && stripos($maybeXml, 'ApplicationResponse') !== false) {
                $cdrField = (string) $resp['xml'];
            }
        }

        if ($cdrField !== '') {
            $cdrXml = base64_decode($cdrField, true);
            if ($cdrXml !== false && $cdrXml !== '' && stripos($cdrXml, 'ApplicationResponse') !== false) {
                return [
                    'cdr_response' => (new DomCdrReader(new XmlReader()))->getCdrResponse($cdrXml),
                    'cdr_zip' => CdrNormalizer::toSunatZip($cdrXml, $filenameBase),
                    'status_code' => $estado,
                    'status_message' => $statusMessage,
                    'provider_detail' => $pseDetail,
                    'message' => $pseMsg,
                ];
            }
        }

        // Sin CDR adjunto: el veredicto lo da el propio PSE.
        //  - isSuccess=true  → el comprobante existe y es válido en el PSE → ACEPTADO (aunque no adjunte CDR),
        //    salvo que el mensaje diga explícitamente rechazado.
        //  - isSuccess=false → no válido/no encontrado: se clasifica por el mensaje para informar.
        if ($isSuccess) {
            $verdict = SunatValidityClassifier::classify($estado, $statusMessage);
            if ($verdict !== SunatValidityClassifier::REJECTED) {
                $verdict = SunatValidityClassifier::ACCEPTED;
            }
        } else {
            $verdict = SunatValidityClassifier::classify($estado, $statusMessage);
        }

        return [
            'cdr_response' => null,
            'cdr_zip' => null,
            'status_code' => $estado,
            'status_message' => $statusMessage,
            'verdict' => $verdict,
            'provider_detail' => $pseDetail,
            'message' => $pseMsg ?: ('PSE respondió (HTTP ' . $httpCode . ') sin CDR adjunto.'),
        ];
    }

    /**
     * SUNAT/PSE confirma que el comprobante existe y está ACEPTADO, pero no devolvió el CDR.
     * El comprobante es válido igual → se marca aceptado (sin CDR) y se sincroniza al tenant.
     * El CDR podrá recuperarse después con otra consulta (has_cdr seguirá en false hasta entonces).
     *
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    private function applyValidWithoutCdr(FiscalDocument $doc, ?string $statusCode, string $statusMessage): array
    {
        $doc->setStatus(FiscalDocument::STATUS_ACCEPTED);
        $doc->setAcceptedAt(new \DateTimeImmutable());
        $doc->setErrorType(null);
        $doc->setRetryable(false);
        $doc->setNextRetryAt(null);
        $doc->setSunatCode('0');
        $msg = 'Aceptado por SUNAT (validado por consulta de estado; CDR no disponible aún en SUNAT).';
        if ($statusMessage !== '') {
            $msg .= ' Detalle SUNAT: ' . $statusMessage;
        }
        $doc->setSunatMessage($msg);
        if ($doc->getSentAt() === null) {
            $doc->setSentAt(new \DateTimeImmutable());
        }

        $this->markTenantSyncPending($doc);
        $this->em->flush();

        $this->logger->info('fiscal_cdr_valid_without_cdr', [
            'uuid' => $doc->getDocumentUuid(),
            'status_code' => $statusCode,
            'status_message' => $statusMessage,
        ]);

        return [
            'found' => true,
            'applied' => true,
            'accepted' => true,
            'status' => $doc->getStatus(),
            'sunat_code' => '0',
            'sunat_message' => $doc->getSunatMessage(),
            'message' => 'Comprobante validado como ACEPTADO por SUNAT (sin CDR disponible aún).',
        ];
    }

    /**
     * Pública a propósito: permite aplicar un CDR ya obtenido/verificado por otra vía (p. ej.
     * recuperación manual para guías GRE 09/31, que no usan ConsultCdrService SOAP como
     * factura/boleta y por eso no pasan por consultSunatDirect()). Misma lógica que usa
     * recover() para documentos que sí soportan la consulta SOAP.
     *
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    public function applyRecoveredCdr(FiscalDocument $doc, string $cdrZip, CdrResponse $cdrResponse): array
    {
        $classified = SunatCdrClassifier::fromCdrResponse($cdrResponse);

        $cdrUrl = $this->storage->storeCdr(
            $doc->getTenantSlug(),
            $doc->getDocumentType(),
            $doc->getSeries(),
            $doc->getNumber(),
            $cdrZip
        );
        $doc->setCdrUrl($cdrUrl);
        $doc->setSunatCode($classified['code']);
        $doc->setSunatMessage($classified['message']);
        if (!empty($classified['notes'])) {
            $doc->setPseResponseJson(json_encode(['cdr_notes' => $classified['notes']], JSON_UNESCAPED_UNICODE) ?: null);
        }
        if ($doc->getSentAt() === null) {
            $doc->setSentAt(new \DateTimeImmutable());
        }

        $accepted = false;
        if ($classified['observed']) {
            $doc->setStatus(FiscalDocument::STATUS_OBSERVED);
            $doc->setAcceptedAt(new \DateTimeImmutable());
            $doc->setErrorType(null);
            $doc->setRetryable(false);
            $doc->setNextRetryAt(null);
            $accepted = true;
        } elseif ($classified['success']) {
            $doc->setStatus(FiscalDocument::STATUS_ACCEPTED);
            $doc->setAcceptedAt(new \DateTimeImmutable());
            $doc->setErrorType(null);
            $doc->setRetryable(false);
            $doc->setNextRetryAt(null);
            $accepted = true;
        } else {
            $doc->setStatus(FiscalDocument::STATUS_REJECTED);
            $doc->setRejectedAt(new \DateTimeImmutable());
            $doc->setErrorType(FiscalDocument::ERROR_BUSINESS);
            $doc->setRetryable(false);
            $doc->setNextRetryAt(null);
        }

        $this->markTenantSyncPending($doc);
        $this->em->flush();

        $this->logger->info('fiscal_cdr_recovered', [
            'uuid' => $doc->getDocumentUuid(),
            'status' => $doc->getStatus(),
            'sunat_code' => $doc->getSunatCode(),
        ]);

        $message = $accepted
            ? 'CDR recuperado: comprobante ' . ($classified['observed'] ? 'aceptado con observaciones' : 'aceptado') . ' por SUNAT.'
            : 'CDR recuperado: comprobante rechazado por SUNAT (' . (string) $classified['code'] . ').';

        return [
            'found' => true,
            'applied' => true,
            'accepted' => $accepted,
            'status' => $doc->getStatus(),
            'sunat_code' => $doc->getSunatCode(),
            'sunat_message' => $doc->getSunatMessage(),
            'message' => $message,
        ];
    }

    /**
     * @param array{found: bool, applied: bool, accepted: bool, status?: string} $_
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    private function result(bool $found, bool $applied, bool $accepted, FiscalDocument $doc, string $message): array
    {
        return [
            'found' => $found,
            'applied' => $applied,
            'accepted' => $accepted,
            'status' => $doc->getStatus(),
            'sunat_code' => $doc->getSunatCode(),
            'sunat_message' => $doc->getSunatMessage(),
            'message' => $message,
        ];
    }

    /**
     * Tras una consulta de validez, el estado en el facturador se actualiza, pero la
     * sincronización a la BD del tenant queda PENDIENTE de decisión manual del usuario.
     */
    private function markTenantSyncPending(FiscalDocument $doc): void
    {
        $doc->setTenantSyncState(FiscalDocument::TENANT_SYNC_PENDING);
        $doc->setTenantSyncReason(null);
        $doc->setTenantSyncDecidedAt(null);
    }

    /**
     * El usuario confirma (Sí): se sincroniza el estado a la BD del tenant (webhook al ERP)
     * y, si corresponde, se encola el correo al cliente.
     *
     * @return array{ok: bool, tenant_sync_state: string, message: string}
     */
    public function confirmTenantSync(FiscalDocument $doc): array
    {
        try {
            $this->webhook->notifyStatus($doc);
        } catch (\Throwable $e) {
            $this->logger->warning('fiscal_tenant_sync_webhook_failed', [
                'uuid' => $doc->getDocumentUuid(),
                'error' => $e->getMessage(),
            ]);
            try {
                $this->queue->push(FiscalQueueService::QUEUE_WEBHOOK_SYNC, [
                    'document_uuid' => $doc->getDocumentUuid(),
                    'attempt' => 1,
                ]);
            } catch (\Throwable) {
                return [
                    'ok' => false,
                    'tenant_sync_state' => (string) $doc->getTenantSyncState(),
                    'message' => 'No se pudo notificar al ERP y no hay cola de reintento: ' . $e->getMessage(),
                ];
            }
        }

        $doc->setTenantSyncState(FiscalDocument::TENANT_SYNC_SYNCED);
        $doc->setTenantSyncReason(null);
        $doc->setTenantSyncDecidedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Correo al cliente solo si el comprobante quedó aceptado/observado y la empresa lo tiene activo.
        if (in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true)) {
            $ruc = $this->extractRuc($doc);
            $empresa = $ruc !== '' ? $this->empresaRepo->find($ruc) : null;
            if ($empresa !== null && $empresa->isEmailEnabled()) {
                try {
                    $this->queue->push(FiscalQueueService::QUEUE_EMAIL, ['document_uuid' => $doc->getDocumentUuid()]);
                } catch (\Throwable) {
                    // el correo es best-effort; no bloquea la sincronización
                }
            }
        }

        $this->logger->info('fiscal_tenant_sync_confirmed', ['uuid' => $doc->getDocumentUuid(), 'status' => $doc->getStatus()]);

        return ['ok' => true, 'tenant_sync_state' => FiscalDocument::TENANT_SYNC_SYNCED, 'message' => 'Estado sincronizado a la base de datos del tenant.'];
    }

    /**
     * El usuario decide NO sincronizar (No): se registra la razón; el tenant NO se toca.
     *
     * @return array{ok: bool, tenant_sync_state: string, message: string}
     */
    public function declineTenantSync(FiscalDocument $doc, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'tenant_sync_state' => (string) $doc->getTenantSyncState(), 'message' => 'Debe indicar la razón por la que no se actualizará el tenant.'];
        }

        $doc->setTenantSyncState(FiscalDocument::TENANT_SYNC_SKIPPED);
        $doc->setTenantSyncReason($reason);
        $doc->setTenantSyncDecidedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->logger->info('fiscal_tenant_sync_skipped', [
            'uuid' => $doc->getDocumentUuid(),
            'reason' => $reason,
        ]);

        return ['ok' => true, 'tenant_sync_state' => FiscalDocument::TENANT_SYNC_SKIPPED, 'message' => 'Registrado: el estado NO se actualizará en la base de datos del tenant.'];
    }

    private function extractRuc(FiscalDocument $doc): string
    {
        $snapshot = json_decode($doc->getSnapshotJson(), true);
        if (!is_array($snapshot)) {
            return '';
        }
        if (isset($snapshot['document']) && is_array($snapshot['document'])) {
            $snapshot = $snapshot['document'];
        }
        $ruc = $snapshot['company_ruc'] ?? ($snapshot['company']['ruc'] ?? '');

        return trim((string) $ruc);
    }

    /**
     * @return string[]
     */
    private function buildPseHeaders(Empresa $empresa): array
    {
        $type = strtolower(trim((string) ($empresa->getConnectionType() ?? '')));
        if ($type === 'custom') {
            return $this->pseAuthBuilder->buildHeaders($empresa);
        }
        $token = $empresa->resolvePseToken();
        if ($token === '') {
            throw new \RuntimeException('Token de acceso PSE no configurado para consultar el CDR.');
        }

        return [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
    }

    private function buildEndpoint(string $baseUrl, string $path): string
    {
        $base = rtrim($baseUrl, '/');
        if (substr($base, -4) === '/api') {
            $base = substr($base, 0, -4);
        }

        return $base . $path;
    }

    /**
     * @param array<string, mixed> $resp
     */
    private function pseMessage(array $resp): string
    {
        foreach (['mensaje', 'message', 'errors', 'error'] as $key) {
            if (!empty($resp[$key]) && is_string($resp[$key])) {
                return trim($resp[$key]);
            }
        }

        return '';
    }
}
