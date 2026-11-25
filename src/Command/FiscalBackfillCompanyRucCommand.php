<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FiscalDocument;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rellena fiscal_documents.company_ruc en los documentos anteriores a la columna, leyendo el snapshot.
 * Va por lotes con pausa para no acaparar disco; se puede cortar y volver a lanzar. Solo toca esa
 * columna (SQL directo): updated_at decide reintentos y atascos y no debe moverse.
 */
class FiscalBackfillCompanyRucCommand extends Command
{
    protected static $defaultName = 'app:fiscal:backfill-company-ruc';

    private Connection $db;

    public function __construct(Connection $db)
    {
        parent::__construct();
        $this->db = $db;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rellena company_ruc de los documentos antiguos a partir del snapshot')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Documentos por lote', '500')
            ->addOption('sleep-ms', null, InputOption::VALUE_REQUIRED, 'Pausa entre lotes', '200')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo contar, sin escribir');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = min(5000, max(1, (int) $input->getOption('batch')));
        $sleepMs = max(0, (int) $input->getOption('sleep-ms'));
        $dryRun = (bool) $input->getOption('dry-run');

        $lastId = 0;
        $filled = 0;
        $withoutRuc = 0;
        while (true) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, snapshot_json FROM fiscal_documents WHERE company_ruc IS NULL AND id > ? ORDER BY id LIMIT ' . $batch,
                [$lastId]
            );
            if ($rows === []) {
                break;
            }

            $idsByRuc = [];
            foreach ($rows as $row) {
                $lastId = (int) $row['id'];
                $ruc = FiscalDocument::companyRucFromSnapshot((string) $row['snapshot_json']) ?? '';
                $idsByRuc[$ruc][] = $lastId;
                $ruc === '' ? $withoutRuc++ : $filled++;
            }

            if (!$dryRun) {
                $this->db->transactional(function (Connection $db) use ($idsByRuc): void {
                    foreach ($idsByRuc as $ruc => $ids) {
                        $db->executeStatement(
                            'UPDATE fiscal_documents SET company_ruc = ? WHERE company_ruc IS NULL AND id IN (?)',
                            [(string) $ruc, $ids],
                            [\PDO::PARAM_STR, Connection::PARAM_INT_ARRAY]
                        );
                    }
                });
            }

            $io->writeln(sprintf('hasta id %d: %d con RUC, %d sin RUC', $lastId, $filled, $withoutRuc));
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $io->success(sprintf(
            '%s%d documento(s) con RUC, %d sin RUC identificable.',
            $dryRun ? '[dry-run, nada escrito] ' : '',
            $filled,
            $withoutRuc
        ));

        return Command::SUCCESS;
    }
}
