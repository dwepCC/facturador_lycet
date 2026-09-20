<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Repository\FiscalAuditLogRepository;
use App\Repository\FiscalDocumentRepository;
use App\Repository\FiscalEmitAttemptRepository;
use App\Repository\FiscalWebhookEventRepository;
use App\Repository\OutboundEmailLogRepository;
use App\Service\Fiscal\FiscalDocumentDetailService;
use PHPUnit\Framework\TestCase;

/**
 * Fase 5 (performance): globalStats() ganó un parámetro opcional $includeTenants (default true,
 * NINGÚN caller existente cambia de comportamiento) para que FiscalOperationsService::summary()
 * pueda saltarse countByTenant() — que calculaba y descartaba sin usar en cada poll de 30s
 * (~125ms medidos en producción con 38k documentos, GROUP BY + ORDER BY + LIMIT 200 sin rango
 * de fecha). Prueba de equivalencia: con $includeTenants=true (o sin pasarlo) el resultado debe
 * ser IDÉNTICO al de antes de Fase 5 en todos los campos, incluido 'tenants'; con false, todo
 * sigue igual EXCEPTO 'tenants', que pasa a [] sin ejecutar la query.
 */
class FiscalDocumentDetailServiceGlobalStatsTest extends TestCase
{
    private function buildService(FiscalDocumentRepository $documents): FiscalDocumentDetailService
    {
        $emails = $this->createMock(OutboundEmailLogRepository::class);
        $emails->method('countPending')->willReturn(3);

        return new FiscalDocumentDetailService(
            $documents,
            $this->createMock(FiscalEmitAttemptRepository::class),
            $emails,
            $this->createMock(FiscalWebhookEventRepository::class),
            $this->createMock(FiscalAuditLogRepository::class),
            null
        );
    }

    public function testDefaultBehaviorStillCallsCountByTenantAndIncludesItInResult(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('countByStatus')->willReturn(['accepted' => 10, 'error' => 2]);
        $documents->method('countByFilters')->willReturn(1);
        $documents->expects($this->once())
            ->method('countByTenant')
            ->with(null)
            ->willReturn([['tenant_slug' => 'demo', 'total' => 12]]);

        $service = $this->buildService($documents);
        $result = $service->globalStats();

        self::assertSame([['tenant_slug' => 'demo', 'total' => 12]], $result['tenants']);
    }

    public function testExplicitIncludeTenantsTrueMatchesDefaultBehaviorExactly(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('countByStatus')->willReturn(['accepted' => 10, 'error' => 2]);
        $documents->method('countByFilters')->willReturn(1);
        $documents->expects($this->once())->method('countByTenant')->willReturn([['tenant_slug' => 'demo', 'total' => 12]]);

        $service = $this->buildService($documents);

        $default = $service->globalStats(null, null, null, null);
        $documents2 = $this->createMock(FiscalDocumentRepository::class);
        $documents2->method('countByStatus')->willReturn(['accepted' => 10, 'error' => 2]);
        $documents2->method('countByFilters')->willReturn(1);
        $documents2->expects($this->once())->method('countByTenant')->willReturn([['tenant_slug' => 'demo', 'total' => 12]]);
        $service2 = $this->buildService($documents2);
        $explicit = $service2->globalStats(null, null, null, null, true);

        self::assertSame($default, $explicit);
    }

    /** El caso real de Fase 5: FiscalOperationsService::summary() pasa includeTenants=false. */
    public function testIncludeTenantsFalseSkipsCountByTenantAndReturnsEmptyArray(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('countByStatus')->willReturn(['accepted' => 10, 'error' => 2]);
        $documents->method('countByFilters')->willReturn(1);
        $documents->expects($this->never())->method('countByTenant');

        $service = $this->buildService($documents);
        $result = $service->globalStats(null, null, null, null, false);

        self::assertSame([], $result['tenants']);
    }

    /**
     * Todos los DEMÁS campos deben ser idénticos entre includeTenants=true/false — la
     * optimización solo afecta 'tenants', ningún otro dato funcional se pierde ni cambia.
     */
    public function testAllOtherFieldsAreIdenticalRegardlessOfIncludeTenants(): void
    {
        $documentsA = $this->createMock(FiscalDocumentRepository::class);
        $documentsA->method('countByStatus')->willReturn([
            'accepted' => 10, 'error' => 2, 'rejected' => 1, 'pending' => 3, 'queued' => 1,
            'sending' => 0, 'retrying' => 4, 'sent' => 2,
        ]);
        $documentsA->method('countByFilters')->willReturn(7);
        $documentsA->method('countByTenant')->willReturn([['tenant_slug' => 'demo', 'total' => 24]]);
        $withTenants = $this->buildService($documentsA)->globalStats(null, null, null, null, true);

        $documentsB = $this->createMock(FiscalDocumentRepository::class);
        $documentsB->method('countByStatus')->willReturn([
            'accepted' => 10, 'error' => 2, 'rejected' => 1, 'pending' => 3, 'queued' => 1,
            'sending' => 0, 'retrying' => 4, 'sent' => 2,
        ]);
        $documentsB->method('countByFilters')->willReturn(7);
        $documentsB->expects($this->never())->method('countByTenant');
        $withoutTenants = $this->buildService($documentsB)->globalStats(null, null, null, null, false);

        foreach (['total', 'documents_today', 'pending', 'in_queue', 'processing', 'sent', 'accepted', 'rejected', 'errors', 'retries', 'emails_pending', 'by_status'] as $key) {
            self::assertSame($withTenants[$key], $withoutTenants[$key], "el campo '{$key}' no debe cambiar según includeTenants");
        }
        self::assertNotSame($withTenants['tenants'], $withoutTenants['tenants']);
    }
}
