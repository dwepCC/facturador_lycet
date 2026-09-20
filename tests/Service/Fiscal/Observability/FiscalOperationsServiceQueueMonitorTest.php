<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Observability;

use App\Entity\FiscalDocument;
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
 * Fase 8 del Panel Central Fiscal: queueMonitor() pasó de traer SIEMPRE los 4 buckets (tope
 * fijo de 50, sin forma de ver más ni de paginar) a pedir UN bucket a la vez con offset/limit
 * reales + los 4 contadores livianos para las pestañas. Estos tests prueban la paginación nueva
 * sin tocar la regla de negocio de qué status cae en qué bucket (eso no cambió).
 */
class FiscalOperationsServiceQueueMonitorTest extends TestCase
{
    private function buildService(FiscalDocumentRepository $documents, FiscalQueueService $queue): FiscalOperationsService
    {
        return new FiscalOperationsService(
            $documents,
            $this->createMock(FiscalAuditLogRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalDocumentDetailService::class),
            $queue,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FiscalAlertService::class)
        );
    }

    private function unreachableQueue(): FiscalQueueService
    {
        $queue = $this->createMock(FiscalQueueService::class);
        $queue->method('isReachable')->willReturn(false);

        return $queue;
    }

    public function testDefaultGroupIsQueuedWhenNoneRequested(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->expects($this->once())
            ->method('findByStatuses')
            ->with([FiscalDocument::STATUS_QUEUED, FiscalDocument::STATUS_PENDING], 25, 0)
            ->willReturn([]);
        $documents->method('countByStatuses')->willReturn(0);

        $service = $this->buildService($documents, $this->unreachableQueue());
        $result = $service->queueMonitor();

        self::assertSame('queued', $result['group']);
        self::assertSame(0, $result['offset']);
        self::assertSame(25, $result['limit']);
    }

    public function testUnknownGroupFallsBackToQueuedInsteadOfErroring(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('findByStatuses')->willReturn([]);
        $documents->method('countByStatuses')->willReturn(0);

        $service = $this->buildService($documents, $this->unreachableQueue());
        $result = $service->queueMonitor('algo_invalido');

        self::assertSame('queued', $result['group']);
    }

    public function testRequestedGroupPassesCorrectStatusesAndPagination(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->expects($this->once())
            ->method('findByStatuses')
            ->with([FiscalDocument::STATUS_ERROR, FiscalDocument::STATUS_REJECTED], 10, 20)
            ->willReturn([]);
        $documents->method('countByStatuses')->willReturn(0);

        $service = $this->buildService($documents, $this->unreachableQueue());
        $result = $service->queueMonitor('failed', 10, 20);

        self::assertSame('failed', $result['group']);
        self::assertSame(10, $result['limit']);
        self::assertSame(20, $result['offset']);
    }

    public function testTotalComesFromCountOfTheRequestedGroupOnly(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('findByStatuses')->willReturn([]);
        $documents->method('countByStatuses')->willReturnCallback(
            static fn (array $statuses): int => in_array(FiscalDocument::STATUS_RETRYING, $statuses, true) ? 7 : 0
        );

        $service = $this->buildService($documents, $this->unreachableQueue());
        $result = $service->queueMonitor('retrying');

        self::assertSame(7, $result['total']);
        self::assertSame(7, $result['counts']['retrying']);
    }

    public function testCountsIncludeAllFourGroupsRegardlessOfWhichIsActive(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('findByStatuses')->willReturn([]);
        $documents->method('countByStatuses')->willReturnCallback(
            static function (array $statuses): int {
                if (in_array(FiscalDocument::STATUS_SENDING, $statuses, true)) {
                    return 3;
                }
                return 1;
            }
        );

        $service = $this->buildService($documents, $this->unreachableQueue());
        $result = $service->queueMonitor('processing');

        self::assertSame(['queued', 'processing', 'failed', 'retrying'], array_keys($result['counts']));
        self::assertSame(3, $result['counts']['processing']);
    }

    public function testLimitIsCappedBetween1And100(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->expects($this->once())->method('findByStatuses')->with(self::anything(), 100, self::anything())->willReturn([]);
        $documents->method('countByStatuses')->willReturn(0);

        $service = $this->buildService($documents, $this->unreachableQueue());
        $service->queueMonitor('queued', 500, 0);
    }

    public function testOffsetNeverNegative(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->expects($this->once())->method('findByStatuses')->with(self::anything(), self::anything(), 0)->willReturn([]);
        $documents->method('countByStatuses')->willReturn(0);

        $service = $this->buildService($documents, $this->unreachableQueue());
        $service->queueMonitor('queued', 25, -10);
    }
}
