<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Procesa la cola fiscal:cdr_consult — consulta el CDR de un comprobante que ya
 * fue informado a SUNAT (o que se quiere revalidar manualmente) SIN reenviarlo.
 *
 * Si el CDR está disponible, {@see FiscalCdrRecoveryService} actualiza el estado.
 * Si no, reprograma la consulta con backoff. Nunca vuelve a emitir el comprobante.
 */
class FiscalCdrConsultProcessor
{
    private FiscalDocumentRepository $repo;
    private EmpresaRepository $empresaRepo;
    private FiscalCdrRecoveryService $recovery;
    private FiscalQueueService $queue;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    public function __construct(
        FiscalDocumentRepository $repo,
        EmpresaRepository $empresaRepo,
        FiscalCdrRecoveryService $recovery,
        FiscalQueueService $queue,
        EntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        $this->repo = $repo;
        $this->empresaRepo = $empresaRepo;
        $this->recovery = $recovery;
        $this->queue = $queue;
        $this->em = $em;
        $this->logger = $logger;
    }

    /**
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    public function processByUuid(string $documentUuid, int $attempt = 1): array
    {
        $doc = $this->repo->findOneBy(['documentUuid' => $documentUuid]);
        if ($doc === null) {
            return $this->emptyResult('Documento no encontrado');
        }
        if (in_array($doc->getStatus(), [
            FiscalDocument::STATUS_ACCEPTED,
            FiscalDocument::STATUS_OBSERVED,
            FiscalDocument::STATUS_REJECTED,
            FiscalDocument::STATUS_CANCELLED,
        ], true)) {
            return $this->emptyResult('El comprobante ya tiene estado definitivo: ' . $doc->getStatus());
        }

        $recovery = $this->recovery->recover($doc);

        if (!empty($recovery['found'])) {
            $this->maybeEnqueueEmail($doc);
            return $recovery;
        }

        $this->reschedule($doc, $attempt);
        return $recovery;
    }

    private function reschedule(FiscalDocument $doc, int $attempt): void
    {
        $maxAge = (int) (getenv('FISCAL_CDR_CONSULT_MAX_AGE_SEC') ?: ($_ENV['FISCAL_CDR_CONSULT_MAX_AGE_SEC'] ?? 0));
        if ($maxAge <= 0) {
            $maxAge = 86400; // 24h
        }
        $ageSeconds = time() - $doc->getCreatedAt()->getTimestamp();
        if ($ageSeconds >= $maxAge) {
            // Tras la ventana máxima sin CDR: se detiene la consulta automática (no reenvío).
            // Permanente + no-retryable → el reconcile de errores transitorios NO lo re-encola a emisión.
            $doc->setStatus(FiscalDocument::STATUS_ERROR);
            $doc->setErrorType(FiscalDocument::ERROR_PERMANENT);
            $doc->setRetryable(false);
            $doc->setNextRetryAt(null);
            $doc->setSunatMessage(
                'No se pudo recuperar el CDR tras ' . (int) round($maxAge / 3600) . 'h. '
                . 'El comprobante fue informado a SUNAT; use "Consultar CDR" para revalidar manualmente.'
            );
            $this->em->flush();
            $this->logger->warning('fiscal_cdr_consult_gave_up', [
                'uuid' => $doc->getDocumentUuid(),
                'age_seconds' => $ageSeconds,
            ]);
            return;
        }

        if (!$this->queue->isEnabled()) {
            return;
        }
        $delay = (int) min(1800, 120 * max(1, $attempt));
        $doc->setNextRetryAt((new \DateTimeImmutable())->modify('+' . $delay . ' seconds'));
        $this->em->flush();
        $this->queue->scheduleRetry($doc->getDocumentUuid(), $delay, FiscalQueueService::QUEUE_CDR_CONSULT);
    }

    private function maybeEnqueueEmail(FiscalDocument $doc): void
    {
        if (!in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true)) {
            return;
        }
        $ruc = $this->extractRuc($doc);
        if ($ruc === '') {
            return;
        }
        $empresa = $this->empresaRepo->find($ruc);
        if ($empresa === null || !$empresa->isEmailEnabled()) {
            return;
        }
        try {
            $this->queue->push(FiscalQueueService::QUEUE_EMAIL, ['document_uuid' => $doc->getDocumentUuid()]);
        } catch (\Throwable $e) {
            $this->logger->warning('fiscal_cdr_consult_email_enqueue_failed', [
                'uuid' => $doc->getDocumentUuid(),
                'error' => $e->getMessage(),
            ]);
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

        return trim((string) ($snapshot['company_ruc'] ?? ($snapshot['company']['ruc'] ?? '')));
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
