<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Command\FiscalBackfillCompanyRucCommand;
use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\Setup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Filtro por RUC emisor sobre la columna company_ruc, incluida la convivencia con documentos
 * anteriores a la columna y su backfill. RUC inventados.
 */
class FiscalDocumentCompanyRucFilterTest extends TestCase
{
    private const RUC_A = '20000000001';
    private const RUC_B = '20000000002';

    private EntityManager $em;
    private FiscalDocumentRepository $repo;

    protected function setUp(): void
    {
        $config = Setup::createAnnotationMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, null, null, false);
        // Misma estrategia que doctrine.yaml: el backfill usa SQL con los nombres reales de columna.
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = EntityManager::create($conn, $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(FiscalDocument::class)]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->repo = new FiscalDocumentRepository($registry);
    }

    private function add(string $uuid, array $snapshot): FiscalDocument
    {
        $doc = (new FiscalDocument())
            ->setDocumentUuid($uuid)
            ->setTenantId(1)
            ->setTenantSlug('t1')
            ->setSaleId(random_int(1, 1_000_000))
            ->setDocumentType('03')
            ->setSeries('B001')
            ->setNumber($uuid)
            ->setSnapshotJson((string) json_encode($snapshot));
        $this->em->persist($doc);
        $this->em->flush();

        return $doc;
    }

    /** Simula un documento anterior a la columna. */
    private function forgetRuc(string $uuid): void
    {
        $this->em->getConnection()->executeStatement('UPDATE fiscal_documents SET company_ruc = NULL WHERE document_uuid = ?', [$uuid]);
    }

    /** @return string[] */
    private function uuidsFor(string $ruc): array
    {
        $this->em->clear();
        $uuids = array_map(static fn (FiscalDocument $d): string => $d->getDocumentUuid(), $this->repo->findByFilters(['company_ruc' => $ruc]));
        sort($uuids);
        $this->assertSame(count($uuids), $this->repo->countByFilters(['company_ruc' => $ruc]));

        return $uuids;
    }

    public function testColumnIsFilledFromEitherSnapshotShape(): void
    {
        $this->assertSame(self::RUC_A, $this->add('plano', ['company_ruc' => self::RUC_A])->getCompanyRuc());
        $this->assertSame(self::RUC_B, $this->add('anidado', ['company' => ['ruc' => self::RUC_B]])->getCompanyRuc());
        $this->assertNull($this->add('sin', ['client' => ['numDoc' => self::RUC_A]])->getCompanyRuc());
    }

    public function testFilterUsesEmitterRucForNewAndOldDocuments(): void
    {
        $this->add('nuevo', ['company' => ['ruc' => self::RUC_A]]);
        $this->add('antiguo', ['company' => ['ruc' => self::RUC_A]]);
        $this->add('otro', ['company' => ['ruc' => self::RUC_B]]);
        // El RUC de un tercero dentro del JSON no hace que el documento sea de esa empresa.
        $this->add('tercero', ['company' => ['ruc' => self::RUC_B], 'envio' => ['transportista' => ['ruc' => self::RUC_A]]]);
        $this->forgetRuc('antiguo');

        $this->assertSame(['antiguo', 'nuevo'], $this->uuidsFor(self::RUC_A));
        $this->assertSame(['otro', 'tercero'], $this->uuidsFor(self::RUC_B));
    }

    public function testBackfillFillsOldDocumentsWithoutTouchingUpdatedAt(): void
    {
        $this->add('antiguo', ['company' => ['ruc' => self::RUC_A]]);
        $this->add('sin', ['x' => 1]);
        $this->forgetRuc('antiguo');
        $db = $this->em->getConnection();
        $db->executeStatement("UPDATE fiscal_documents SET updated_at = '2026-01-01 00:00:00'");
        $rows = static fn (): array => $db->fetchAllKeyValue('SELECT document_uuid, company_ruc FROM fiscal_documents ORDER BY document_uuid');

        $tester = new CommandTester(new FiscalBackfillCompanyRucCommand($db));
        $tester->execute(['--dry-run' => true, '--sleep-ms' => '0']);
        $this->assertSame(['antiguo' => null, 'sin' => null], $rows());

        $tester->execute(['--batch' => '1', '--sleep-ms' => '0']);
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['antiguo' => self::RUC_A, 'sin' => ''], $rows());
        $this->assertSame(2, (int) $db->fetchOne("SELECT COUNT(*) FROM fiscal_documents WHERE updated_at = '2026-01-01 00:00:00'"));
        $this->assertSame(['antiguo'], $this->uuidsFor(self::RUC_A));
    }
}
