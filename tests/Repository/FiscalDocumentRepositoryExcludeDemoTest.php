<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * "Incluir demo" del dashboard: por defecto se ocultan los documentos enviados a SUNAT beta (ambiente
 * demo). Un documento sin ambiente todavía (nunca se envió) NO se oculta, y uno emitido en beta y
 * reemitido en producción cuenta como producción. Con SQLite en memoria para ejercitar el DQL real.
 */
class FiscalDocumentRepositoryExcludeDemoTest extends TestCase
{
    private EntityManager $em;
    private FiscalDocumentRepository $repo;

    protected function setUp(): void
    {
        $config = Setup::createAnnotationMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true, null, null, false);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = EntityManager::create($conn, $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(FiscalDocument::class)]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->repo = new FiscalDocumentRepository($registry);
    }

    private function add(string $uuid, ?string $sunatMode, string $status = 'accepted', bool $attended = false): void
    {
        $doc = (new FiscalDocument())
            ->setDocumentUuid($uuid)
            ->setTenantId(1)
            ->setTenantSlug('t1')
            ->setSaleId(random_int(1, 1_000_000))
            ->setDocumentType('03')
            ->setSeries('B001')
            ->setNumber($uuid)
            ->setStatus($status)
            ->setSnapshotJson('{}')
            ->setSunatMode($sunatMode)
            ->setAttended($attended);
        $this->em->persist($doc);
        $this->em->flush();
    }

    public function testExcludeDemoHidesOnlyBetaDocuments(): void
    {
        $this->add('prod', 'production');
        $this->add('demo', 'beta');
        $this->add('sin-enviar', null, 'pending');

        $this->assertSame(3, $this->repo->countByFilters([]), 'sin el filtro se ven todos');
        $this->assertSame(2, $this->repo->countByFilters(['exclude_demo' => true]), 'oculta solo el de beta; el que aún no se envió se queda');
        $this->assertSame(['production' => 1], $this->countByMode($this->repo->findByFilters(['exclude_demo' => true, 'status' => 'accepted'])));
    }

    public function testExcludeDemoAppliesToStatusCountsAndNeedsAction(): void
    {
        $this->add('r-prod', 'production', 'rejected');
        $this->add('r-demo', 'beta', 'rejected');

        $this->assertSame(['rejected' => 2], $this->repo->countByStatus());
        $this->assertSame(['rejected' => 1], $this->repo->countByStatus(null, null, null, false, true));
        $this->assertSame(2, $this->repo->countUnattendedNeedingAction());
        $this->assertSame(1, $this->repo->countUnattendedNeedingAction(null, true));
    }

    public function testAttendedFiltersAreIndependentOfDemoFilter(): void
    {
        $this->add('a', 'production', 'rejected', true);
        $this->add('b', 'production', 'rejected', false);
        $this->add('c', 'beta', 'rejected', false);

        $this->assertSame(1, $this->repo->countByFilters(['exclude_demo' => true, 'attended_only' => true]));
        $this->assertSame(1, $this->repo->countByFilters(['exclude_demo' => true, 'unattended_only' => true]));
        $this->assertSame(2, $this->repo->countByFilters(['unattended_only' => true]));
    }

    /**
     * @param FiscalDocument[] $docs
     * @return array<string, int>
     */
    private function countByMode(array $docs): array
    {
        $out = [];
        foreach ($docs as $d) {
            $out[(string) $d->getSunatMode()] = ($out[(string) $d->getSunatMode()] ?? 0) + 1;
        }

        return $out;
    }
}
