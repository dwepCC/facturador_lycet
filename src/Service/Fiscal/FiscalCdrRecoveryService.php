<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Service\Fiscal\Provider\PseAuthBuilder;
use App\Service\Fiscal\Provider\PseProviderRegistry;
use App\Service\Fiscal\Provider\SunatCdrClassifier;
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
    public function recover(FiscalDocument $doc): array
    {
        $ruc = $this->extractRuc($doc);
        if ($ruc === '') {
            return $this->result(false, false, false, $doc, 'No se pudo determinar el RUC emisor del comprobante.');
        }
        $empresa = $this->empresaRepo->find($ruc);
        if ($empresa === null) {
            return $this->result(false, false, false, $doc, 'Empresa no registrada para el RUC ' . $ruc . '.');
        }

        $sendMode = strtolower(trim((string) ($doc->getSendMode() ?? $empresa->getSendMode())));

        try {
            if ($sendMode === 'pse') {
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
        if (!$cdrResponse instanceof CdrResponse || $cdrZip === null || $cdrZip === '') {
            $msg = $consult['message'] ?? 'SUNAT aún no tiene el CDR disponible para este comprobante.';
            return $this->result(false, false, false, $doc, $msg);
        }

        return $this->applyRecoveredCdr($doc, $cdrZip, $cdrResponse);
    }

    /**
     * @return array{cdr_response: ?CdrResponse, cdr_zip: ?string, message: string}
     */
    private function consultSunatDirect(Empresa $empresa, FiscalDocument $doc, string $ruc): array
    {
        if (strtolower(trim($empresa->getAmbiente())) !== 'produccion') {
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => 'La consulta de CDR SUNAT solo está disponible en producción.'];
        }
        if (trim($empresa->getSolUser()) === '' || trim($empresa->getSolPass()) === '') {
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => 'Credenciales SOL no configuradas para consultar el CDR.'];
        }

        $ws = new SoapClient(SunatEndpoints::FE_CONSULTA_CDR . '?wsdl');
        $ws->setCredentials($empresa->getSolUser(), $empresa->getSolPass());
        $service = new ConsultCdrService();
        $service->setClient($ws);

        $result = $service->getStatusCdr(
            $ruc,
            $doc->getDocumentType(),
            $doc->getSeries(),
            (int) $doc->getNumber()
        );

        if (!$result->isSuccess()) {
            $msg = 'SUNAT no devolvió CDR';
            if (method_exists($result, 'getError') && $result->getError() && method_exists($result->getError(), 'getMessage')) {
                $errMsg = trim((string) $result->getError()->getMessage());
                if ($errMsg !== '') {
                    $msg .= ': ' . $errMsg;
                }
            } elseif (method_exists($result, 'getMessage') && trim((string) $result->getMessage()) !== '') {
                $msg .= ': ' . trim((string) $result->getMessage());
            }
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => $msg];
        }

        return [
            'cdr_response' => $result->getCdrResponse(),
            'cdr_zip' => $result->getCdrZip(),
            'message' => (string) ($result->getMessage() ?? ''),
        ];
    }

    /**
     * @return array{cdr_response: ?CdrResponse, cdr_zip: ?string, message: string}
     */
    private function consultPse(Empresa $empresa, FiscalDocument $doc, string $ruc): array
    {
        $baseUrl = $empresa->resolvePseBaseUrl();
        if ($baseUrl === '') {
            $baseUrl = PseProviderRegistry::baseUrl((string) ($empresa->getProvider() ?? 'validapse'));
        }
        if ($baseUrl === '') {
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => 'pse_base_url no configurada.'];
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
        $resp = json_decode($bodyStr, true);
        if (!is_array($resp)) {
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => 'PSE respondió HTTP ' . $httpCode . ' sin JSON válido.'];
        }

        $cdrField = '';
        foreach (['cdr', 'cdr_base64', 'contenido_cdr'] as $key) {
            if (!empty($resp[$key]) && is_string($resp[$key])) {
                $cdrField = (string) $resp[$key];
                break;
            }
        }
        if ($cdrField === '') {
            $msg = $this->pseMessage($resp) ?: ('PSE aún no tiene el CDR disponible (HTTP ' . $httpCode . ').');
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => $msg];
        }

        $cdrXml = base64_decode($cdrField, true);
        if ($cdrXml === false || $cdrXml === '') {
            return ['cdr_response' => null, 'cdr_zip' => null, 'message' => 'CDR PSE en base64 inválido.'];
        }

        $cdrResponse = (new DomCdrReader(new XmlReader()))->getCdrResponse($cdrXml);
        $cdrZip = CdrNormalizer::toSunatZip($cdrXml, $filenameBase);

        return [
            'cdr_response' => $cdrResponse,
            'cdr_zip' => $cdrZip,
            'message' => $this->pseMessage($resp),
        ];
    }

    /**
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    private function applyRecoveredCdr(FiscalDocument $doc, string $cdrZip, CdrResponse $cdrResponse): array
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

        $this->em->flush();
        $this->notifyOrEnqueueSync($doc);

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

    private function notifyOrEnqueueSync(FiscalDocument $doc): void
    {
        try {
            $this->webhook->notifyStatus($doc);
        } catch (\Throwable $e) {
            $this->logger->warning('fiscal_cdr_recovery_webhook_failed', [
                'uuid' => $doc->getDocumentUuid(),
                'error' => $e->getMessage(),
            ]);
            // Reintento del webhook por la cola dedicada: el backend/tenant recibirá el estado igual.
            try {
                $this->queue->push(FiscalQueueService::QUEUE_WEBHOOK_SYNC, [
                    'document_uuid' => $doc->getDocumentUuid(),
                    'attempt' => 1,
                ]);
            } catch (\Throwable) {
                // Sin Redis, la red de seguridad del reconcile reintentará más tarde.
            }
        }
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
