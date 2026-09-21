<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Entity\FiscalEmitAttempt;
use App\Exception\EmpresaNoRegistradaException;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\Provider\FiscalEmitResult;
use App\Service\Fiscal\Provider\FiscalProviderResolver;
use App\Service\Fiscal\Provider\PseResponseFormatter;
use App\Service\Fiscal\Observability\FiscalAuditService;
use App\Entity\FiscalAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Greenter\Model\DocumentInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * Procesa emisión fiscal vía provider abstraction (SUNAT directo / PSE).
 * Soporta: factura/boleta, NC/ND, guía, resumen, baja.
 */
class FiscalEmitProcessor
{
    private EntityManagerInterface $em;
    private FiscalDocumentRepository $repo;
    private EmpresaRepository $empresaRepo;
    private SerializerInterface $serializer;
    private FiscalStorageService $storage;
    private FiscalWebhookService $webhook;
    private FiscalQueueService $queue;
    private FiscalProviderResolver $providerResolver;
    private FiscalPdfService $pdfService;
    private ?FiscalDocumentPdfResolver $pdfResolver;
    private LoggerInterface $logger;
    private ?FiscalAuditService $audit;

    public function __construct(
        EntityManagerInterface $em,
        FiscalDocumentRepository $repo,
        EmpresaRepository $empresaRepo,
        SerializerInterface $serializer,
        FiscalStorageService $storage,
        FiscalWebhookService $webhook,
        FiscalQueueService $queue,
        FiscalProviderResolver $providerResolver,
        FiscalPdfService $pdfService,
        LoggerInterface $logger,
        ?FiscalAuditService $audit = null,
        ?FiscalDocumentPdfResolver $pdfResolver = null
    ) {
        $this->em = $em;
        $this->repo = $repo;
        $this->empresaRepo = $empresaRepo;
        $this->serializer = $serializer;
        $this->storage = $storage;
        $this->webhook = $webhook;
        $this->queue = $queue;
        $this->providerResolver = $providerResolver;
        $this->pdfService = $pdfService;
        $this->logger = $logger;
        $this->audit = $audit;
        $this->pdfResolver = $pdfResolver;
    }

    public function processByUuid(string $documentUuid): void
    {
        $doc = $this->repo->findOneBy(['documentUuid' => $documentUuid]);
        if ($doc === null) {
            throw new \InvalidArgumentException('Documento fiscal no encontrado: ' . $documentUuid);
        }
        $this->process($doc);
    }

    public function process(FiscalDocument $doc): void
    {
        // Idempotencia: ACCEPTED y OBSERVED son ambos estados terminales (retryable=false,
        // ver más abajo). Si solo se comprobaba ACCEPTED, un documento aceptado-con-observación
        // podía volver a enviarse ante una segunda invocación de process() (job duplicado, doble
        // clic en "reintentar", carrera entre workers) — SUNAT lo rechaza como comprobante
        // duplicado (1033) y esa respuesta terminaba pisando el estado ya aceptado. Bug real
        // que produjo un reenvío indebido de una guía de remisión ya aceptada (ago 2026).
        if (in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true)) {
            return;
        }

        $doc->setStatus(FiscalDocument::STATUS_SENDING);
        $this->em->flush();

        $started = microtime(true);
        $attemptNum = $doc->getRetryCount() + 1;
        $providerName = '';
        $ruc = '';
        $documentClass = '';

        $this->auditSafe(function () use ($doc, $attemptNum): void {
            if ($this->audit === null) {
                return;
            }
            $this->audit->setRequestId($this->audit->generateRequestId());
            $this->audit->fromDocument($doc, 'fiscal_processing_started', FiscalAuditLog::STATUS_PROCESSING, [
                'attempt' => $attemptNum,
            ]);
        });

