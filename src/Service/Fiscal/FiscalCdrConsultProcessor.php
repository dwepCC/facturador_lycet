<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Procesa la cola fiscal:cdr_consult — consulta la validez del comprobante y recupera el CDR
 * SIN reenviarlo.
 *
 * Para un clic manual desde el dashboard ("Consultar CDR" / "Validar con SUNAT"), sigue siendo
 * una operación de una sola pasada, sin efectos colaterales.
 *
 * Para el ciclo automático (`$autoRetry=true`, solo lo usa {@see \App\Command\FiscalWorkerCommand}
 * al drenar `fiscal:cdr_consult:scheduled`), y SOLO para guías de remisión (GRE 09/31) — la API
 * REST de SUNAT nunca devuelve el CDR en el envío, a diferencia de factura/boleta — si la consulta
 * no trae CDR real, se reprograma sola hasta MAX_AUTO_ATTEMPTS veces (ver maybeScheduleAutoRetry()).
 * Factura/boleta/notas vía PSE que caigan en el mismo estado "enviado, CDR pendiente" NO se tocan:
 * siguen 100% manuales, tal como quedó el 19-sep (sección 11.5 de PLAN-MEJORAS-VALIDACION-Y-REINTENTOS.md).
 *
 * El resultado actualiza el estado en el facturador; la sincronización a la BD del tenant
 * queda PENDIENTE de decisión manual (ver {@see FiscalCdrRecoveryService}).
 */
class FiscalCdrConsultProcessor
{
    /** Tope de intentos automáticos (solo GRE) antes de marcar el documento para revisión manual. */
    private const MAX_AUTO_ATTEMPTS = 8;

    private FiscalDocumentRepository $repo;
    private FiscalCdrRecoveryService $recovery;
    private FiscalQueueService $queue;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalCdrRecoveryService $recovery,
        FiscalQueueService $queue,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->repo = $repo;
        $this->recovery = $recovery;
        $this->queue = $queue;
        $this->em = $em;
        $this->logger = $logger;
    }

    /**
     * @param ?string $via 'pse' o 'sunat' para forzar la vía de consulta (null = según el envío del doc).
     * @param bool $autoRetry true solo cuando lo invoca el ciclo automático del worker.
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    public function processByUuid(string $documentUuid, int $attempt = 1, ?string $via = null, bool $autoRetry = false): array
    {
        $doc = $this->repo->findOneBy(['documentUuid' => $documentUuid]);
        if ($doc === null) {
            return $this->emptyResult('Documento no encontrado');
        }

        $hasCdr = $doc->getCdrUrl() !== null && $doc->getCdrUrl() !== '';
        // La consulta de validez/CDR es solo lectura (no reenvía): se permite en cualquier estado,
        // incluido RECHAZADO (se revalida el estado real del comprobante en SUNAT/PSE).
        // Solo se omite cuando no hay nada que hacer: anulado, o ya aceptado/observado CON su CDR.
        if ($doc->getStatus() === FiscalDocument::STATUS_CANCELLED
            || (in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true) && $hasCdr)
        ) {
            return $this->emptyResult('El comprobante ya tiene estado definitivo: ' . $doc->getStatus());
        }

        $result = $this->recovery->recover($doc, $via);

        if ($autoRetry) {
            $this->maybeScheduleAutoRetry($doc, $result, $attempt);
        }

        return $result;
    }

    /**
     * @param array{found: bool, applied: bool, accepted: bool, status: string} $result
     */
    private function maybeScheduleAutoRetry(FiscalDocument $doc, array $result, int $attempt): void
    {
        // Solo guías — nunca factura/boleta/notas (esas quedan 100% manuales, sin tocar el fix del 19-sep).
        if (!GreEmitRouting::isGreDocument($doc)) {
            return;
        }
        // recover() ya movió el status a un veredicto definitivo (CDR real encontrado): nada que reintentar.
        if (($result['found'] ?? false) === true) {
            return;
        }
        if (in_array($doc->getStatus(), [
            FiscalDocument::STATUS_ACCEPTED,
            FiscalDocument::STATUS_OBSERVED,
            FiscalDocument::STATUS_REJECTED,
            FiscalDocument::STATUS_CANCELLED,
        ], true)) {
            return;
        }

        if ($attempt >= self::MAX_AUTO_ATTEMPTS) {
            // Se agotó la ventana automática (~50 min desde el envío) sin que SUNAT/PSE resolviera
            // el ticket. El comprobante SÍ fue enviado — esto no es un rechazo, solo que la
            // confirmación automática no llegó a tiempo. Igual criterio que el resto de casos
            // "manual_only": no se reintenta solo, queda visible en el panel (errors_only ya
            // filtra por status=error) para que una persona use "Consultar CDR" cuando pueda.
            $doc->setStatus(FiscalDocument::STATUS_ERROR);
            $doc->setErrorType('manual_only');
            $doc->setRetryable(false);
            $doc->setSunatMessage(
                'La guía fue enviada correctamente, pero SUNAT/PSE no confirmó el CDR tras '
                . self::MAX_AUTO_ATTEMPTS . ' consultas automáticas (~50 min). No está rechazada — '
                . 'use "Consultar CDR" o "Validar con SUNAT" para verificar el resultado manualmente.'
            );
            $this->em->flush();
            $this->logger->warning('fiscal_gre_auto_consult_exhausted', [
                'uuid' => $doc->getDocumentUuid(),
                'attempts' => $attempt,
            ]);

            return;
        }

        $delaySeconds = min(1200, 30 * (2 ** ($attempt - 1)));
        $this->queue->scheduleCdrConsultRetry($doc->getDocumentUuid(), $attempt + 1, $delaySeconds);
    }

    /**
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    private function emptyResult(string $message): array
    {
        return [
            'found' => false,
            'applied' => false,
            'accepted' => false,
            'status' => '',
            'sunat_code' => null,
            'sunat_message' => null,
            'message' => $message,
        ];
    }
}
