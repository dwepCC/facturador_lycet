<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalOrphanRepairService;
use App\Service\Fiscal\FiscalQueueService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-encola documentos fiscales huérfanos (status queued/pending sin provider ni job Redis).
 */
class FiscalRequeueOrphanedCommand extends Command
{
    protected static $defaultName = 'app:fiscal:requeue-orphaned';

    private FiscalDocumentRepository $repo;
    private FiscalOrphanRepairService $orphanRepair;
    private FiscalQueueService $queue;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalOrphanRepairService $orphanRepair,
        FiscalQueueService $queue
    ) {
        parent::__construct();
        $this->repo = $repo;
        $this->orphanRepair = $orphanRepair;
        $this->queue = $queue;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Re-encola en fiscal:emit documentos huérfanos (queued/pending sin provider)')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Máximo de documentos', '500')
            ->addOption('min-age', null, InputOption::VALUE_OPTIONAL, 'Edad mínima en segundos', '120')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo listar, sin encolar');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->queue->isEnabled()) {
            $io->error('REDIS_URL no configurado');
            return Command::FAILURE;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $minAge = max(0, (int) $input->getOption('min-age'));
        $dryRun = (bool) $input->getOption('dry-run');

        $orphans = $this->repo->findEmitOrphans($limit, $minAge);
        if ($orphans === []) {
            $io->success('No hay documentos huérfanos.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Encontrados %d documento(s) huérfano(s):', count($orphans)));
        foreach ($orphans as $doc) {
            $io->writeln($this->formatOrphanLine($doc));
        }

        if ($dryRun) {
            $io->note('Dry-run: no se encoló ningún job.');
            return Command::SUCCESS;
        }

        $requeued = $this->orphanRepair->repairBatch($limit, $minAge);
        $io->success(sprintf('Re-encolados %d documento(s) en fiscal:emit.', $requeued));

        return Command::SUCCESS;
    }

    private function formatOrphanLine(FiscalDocument $doc): string
    {
        return sprintf(
            '  id=%d uuid=%s tenant=%d sale=%d status=%s queued_at=%s',
            $doc->getId(),
            $doc->getDocumentUuid(),
            $doc->getTenantId(),
            $doc->getSaleId(),
            $doc->getStatus(),
            $doc->getQueuedAt() ? $doc->getQueuedAt()->format(DATE_ATOM) : 'null'
        );
    }
}
