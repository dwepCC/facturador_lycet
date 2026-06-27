<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

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

        $orphans = $this->repo->findEmitOrphans($limit, $minAgeSeconds);
        if ($orphans === []) {
            return 0;
        }

        $requeued = 0;
        foreach ($orphans as $doc) {
            if (!$this->documentService->needsEmitRequeue($doc)) {
                continue;
            }

            $snapshot = json_decode($doc->getSnapshotJson(), true);
            $ruc = '';
            if (is_array($snapshot)) {
                $ruc = trim((string) ($snapshot['company_ruc'] ?? ($snapshot['company']['ruc'] ?? '')));
            }
            if ($ruc === '') {
                continue;
            }

            $this->documentService->requeueEmitJob(
                $doc,
                (string) ($doc->getFiscalFingerprint() ?? ''),
                $ruc,
                'orphan_repair'
            );
            $requeued++;
        }

        return $requeued;
    }
}
