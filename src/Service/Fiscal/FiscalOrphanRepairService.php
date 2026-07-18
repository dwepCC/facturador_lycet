<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;

/**
 * Re-encola documentos huérfanos (status queued/pending en BD sin job efectivo en Redis).
 */
class FiscalOrphanRepairService
{
    private FiscalDocumentRepository $repo;
    private FiscalDocumentService $documentService;
    private FiscalQueueService $queue;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalDocumentService $documentService,
        FiscalQueueService $queue
    ) {
        $this->repo = $repo;
        $this->documentService = $documentService;
        $this->queue = $queue;
    }

    /**
     * @return int Cantidad re-encolada en fiscal:emit
     */
    public function repairBatch(int $limit = 50, int $minAgeSeconds = 120): int
    {
        if (!$this->queue->isEnabled()) {
            return 0;
        }

        $requeued = 0;

        $orphans = $this->repo->findEmitOrphans($limit, $minAgeSeconds);
        foreach ($orphans as $doc) {
            if (!$this->documentService->needsEmitRequeue($doc)) {
                continue;
            }
            $ruc = $this->extractRuc($doc);
            if ($ruc === '') {
                continue;
            }
            $this->documentService->requeueEmitJob($doc, (string) ($doc->getFiscalFingerprint() ?? ''), $ruc, 'orphan_repair');
            $requeued++;
        }

        // Reintento lento de errores TRANSITORIOS (SUNAT/red caídos): se re-encolan hasta que
        // SUNAT/PSE dé veredicto o se agote la edad máxima. Los permanentes (retryable=false) se ignoran.
        $maxAge = (int) (getenv('FISCAL_RETRY_MAX_AGE_SEC') ?: ($_ENV['FISCAL_RETRY_MAX_AGE_SEC'] ?? 0));
        if ($maxAge <= 0) {
            $maxAge = 172800; // 48h
        }
        $transient = $this->repo->findRetryableTransientErrors($limit, 60, $maxAge);
        foreach ($transient as $doc) {
            $ruc = $this->extractRuc($doc);
            if ($ruc === '') {
                continue;
            }
            $this->documentService->requeueEmitJob($doc, (string) ($doc->getFiscalFingerprint() ?? ''), $ruc, 'transient_retry');
            $requeued++;
        }

        return $requeued;
    }

    private function extractRuc(FiscalDocument $doc): string
    {
        $snapshot = json_decode($doc->getSnapshotJson(), true);
        if (!is_array($snapshot)) {
            return '';
        }

        return trim((string) ($snapshot['company_ruc'] ?? ($snapshot['company']['ruc'] ?? '')));
    }
}