        try {
            [$documentClass, $greenterDoc] = $this->deserializeSnapshot($doc);
            $ruc = trim((string) $greenterDoc->getCompany()->getRuc());
            $empresa = $this->empresaRepo->find($ruc);
            if ($empresa === null || !$empresa->isEnabled()) {
                throw new EmpresaNoRegistradaException($ruc, 'Empresa fiscal deshabilitada o no registrada');
            }

            $doc->setSendMode($empresa->getSendMode());
            $doc->setProvider($empresa->getProvider());

            $sunatMode = strtolower(trim($empresa->getAmbiente())) === 'produccion' ? 'production' : 'beta';
            $doc->setSunatMode($sunatMode);
            // El ambiente de origen se fija en el primer intento y no se reescribe:
            // es lo que permite distinguir después un comprobante nacido en beta de
            // uno emitido en producción, aunque se reemita.
            if ($doc->getOriginalSunatMode() === null) {
                $doc->setOriginalSunatMode($sunatMode);
            }

            $providerName = $this->providerResolver->resolveName($doc, $empresa);
            $doc->setProvider($providerName);
            $doc->setProviderVersion('1.0');

            $this->auditSafe(function () use ($doc, $providerName, $ruc, $empresa, $attemptNum): void {
                if ($this->audit === null) {
                    return;
                }
                $this->audit->fromDocument($doc, 'fiscal_provider_selected', FiscalAuditLog::STATUS_PROCESSING, [
                    'provider' => $providerName,
                    'ruc' => $ruc,
                    'send_mode' => $empresa->getSendMode(),
                    'connection_type' => $empresa->getConnectionType(),
                    'attempt' => $attemptNum,
                ]);
            });

            $result = $this->providerResolver->emit($doc, $empresa, $documentClass, $greenterDoc);

            if ($this->handleTicketOnlyResult($doc, $documentClass, $greenterDoc, $result, $providerName, $attemptNum, $started, $empresa)) {
                return;
            }

            // SUNAT/PSE indica que el comprobante ya fue informado anteriormente (intento
            // ANTERIOR, ej. 1033): NO reenviar. Consultar el CDR y actualizar estado.
            if ($result->alreadySubmitted) {
                $this->handleAlreadySubmitted($doc, $result, $providerName, $attemptNum, $started, $empresa);
                if ($doc->getFiscalFingerprint()) {
                    $this->queue->releaseClaim($doc->getFiscalFingerprint());
                }
                return;
            }

            // PSE confirmó éxito de ESTE envío pero sin CDR real embebido: no es lo mismo
            // que "ya informado antes" (arriba) ni una aceptación — queda enviado, pendiente
            // de CDR (14.2/14.3, regla 4 del usuario: isSuccess no implica alreadySubmitted).
            if ($result->sentPendingCdr) {
                $this->handleSentPendingCdr($doc, $result, $providerName, $attemptNum, $started);
                if ($doc->getFiscalFingerprint()) {
                    $this->queue->releaseClaim($doc->getFiscalFingerprint());
                }
                return;
            }

            $signedXml = $result->signedXml ?? '';
            if ($signedXml === '') {
                if (!empty($result->pseResponse)) {
                    if ($result->errorType === FiscalDocument::ERROR_TRANSIENT) {
                        // Transitorio real (0109/0100/0154/Server Error, sección 12.3): mismo
                        // camino de reintento automático que una falla de conexión, con el
                        // mismo tope de 5 intentos — NO es un rechazo, no se marca como tal.
                        $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_RETRYING, $result, null, $started);
                        $this->applyFailure($doc, $empresa, FiscalDocument::ERROR_TRANSIENT, $result->pseMessage, $attemptNum, $started);
                    } elseif ($result->errorType === 'manual_only') {
                        // No se resuelve reintentando solo (perfil SOL, nombre de archivo,
                        // fuera de fecha, no identificado) — nunca se auto-programa reintento.
                        $this->handleManualOnlyResult($doc, $result, $providerName, $attemptNum, $started);
                    } else {
                        // 'business': rechazo real de negocio, terminal.
                        $this->handlePseBusinessResult($doc, $result, $providerName, $attemptNum, $started);
                    }
                    if ($doc->getFiscalFingerprint()) {
                        $this->queue->releaseClaim($doc->getFiscalFingerprint());
                    }
                    return;
                }
                throw new \RuntimeException('Emisión sin XML firmado');
            }

            if (($result->pdf === null || $result->pdf === '') && FiscalDocumentClassResolver::supportsPdf($documentClass)) {
                $result->pdf = $this->pdfService->render($documentClass, $greenterDoc, $signedXml);
            }

            $stored = $this->storage->store(
                $doc->getTenantSlug(),
                $doc->getDocumentType(),
                $doc->getSeries(),
                $doc->getNumber(),
                $result->unsignedXml,
                $signedXml,
                $result->cdrZip,
                $result->pdf
            );

