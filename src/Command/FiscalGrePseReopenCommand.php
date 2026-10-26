<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalQueueService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reabre las guías de remisión (09/31) enviadas por PSE que quedaron en `sent` o en `error/manual_only`
 * porque la consulta automática nunca llegó a preguntarle al PSE (ver FiscalCdrRecoveryService::recover()).
 *
 * NUNCA reenvía nada: solo consulta el PSE (GET /api/cpe/consultar/RUC-TIPO-SERIE-NUMERO) y, si devuelve
 * un CDR real, lo aplica con la misma lógica de siempre. Es idempotente: una guía que ya tiene CDR no es
 * candidata, y repetir el comando solo repite la consulta de las que siguen sin CDR.
 *
 * Por defecto es un ensayo (lista candidatas, no llama a nadie). Con --execute consulta y aplica.
 * Con --rearm, las que sigan sin CDR vuelven al ciclo automático de consultas (ventana de PSE).
 */
class FiscalGrePseReopenCommand extends Command
{
    protected static $defaultName = 'app:fiscal:gre-pse-reopen';

    private FiscalDocumentRepository $repo;
    private FiscalCdrRecoveryService $recovery;
    private FiscalQueueService $queue;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalCdrRecoveryService $recovery,
        FiscalQueueService $queue
    ) {
        parent::__construct();
        $this->repo = $repo;
        $this->recovery = $recovery;
        $this->queue = $queue;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Consulta por PSE (sin reenviar) las guías PSE atascadas sin CDR y aplica el CDR si el PSE lo devuelve.')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Consultar al PSE y aplicar CDR (sin esta opción solo lista las candidatas)')
            ->addOption('rearm', null, InputOption::VALUE_NONE, 'Con --execute: las que sigan sin CDR vuelven al ciclo automático de consultas')
            ->addOption('uuid', null, InputOption::VALUE_REQUIRED, 'Limitar a un document_uuid')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Limitar a un tenant_slug')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Máximo de guías a procesar', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $rearm = (bool) $input->getOption('rearm');
        $limit = max(1, (int) $input->getOption('limit'));

        $qb = $this->repo->createQueryBuilder('d')
            ->andWhere('d.documentType IN (:types)')->setParameter('types', ['09', '31'])
            ->andWhere('d.sendMode = :pse')->setParameter('pse', 'pse')
            ->andWhere('d.attended = :att')->setParameter('att', false)
            ->andWhere('d.sentAt IS NOT NULL')
            ->andWhere('d.cdrUrl IS NULL OR d.cdrUrl = :empty')->setParameter('empty', '')
            ->andWhere('(d.status = :sent) OR (d.status = :err AND d.errorType = :manual)')
            ->setParameter('sent', FiscalDocument::STATUS_SENT)
            ->setParameter('err', FiscalDocument::STATUS_ERROR)
            ->setParameter('manual', 'manual_only')
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit);
        if (($uuid = trim((string) $input->getOption('uuid'))) !== '') {
            $qb->andWhere('d.documentUuid = :uuid')->setParameter('uuid', $uuid);
        }
        if (($tenant = trim((string) $input->getOption('tenant'))) !== '') {
            $qb->andWhere('d.tenantSlug = :tenant')->setParameter('tenant', $tenant);
        }

        /** @var FiscalDocument[] $docs */
        $docs = $qb->getQuery()->getResult();
        $io->writeln(sprintf('%d guía(s) PSE sin CDR candidatas%s.', count($docs), $execute ? '' : ' (ensayo: no se consulta a nadie, use --execute)'));

        $applied = 0;
        $pending = 0;
        foreach ($docs as $doc) {
            $label = sprintf('%s %s-%s [%s]', $doc->getDocumentType(), $doc->getSeries(), $doc->getNumber(), $doc->getDocumentUuid());
            if (!$execute) {
                $io->writeln('  · ' . $label . ' estado=' . $doc->getStatus() . '/' . (string) $doc->getErrorType());
                continue;
            }
            $result = $this->recovery->recover($doc, 'pse');
            if (($result['applied'] ?? false) === true) {
                $applied++;
                $io->writeln('  ✔ ' . $label . ' → ' . $result['status'] . ' (' . (string) $result['sunat_code'] . ')');
                continue;
            }
            $pending++;
            $io->writeln('  … ' . $label . ' sin CDR: ' . (string) ($result['detail'] ?? $result['message']));
            if ($rearm) {
                $this->queue->scheduleCdrConsultRetry($doc->getDocumentUuid(), 1, 30);
            }
        }

        if ($execute) {
            $io->success(sprintf('CDR aplicado: %d · sin CDR: %d%s', $applied, $pending, $rearm && $pending > 0 ? ' · reencoladas al ciclo automático' : ''));
        }

        return Command::SUCCESS;
    }
}
