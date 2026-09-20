<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Observability;

use App\Entity\Empresa;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalAuditLogRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\Observability\FiscalAlertService;
use App\Service\Fiscal\Observability\FiscalOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Fase 8 del Panel Central Fiscal: tenantsTable() nunca paginaba — devolvía TODOS los tenants
 * habilitados que pasaran los filtros (hasta 384 en producción), y el frontend los pintaba
 * todos como filas sin ningún límite. `total` ya existía (era simplemente count(items)); ahora
 * además corta la página real con limit/offset, preservando el total SIN paginar para que el
 * frontend pueda mostrar "mostrando X-Y de Z".
 */
class FiscalOperationsServiceTenantsTableTest extends TestCase
{
    private function empresa(string $slug, string $ruc): Empresa
    {
        return (new Empresa())
            ->setRuc($ruc)
            ->setSolUser('')
            ->setSolPass('')
            ->setAmbiente('produccion')
            ->setTenantId(1)
            ->setTenantSlug($slug)
            ->setSendMode('sunat_direct')
            ->setConnectionType('bearer')
            ->setConnectionStatus('connected');
    }

    private function buildService(EmpresaRepository $empresas): FiscalOperationsService
    {
        $auditLogs = $this->createMock(FiscalAuditLogRepository::class);
        $auditLogs->method('tenantOperationsSummary')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);
        $em->method('getConnection')->willReturn($connection);

        return new FiscalOperationsService(
            $this->createMock(FiscalDocumentRepository::class),
            $auditLogs,
            $empresas,
            $this->createMock(FiscalDocumentDetailService::class),
            $this->createMock(FiscalQueueService::class),
            $em,
            $this->createMock(FiscalAlertService::class)
        );
    }

    private function empresaRepoWith(array $empresas): EmpresaRepository
    {
        $repo = $this->createMock(EmpresaRepository::class);
        $repo->method('findBy')->willReturn($empresas);

        return $repo;
    }

    public function testTotalReflectsAllMatchingTenantsNotJustThePage(): void
    {
        $empresas = [];
        for ($i = 1; $i <= 30; $i++) {
            $empresas[] = $this->empresa("tenant-{$i}", str_pad((string) $i, 11, '0', STR_PAD_LEFT));
        }
        $service = $this->buildService($this->empresaRepoWith($empresas));

        $result = $service->tenantsTable([], 10, 0);

        self::assertSame(30, $result['total']);
        self::assertCount(10, $result['items']);
    }

    public function testOffsetSlicesThePageCorrectly(): void
    {
        $empresas = [];
        for ($i = 1; $i <= 5; $i++) {
            $empresas[] = $this->empresa("tenant-{$i}", str_pad((string) $i, 11, '0', STR_PAD_LEFT));
        }
        $service = $this->buildService($this->empresaRepoWith($empresas));

        $page1 = $service->tenantsTable([], 2, 0);
        $page2 = $service->tenantsTable([], 2, 2);
        $page3 = $service->tenantsTable([], 2, 4);

        self::assertSame(['tenant-1', 'tenant-2'], array_column($page1['items'], 'tenant_slug'));
        self::assertSame(['tenant-3', 'tenant-4'], array_column($page2['items'], 'tenant_slug'));
        self::assertSame(['tenant-5'], array_column($page3['items'], 'tenant_slug'));
        self::assertSame(5, $page1['total']);
        self::assertSame(5, $page3['total']);
    }

    public function testDefaultPaginationMatchesPreviousBehaviorForSmallSets(): void
    {
        // Con menos tenants que el limit por defecto, el resultado debe ser idéntico al
        // comportamiento anterior a Fase 8 (todos los items, sin recorte visible).
        $empresas = [$this->empresa('demo', '20000000001')];
        $service = $this->buildService($this->empresaRepoWith($empresas));

        $result = $service->tenantsTable();

        self::assertCount(1, $result['items']);
        self::assertSame(1, $result['total']);
    }

    public function testFiltersStillApplyBeforePagination(): void
    {
        $empresas = [
            $this->empresa('alfa', '20000000001'),
            $this->empresa('beta', '20000000002'),
        ];
        $service = $this->buildService($this->empresaRepoWith($empresas));

        $result = $service->tenantsTable(['tenant_slug' => 'beta'], 10, 0);

        self::assertSame(1, $result['total']);
        self::assertSame('beta', $result['items'][0]['tenant_slug']);
    }

    public function testOffsetBeyondTotalReturnsEmptyItemsButCorrectTotal(): void
    {
        $empresas = [$this->empresa('demo', '20000000001')];
        $service = $this->buildService($this->empresaRepoWith($empresas));

        $result = $service->tenantsTable([], 10, 100);

        self::assertSame([], $result['items']);
        self::assertSame(1, $result['total']);
    }
}