            $doc->setSentAt(new \DateTimeImmutable());
            $doc->setHash($result->hash);
            $doc->setXmlUrl($stored['xml_url']);
            $doc->setUnsignedXmlUrl($stored['unsigned_xml_url']);
            $doc->setXmlSignedUrl($stored['xml_signed_url']);
            $doc->setCdrUrl($stored['cdr_url']);
            $doc->setPdfUrl($stored['pdf_url']);
            if (($doc->getPdfUrl() === null || $doc->getPdfUrl() === '') && $this->pdfResolver !== null) {
                try {
                    $this->pdfResolver->generate($doc, true);
                } catch (\Throwable $e) {
                    $this->logger->warning('fiscal_pdf_generate_after_emit_failed', [
                        'uuid' => $doc->getDocumentUuid(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($result->ticket !== null && $result->ticket !== '') {
                $doc->setTicket($result->ticket);
            }
            $doc->setSunatCode($result->sunatCode);
            $doc->setSunatMessage($result->sunatMessage ?? $result->pseMessage);
            if (!empty($result->pseResponse)) {
                $doc->setPseResponseJson(json_encode($result->pseResponse, JSON_UNESCAPED_UNICODE) ?: null);
            } elseif (!empty($result->cdrNotes) || !empty($result->sunatResponse)) {
                $doc->setPseResponseJson(json_encode([
                    'cdr_notes' => $result->cdrNotes,
                    'sunat' => $result->sunatResponse,
                ], JSON_UNESCAPED_UNICODE) ?: null);
            }

            if ($result->isObserved()) {
                $doc->setStatus(FiscalDocument::STATUS_OBSERVED);
                $doc->setAcceptedAt(new \DateTimeImmutable());
                $doc->setErrorType(null);
                $doc->setRetryable(false);
            } elseif ($result->isAccepted()) {
                $doc->setStatus(FiscalDocument::STATUS_ACCEPTED);
                $doc->setAcceptedAt(new \DateTimeImmutable());
                $doc->setErrorType(null);
                $doc->setRetryable(false);
            } elseif ($result->rejected) {
                // Rechazo de negocio SUNAT/PSE (código 2000+): terminal, no se reintenta.
                $doc->setStatus(FiscalDocument::STATUS_REJECTED);
                $doc->setRejectedAt(new \DateTimeImmutable());
                $doc->setErrorType(FiscalDocument::ERROR_BUSINESS);
                $doc->setRetryable(false);
            } elseif ($result->errorType === 'manual_only') {
                // No se resuelve reintentando solo. Llega aquí sobre todo desde el canal
                // directo (Fase 3) — ahí `signedXml` casi siempre existe aunque el envío
                // falle, así que rara vez entra por la rama `if ($signedXml === '')` de
                // arriba. Sin esta rama, `errorType='manual_only'` caía en el `else`
                // transitorio de abajo y se reintentaba solo igual (13.11.4 del plan).
                $this->handleManualOnlyResult($doc, $result, $providerName, $attemptNum, $started);
                if ($doc->getFiscalFingerprint()) {
                    $this->queue->releaseClaim($doc->getFiscalFingerprint());
                }
                return;
            } else {
                // Sin veredicto de SUNAT (CDR nulo / excepción de sistema 0100-1999): falla
                // TRANSITORIA → se reintenta hasta que SUNAT acepte, observe o rechace.
                $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_RETRYING, $result, null, $started);
                $this->applyFailure(
                    $doc,
                    $empresa,
                    $result->errorType ?? FiscalDocument::ERROR_TRANSIENT,
                    $result->sunatMessage ?? $result->pseMessage,
                    $attemptNum,
                    $started
                );
                if ($doc->getFiscalFingerprint()) {
                    $this->queue->releaseClaim($doc->getFiscalFingerprint());
                }
                return;
            }

            $this->recordAttempt($doc, $attemptNum, $providerName, $doc->getStatus(), $result, null, $started);
            $this->em->flush();
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            if (in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true)) {
                $this->auditSafe(function () use ($doc, $attemptNum, $durationMs, $providerName, $ruc): void {
                    if ($this->audit === null) {
                        return;
                    }
                    $this->audit->fromDocument($doc, 'fiscal_emit_success', FiscalAuditLog::STATUS_SUCCESS, [
                        'attempt' => $attemptNum,
                        'duration_ms' => $durationMs,
                        'provider' => $providerName,
                        'ruc' => $ruc,
                    ]);
                });
            } elseif (in_array($doc->getStatus(), [FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_ERROR], true)) {
                $this->auditSafe(function () use ($doc, $attemptNum, $durationMs, $result): void {
                    if ($this->audit === null) {
                        return;
                    }
                    $this->audit->fromDocument($doc, 'fiscal_emit_failed', FiscalAuditLog::STATUS_FAILED, [
                        'attempt' => $attemptNum,
                        'duration_ms' => $durationMs,
                        'error_code' => $result->sunatCode,
                        'error_message' => $result->sunatMessage ?? $result->pseMessage,
                    ]);
                });
            }
            $this->notifyOrEnqueueSync($doc);

            if (in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true) && $empresa->isEmailEnabled()) {
                $this->queue->push(FiscalQueueService::QUEUE_EMAIL, [
                    'document_uuid' => $doc->getDocumentUuid(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('fiscal_emit_failed', [
                'uuid' => $doc->getDocumentUuid(),
                'error' => $e->getMessage(),
            ]);
            $durationMs = (int) round((microtime(true) - $started) * 1000);
            $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_ERROR, null, $e->getMessage(), $started);

            $this->auditSafe(function () use ($doc, $attemptNum, $durationMs, $e, $providerName, $ruc): void {
                if ($this->audit === null) {
                    return;
                }
                $this->audit->fromDocument($doc, 'fiscal_emit_failed', FiscalAuditLog::STATUS_FAILED, [
                    'attempt' => $attemptNum,
                    'duration_ms' => $durationMs,
                    'provider' => $providerName,
                    'ruc' => $ruc,
                    'error_message' => $e->getMessage(),
                ]);
            });

            $empresa = null;
            if ($ruc !== '') {
                $empresa = $this->empresaRepo->find($ruc);
            }
            // Permanente (cert/credenciales/empresa deshabilitada) → no se reintenta solo.
            // Transitorio (red, SUNAT no disponible, excepción de sistema) → se reintenta.
            $errorType = $this->isNonRetryableEmitError($e->getMessage())
                ? FiscalDocument::ERROR_PERMANENT
                : FiscalDocument::ERROR_TRANSIENT;
            $this->applyFailure($doc, $empresa, $errorType, $e->getMessage(), $attemptNum, $started);

            if ($doc->getFiscalFingerprint()) {
                $this->queue->releaseClaim($doc->getFiscalFingerprint());
            }
            throw $e;
        }

        if ($doc->getFiscalFingerprint()) {
            $this->queue->releaseClaim($doc->getFiscalFingerprint());
        }
    }

    private function handleTicketOnlyResult(
        FiscalDocument $doc,
        string $documentClass,
        DocumentInterface $greenterDoc,
        FiscalEmitResult $result,
        string $providerName,
        int $attemptNum,
        float $started,
        Empresa $empresa
    ): bool {
        $ticket = $result->ticket ?? '';
        if ($ticket === '' || !FiscalDocumentClassResolver::isTicketBased($documentClass)) {
            return false;
        }
        if (($result->cdrZip ?? '') !== '' && ($result->success ?? false)) {
            return false;
        }

        $doc->setTicket($ticket);
        $doc->setSentAt(new \DateTimeImmutable());
        $doc->setSunatCode($result->sunatCode);
        $doc->setSunatMessage($result->sunatMessage);
        $doc->setStatus(FiscalDocument::STATUS_SENT);

        $signedXml = trim((string) ($result->signedXml ?? ''));
        if ($signedXml !== '') {
            $pdf = $result->pdf ?? null;
            if (($pdf === null || $pdf === '') && FiscalDocumentClassResolver::supportsPdf($documentClass)) {
                $pdf = $this->pdfService->render($documentClass, $greenterDoc, $signedXml);
            }
            $stored = $this->storage->store(
                $doc->getTenantSlug(),
                $doc->getDocumentType(),
                $doc->getSeries(),
                $doc->getNumber(),
                $result->unsignedXml ?? null,
                $signedXml,
                null,
                $pdf
            );
            $doc->setXmlUrl($stored['xml_url']);
            $doc->setUnsignedXmlUrl($stored['unsigned_xml_url']);
            $doc->setXmlSignedUrl($stored['xml_signed_url']);
            $doc->setPdfUrl($stored['pdf_url']);
            if ($result->hash !== null && $result->hash !== '') {
                $doc->setHash($result->hash);
            }
            if (($doc->getPdfUrl() === null || $doc->getPdfUrl() === '') && $this->pdfResolver !== null) {
                try {
                    $this->pdfResolver->generate($doc, true);
                } catch (\Throwable $e) {
                    $this->logger->warning('fiscal_pdf_generate_after_ticket_emit_failed', [
                        'uuid' => $doc->getDocumentUuid(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_SENT, $result, null, $started);
        $this->em->flush();
        $this->notifyOrEnqueueSync($doc);
        $this->queue->scheduleRetry($doc->getDocumentUuid(), 30, FiscalQueueService::QUEUE_STATUS_POLL);
        if ($doc->getFiscalFingerprint()) {
            $this->queue->releaseClaim($doc->getFiscalFingerprint());
        }
        return true;
    }

    /**
     * El comprobante ya fue informado anteriormente a SUNAT (código 1033 y variantes).
     * NO se reenvía y NO se consulta automáticamente: se marca para revisión manual.
     * El usuario decide cuándo validar/recuperar el CDR con el botón "Consultar CDR".
     */
    private function handleAlreadySubmitted(
        FiscalDocument $doc,
        FiscalEmitResult $result,
        string $providerName,
        int $attemptNum,
        float $started,
        Empresa $empresa
    ): void {
        $this->storeSignedXmlIfMissing($doc, $result, 'fiscal_already_submitted_store_signed_failed');

        if ($doc->getSentAt() === null) {
            $doc->setSentAt(new \DateTimeImmutable());
        }
        // Estado terminal NO reintentable (no se reenvía) que requiere acción manual del usuario.
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setErrorType(FiscalDocument::ERROR_PERMANENT);
        $doc->setRetryable(false);
        $doc->setNextRetryAt(null);
        if ($result->sunatCode !== null && $result->sunatCode !== '') {
            $doc->setSunatCode($result->sunatCode);
        }
        $doc->setSunatMessage(trim(
            'El comprobante fue informado anteriormente a SUNAT. No se reenvía. '
            . 'Use "Consultar CDR" para validar el estado y recuperar el CDR manualmente. '
            . (string) ($result->sunatMessage ?? '')
        ));
        $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_ERROR, $result, null, $started);
        $this->em->flush();
        $this->notifyOrEnqueueSync($doc);
    }

    /**
     * PSE confirmó éxito de ESTE envío (isSuccess:true) pero sin CDR real embebido en la
     * respuesta. No es "ya informado antes" ($alreadySubmitted, que requiere evidencia
     * concreta vía SunatDuplicateClassifier) ni una aceptación (eso exige un CdrResponse
     * real clasificado por SunatCdrClassifier) — es este mismo intento, recibido por el PSE,
     * con el CDR de SUNAT todavía pendiente. No se reenvía (evita duplicar el envío) y no se
     * fabrica ningún código de aceptación: el documento queda `STATUS_SENT`, retryable=false,
     * disponible para que una persona use "Consultar CDR" cuando quiera verificar el
     * resultado real (regla 4 de la sección 14 del plan).
     */
    private function handleSentPendingCdr(
        FiscalDocument $doc,
        FiscalEmitResult $result,
        string $providerName,
        int $attemptNum,
        float $started
    ): void {
        $this->storeSignedXmlIfMissing($doc, $result, 'fiscal_sent_pending_cdr_store_signed_failed');

        $doc->setSentAt($doc->getSentAt() ?? new \DateTimeImmutable());
        $doc->setStatus(FiscalDocument::STATUS_SENT);
        $doc->setErrorType(null);
        $doc->setRetryable(false);
        $doc->setNextRetryAt(null);
        if ($result->sunatCode !== null && $result->sunatCode !== '') {
            $doc->setSunatCode($result->sunatCode);
        }
        $doc->setSunatMessage(trim(
            'Enviado al proveedor (PSE); SUNAT aún no devolvió el CDR real. '
            . 'Use "Consultar CDR" para verificar el resultado. '
            . (string) ($result->sunatMessage ?? '')
        ));
        if (!empty($result->pseResponse)) {
            $doc->setPseResponseJson(json_encode($result->pseResponse, JSON_UNESCAPED_UNICODE) ?: null);
        }
        $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_SENT, $result, null, $started);
        $this->em->flush();
        $this->notifyOrEnqueueSync($doc);

        // Solo para guías (GRE 09/31): la API REST de SUNAT nunca devuelve el CDR en el envío
        // (a diferencia de factura/boleta), así que programamos la primera reconsulta automática
        // acotada (ver FiscalCdrConsultProcessor::maybeScheduleAutoRetry). Para el resto de
        // documentos que caigan en este mismo caso vía PSE, se deja el comportamiento manual del
        // 19-sep tal cual — nada se toca.
        if (GreEmitRouting::isGreDocument($doc)) {
            $this->queue->scheduleCdrConsultRetry($doc->getDocumentUuid(), 1, 30);
        }
    }

    /**
     * SUNAT/PSE respondió, pero el motivo (`errorType='manual_only'`, vía
     * FiscalErrorBucketClassifier — ej. perfil SOL sin habilitar código 0111, nombre de
     * archivo, fuera de fecha, o código no identificado) no se resuelve reintentando el
     * envío solo. Nunca se programa un reintento automático (ni ZSET rápido ni el barrido de
     * huérfanos, porque `errorType` no queda en 'transient') — queda disponible para que una
     * persona decida reenviar manualmente desde el dashboard cuando corresponda.
     */
    private function handleManualOnlyResult(
        FiscalDocument $doc,
        FiscalEmitResult $result,
        string $providerName,
        int $attemptNum,
        float $started
    ): void {
        $this->storeSignedXmlIfMissing($doc, $result, 'fiscal_manual_only_store_signed_failed');

        $doc->setSentAt($doc->getSentAt() ?? new \DateTimeImmutable());
        if ($result->sunatCode !== null && $result->sunatCode !== '') {
            $doc->setSunatCode($result->sunatCode);
        }
        $doc->setSunatMessage(($result->sunatMessage ?? $result->pseMessage) ?: 'Requiere revisión manual del documento');
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setErrorType('manual_only');
        $doc->setRetryable(true);
        $doc->setNextRetryAt(null);
        if (!empty($result->pseResponse)) {
            $doc->setPseResponseJson(json_encode($result->pseResponse, JSON_UNESCAPED_UNICODE) ?: null);
        }
        $this->recordAttempt($doc, $attemptNum, $providerName, FiscalDocument::STATUS_ERROR, $result, null, $started);
        $this->em->flush();
        $this->notifyOrEnqueueSync($doc);
    }

    /**
     * Guarda el XML firmado si el documento todavía no lo tiene persistido — común a los
     * caminos que interceptan la emisión antes del guardado normal (ya informado, enviado
     * pendiente de CDR). No falla el flujo si el guardado falla: solo se registra el warning.
     */
    private function storeSignedXmlIfMissing(FiscalDocument $doc, FiscalEmitResult $result, string $logEvent): void
    {
        $signedXml = trim((string) ($result->signedXml ?? ''));
        if ($signedXml === '' || ($doc->getXmlSignedUrl() !== null && $doc->getXmlSignedUrl() !== '')) {
            return;
        }
        try {
            $stored = $this->storage->store(
                $doc->getTenantSlug(),
                $doc->getDocumentType(),
                $doc->getSeries(),
                $doc->getNumber(),
                $result->unsignedXml,
                $signedXml,
                null,
                $result->pdf
            );
            $doc->setXmlUrl($stored['xml_url']);
            $doc->setUnsignedXmlUrl($stored['unsigned_xml_url']);
            $doc->setXmlSignedUrl($stored['xml_signed_url']);
            if ($result->hash !== null && $result->hash !== '') {
                $doc->setHash($result->hash);
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning($logEvent, [
                'uuid' => $doc->getDocumentUuid(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * PSE respondió (HTTP 200/4xx con JSON) pero sin XML firmado — persistir respuesta y mensaje de negocio.
     */
    private function handlePseBusinessResult(
        FiscalDocument $doc,
        FiscalEmitResult $result,
        string $providerName,
        int $attemptNum,
        float $started
    ): void {
        $pseMessage = $result->pseMessage ?: PseResponseFormatter::message($result->pseResponse);
        $displayMessage = $pseMessage !== ''
            ? $pseMessage
            : ($result->sunatMessage ?? 'Rechazado por PSE');

        $doc->setSentAt(new \DateTimeImmutable());
        $doc->setHash($result->hash !== '' ? $result->hash : null);
        $doc->setSunatCode($result->sunatCode);
        $doc->setSunatMessage($displayMessage);
        if (!empty($result->pseResponse)) {
            $doc->setPseResponseJson(json_encode($result->pseResponse, JSON_UNESCAPED_UNICODE) ?: null);
        }

        if ($result->rejected || !$result->success) {
            $doc->setStatus(FiscalDocument::STATUS_REJECTED);
            $doc->setRejectedAt(new \DateTimeImmutable());
        } else {
            $doc->setStatus(FiscalDocument::STATUS_ERROR);
        }

        $this->recordAttempt($doc, $attemptNum, $providerName, $doc->getStatus(), $result, null, $started);
        $this->em->flush();

        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $this->auditSafe(function () use ($doc, $attemptNum, $durationMs, $result, $displayMessage): void {
            if ($this->audit === null) {
                return;
            }
            $this->audit->fromDocument($doc, 'fiscal_emit_failed', FiscalAuditLog::STATUS_FAILED, [
                'attempt' => $attemptNum,
                'duration_ms' => $durationMs,
                'error_code' => $result->sunatCode,
                'error_message' => $displayMessage,
                'metadata_json' => json_encode([
                    'pse_response' => PseResponseFormatter::summarize($result->pseResponse),
                ], JSON_UNESCAPED_UNICODE) ?: null,
            ]);
        });

        $this->notifyOrEnqueueSync($doc);
    }

    /**
     * @return array{0: string, 1: DocumentInterface}
     */
    private function deserializeSnapshot(FiscalDocument $doc): array
    {
        $raw = $doc->getSnapshotJson();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('snapshot_json inválido');
        }
        if (isset($data['document']) && is_array($data['document'])) {
            $data = $data['document'];
        }

        // Defensa en profundidad: por si el snapshot quedó guardado sin sanear
        // (documentos previos a este fix) o llega alguna vez sin pasar por
        // FiscalDocumentService::enqueue().
        $data = FiscalTextSanitizer::sanitize($data);
        $data = DespatchSnapshotEnricher::enrich($data);

        $class = FiscalDocumentClassResolver::resolve($data, $doc);
        $greenterDoc = $this->serializer->deserialize(json_encode($data), $class, 'json');
        return [$class, $greenterDoc];
    }

    private function recordAttempt(
        FiscalDocument $doc,
        int $attemptNum,
        string $provider,
        string $status,
        ?FiscalEmitResult $result,
        ?string $error,
        float $started
    ): void {
        $attempt = new FiscalEmitAttempt();
        $attempt->setDocumentUuid($doc->getDocumentUuid());
        $attempt->setAttemptNumber($attemptNum);
        $attempt->setProvider($provider ?: null);
        $attempt->setStatus($status);
        $attempt->setDurationMs((int) round((microtime(true) - $started) * 1000));
        if ($result !== null) {
            $attempt->setSunatCode($result->sunatCode);
            $attempt->setSunatMessage($result->sunatMessage);
            $attempt->setPseMessage($result->pseMessage);
        }
        if ($error !== null) {
            $attempt->setErrorMessage($error);
        }
        $this->em->persist($attempt);
    }

    private function notifyOrEnqueueSync(FiscalDocument $doc): void
    {
        try {
            $this->webhook->notifyStatus($doc);
        } catch (\Throwable $e) {
            $this->queue->push(FiscalQueueService::QUEUE_WEBHOOK_SYNC, [
                'document_uuid' => $doc->getDocumentUuid(),
                'attempt' => 1,
            ]);
        }
    }

    private function auditSafe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable) {
        }
    }

    /** Errores de certificado/firma no se resuelven reintentando. */
    /**
     * Tope de intentos TOTALES de envío (incluye el primero). Configurable por
     * FISCAL_MAX_RETRIES — el nombre de la variable de entorno se conserva por
     * compatibilidad, aunque ahora limita intentos totales, no "reintentos" adicionales.
     * Regla cerrada en la sección 14.1 del plan: intento 1 (envío inicial) + hasta 4
     * reintentos automáticos = 5 intentos totales. Al agotarse, ver applyFailure().
     */
    private function maxRetries(): int
    {
        $v = (int) (getenv('FISCAL_MAX_RETRIES') ?: ($_ENV['FISCAL_MAX_RETRIES'] ?? 0));

        return $v > 0 ? $v : 5;
    }

    /**
     * Aplica una falla NO terminal (transitoria o permanente):
     *  - Fija error_type y retry_count.
     *  - Si es transitoria y hay cupo de intentos (retry_count < maxRetries(), 5 por
     *    defecto) → STATUS_RETRYING + backoff (cola ZSET) → reintento automático real.
     *  - Si se agotan los 5 intentos totales, sea transitoria o permanente → STATUS_ERROR
     *    con retryable=false y next_retry_at=null: ningún camino automático (ZSET,
     *    FiscalOrphanRepairService) vuelve a tocarlo. Disponible solo para reenvío manual
     *    desde el dashboard (no depende de `retryable`, ver FiscalController::retry()).
     *    Antes de la sección 14 del plan, el caso transitorio agotado quedaba
     *    `retryable=true` con reintento lento indefinido cada 900s — eliminado.
     */
    private function applyFailure(
        FiscalDocument $doc,
        ?Empresa $empresa,
        string $errorType,
        ?string $message,
        int $attemptNum,
        float $started
    ): void {
        if ($message !== null && $message !== '') {
            $doc->setSunatMessage($message);
        }
        $doc->setErrorType($errorType);
        $doc->setRetryCount($doc->getRetryCount() + 1);

        $permanent = ($errorType === FiscalDocument::ERROR_PERMANENT);
        $retryEnabled = $empresa !== null && $empresa->isRetryEnabled();
        $withinFastRetries = $doc->getRetryCount() < $this->maxRetries();

        if (!$permanent && $retryEnabled && $withinFastRetries) {
            $delay = min(3600, 30 * (2 ** max(0, $doc->getRetryCount() - 1)));
            $doc->setStatus(FiscalDocument::STATUS_RETRYING);
            $doc->setRetryable(true);
            $doc->setNextRetryAt((new \DateTimeImmutable())->modify('+' . $delay . ' seconds'));
            $this->em->flush();
            $this->auditSafe(function () use ($doc, $attemptNum, $delay, $errorType): void {
                if ($this->audit === null) {
                    return;
                }
                $this->audit->fromDocument($doc, 'fiscal_retry_scheduled', FiscalAuditLog::STATUS_RETRYING, [
                    'attempt' => $attemptNum,
                    'metadata_json' => json_encode(['delay_seconds' => $delay, 'error_type' => $errorType]),
                ]);
            });
            $retryQueue = ($doc->getSendMode() === 'pse')
                ? FiscalQueueService::QUEUE_PSE_RETRY
                : FiscalQueueService::QUEUE_RETRY;
            $this->queue->scheduleRetry($doc->getDocumentUuid(), $delay, $retryQueue);

            return;
        }

        // Terminal: ya sea permanente desde el inicio, o transitorio con los 5 intentos
        // agotados — en ambos casos, ningún proceso automático debe volver a encolarlo
        // (sección 14.1 del plan). Disponible únicamente para reenvío manual.
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setRetryable(false);
        $doc->setNextRetryAt(null);
        $this->em->flush();
        $this->notifyOrEnqueueSync($doc);
    }

    /**
     * Pública a propósito: la reutiliza el comando de reclasificación histórica
     * (app:fiscal:reclassify-historical, Fase 7 del plan) para re-evaluar documentos del
     * canal directo con los mismos patrones ya corregidos — no se duplica esta lista en
     * ningún otro lugar.
     */
    public function isNonRetryableEmitError(string $message): bool
    {
        $m = strtolower($message);
        foreach ([
            'openssl_sign',
            'openssl_pkey_get_private',
            'private key',
            'cannot be coerced',
            'certificado inválido: ',
            'certificado inválido',
            'clave privada',
            'cliente no autorizado',
            'token gre rechazado',
            // Excepciones PHP que ni llegan a contactar a SUNAT — nunca se resuelven
            // reintentando el mismo envío (13.11.5 del plan, evidencia real: documentos con
            // 174-203 reintentos acumulados sin poder tener éxito nunca).
            'deshabilitada o no registrada', // EmpresaNoRegistradaException (mensaje de FiscalEmitProcessor)
            'no registrada para el ruc indicado', // EmpresaNoRegistradaException (mensaje por defecto, SeeFactory/SeeApiFactory)
            'no tiene configuradas las credenciales', // SeeApiFactory, credenciales GRE faltantes
            'invalid datetime', // bug de formato de fecha en el snapshot/documento
        ] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        // HTTP 401/403 de la API REST de guías (api-cpe.sunat.gob.pe vía SeeApiFactory,
        // Guzzle ClientException) — credenciales/token inválidos, no una caída de red.
        if (preg_match('/\[40[13]\]\s*client error/i', $m) === 1) {
            return true;
        }

        return false;
    }
}

