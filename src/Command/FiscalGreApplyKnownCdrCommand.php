<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use Doctrine\ORM\EntityManagerInterface;
use Greenter\Ws\Reader\DomCdrReader;
use Greenter\Ws\Reader\XmlReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Corrección administrativa puntual: aplica un CDR de SUNAT ya descargado y verificado
 * en disco (zip) a un FiscalDocument, sin reenviar nada a SUNAT.
 *
 * Existe porque ConsultCdrService (SOAP) — la vía que usa FiscalCdrRecoveryService::recover()
 * para factura/boleta/NC/ND — no cubre guías de remisión (09/31): esas usan el API REST GRE
 * por ticket. Cuando un documento GRE queda con un estado local incorrecto pero ya existe el
 * CDR real de SUNAT en disco (p. ej. recuperado a mano tras un reenvío indebido, ver el guard
 * de idempotencia agregado en FiscalEmitProcessor::process), este comando permite corregirlo
 * sin tocar el pipeline de emisión: reutiliza FiscalCdrRecoveryService::applyRecoveredCdr()
 * (misma lógica que la recuperación automática) y, si se pide, sincroniza el resultado al
 * tenant vía el webhook existente (confirmTenantSync).
 *
 * Uso puntual — no se agrega botón de panel para esto, es intencional: requiere que alguien
 * ya haya verificado el CDR real fuera de banda antes de correrlo.
 */
class FiscalGreApplyKnownCdrCommand extends Command
{
    protected static $defaultName = 'app:fiscal:gre-apply-known-cdr';

    private FiscalDocumentRepository $repo;
    private FiscalCdrRecoveryService $recovery;
    private EntityManagerInterface $em;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalCdrRecoveryService $recovery,
        EntityManagerInterface $em
    ) {
        parent::__construct();
        $this->repo = $repo;
        $this->recovery = $recovery;
        $this->em = $em;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Aplica un CDR de SUNAT ya descargado en disco a un FiscalDocument (guía GRE), sin reenviar.')
            ->addArgument('document_uuid', InputArgument::REQUIRED, 'UUID del FiscalDocument')
            ->addArgument('cdr_zip_path', InputArgument::REQUIRED, 'Ruta local al .zip del CDR ya descargado/verificado')
            ->addOption('sync-tenant', null, InputOption::VALUE_NONE, 'Además de corregir el facturador, sincronizar el resultado al tenant vía webhook')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Solo mostrar qué se aplicaría, sin escribir nada');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $uuid = trim((string) $input->getArgument('document_uuid'));
        $zipPath = trim((string) $input->getArgument('cdr_zip_path'));
        $dryRun = (bool) $input->getOption('dry-run');
        $syncTenant = (bool) $input->getOption('sync-tenant');

        $doc = $this->repo->findOneBy(['documentUuid' => $uuid]);
        if (!$doc instanceof FiscalDocument) {
            $io->error('FiscalDocument no encontrado: ' . $uuid);
            return Command::FAILURE;
        }

        if (!is_file($zipPath)) {
            $io->error('No existe el archivo: ' . $zipPath);
            return Command::FAILURE;
        }
        $cdrZip = (string) file_get_contents($zipPath);
        if ($cdrZip === '') {
            $io->error('El archivo CDR está vacío: ' . $zipPath);
            return Command::FAILURE;
        }

        $tmpDir = sys_get_temp_dir() . '/gre-cdr-' . bin2hex(random_bytes(6));
        mkdir($tmpDir);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            $io->error('No se pudo abrir el zip del CDR.');
            return Command::FAILURE;
        }
        $zip->extractTo($tmpDir);
        $zip->close();
        $xmlFiles = glob($tmpDir . '/*.xml') ?: [];
        if ($xmlFiles === []) {
            $io->error('El zip del CDR no contiene ningún XML.');
            return Command::FAILURE;
        }
        $cdrXml = (string) file_get_contents($xmlFiles[0]);

        $cdrResponse = (new DomCdrReader(new XmlReader()))->getCdrResponse($cdrXml);

        $io->writeln('Documento: uuid=' . $doc->getDocumentUuid() . ' tenant=' . $doc->getTenantSlug()
            . ' ' . $doc->getSeries() . '-' . $doc->getNumber() . ' estado actual=' . $doc->getStatus());
        $io->writeln('CDR: ResponseCode=' . (string) $cdrResponse->getCode() . ' Description=' . (string) $cdrResponse->getDescription());
        $notes = $cdrResponse->getNotes();
        if (is_array($notes) && $notes !== []) {
            $io->writeln('Notas: ' . implode(' | ', $notes));
        }

        if ($dryRun) {
            $io->note('Dry-run: no se aplicó ningún cambio.');
            return Command::SUCCESS;
        }

        $result = $this->recovery->applyRecoveredCdr($doc, $cdrZip, $cdrResponse);
        $io->success('Aplicado en facturador: status=' . $result['status'] . ' sunat_code=' . (string) $result['sunat_code']);
        $io->writeln($result['message']);

        if ($syncTenant) {
            $sync = $this->recovery->confirmTenantSync($doc);
            if ($sync['ok']) {
                $io->success('Sincronizado al tenant: ' . $sync['message']);
            } else {
                $io->error('Falló la sincronización al tenant: ' . $sync['message']);
                return Command::FAILURE;
            }
        } else {
            $io->note('No se sincronizó al tenant (falta --sync-tenant). El facturador ya quedó corregido.');
        }

        return Command::SUCCESS;
    }
}
