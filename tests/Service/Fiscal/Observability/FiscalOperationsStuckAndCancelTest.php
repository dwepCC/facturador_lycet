<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Observability;

use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalAuditLogRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalClock;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\Observability\FiscalAlertService;
use App\Service\Fiscal\Observability\FiscalAuditService;
use App\Service\Fiscal\Observability\FiscalOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Operaciones fiscales: documentos "atascados" en la cola en vivo, y cancelar desde Operaciones
 * deja el documento ATENDIDO (decisión explícita: no debe volver a Pendientes).
 */
class FiscalOperationsStuckAndCancelTest extends TestCase
{
    private function doc(string $status, ?string $updated = null, ?string $nextRetry = null): FiscalDocument
    {
        $doc = new FiscalDocument();
        $doc->setStatus($status);
        $ref = new \ReflectionProperty(FiscalDocument::class, 'updatedAt');
        $ref->setAccessible(true);
        // updated_at / next_retry_at los escribe el worker: se construyen con SU reloj (ver FiscalClock),
        // así la prueba no depende de la zona horaria del proceso que la ejecuta.
        $cli = FiscalClock::cliTimezone();
        $ref->setValue($doc, new \DateTimeImmutable($updated ?? 'now', $cli));
        // En cola/pendiente el reloj que cuenta es queued_at (lo escribe la web).
        if (in_array($status, [FiscalDocument::STATUS_QUEUED, FiscalDocument::STATUS_PENDING], true)) {
            $doc->setQueuedAt(new \DateTimeImmutable($updated ?? 'now'));
        }
        if ($nextRetry !== null) {
            $doc->setNextRetryAt(new \DateTimeImmutable($nextRetry, $cli));
        }

        return $doc;
    }

    public function testQueuedIsStuckOnlyAfterFifteenMinutes(): void
    {
        self::assertFalse(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_QUEUED, '-5 minutes')));
        self::assertTrue(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_QUEUED, '-20 minutes')));
        self::assertTrue(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_PENDING, '-3 hours')));
    }

    public function testSendingUsesTenMinutesAndSentWaitsThirty(): void
    {
        self::assertFalse(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_SENDING, '-5 minutes')));
        self::assertTrue(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_SENDING, '-12 minutes')));
        // Enviado a SUNAT esperando el CDR: 12 min es normal, 45 no.
        self::assertFalse(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_SENT, '-12 minutes')));
        self::assertTrue(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_SENT, '-45 minutes')));
    }

    public function testRetryingIsStuckWhenItsScheduledRetryIsOverdue(): void
    {
        // Reintento programado a futuro: normal, aunque el documento sea viejo.
        self::assertFalse(FiscalDocumentRepository::isStuck(
            $this->doc(FiscalDocument::STATUS_RETRYING, '-3 hours', '+10 minutes')
        ));
        // Programado hace 20 min y nadie lo tomó: atascado.
        self::assertTrue(FiscalDocumentRepository::isStuck(
            $this->doc(FiscalDocument::STATUS_RETRYING, '-3 hours', '-20 minutes')
        ));
        // Sin programación: 30 min sin avanzar.
        self::assertFalse(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_RETRYING, '-10 minutes')));
        self::assertTrue(FiscalDocumentRepository::isStuck($this->doc(FiscalDocument::STATUS_RETRYING, '-40 minutes')));
    }

    public function testTerminalStatusesAreNeverStuck(): void
    {
        foreach ([FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_REJECTED, FiscalDocument::STATUS_ERROR, FiscalDocument::STATUS_CANCELLED] as $st) {
            self::assertFalse(FiscalDocumentRepository::isStuck($this->doc($st, '-10 days')), $st);
        }
    }

    public function testStuckGroupReadsFromFindStuck(): void
    {
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->expects($this->once())->method('findStuck')->with(25, 0)->willReturn([]);
        $documents->expects($this->never())->method('findByStatuses');
        $documents->method('countStuck')->willReturn(2);
        $documents->method('countByStatuses')->willReturn(0);
        $documents->method('countUnattendedNeedingAction')->willReturn(0);
        $queue = $this->createMock(FiscalQueueService::class);
        $queue->method('isReachable')->willReturn(false);

        $result = $this->service($documents, $queue)->queueMonitor('stuck', 25, 0);

        self::assertSame('stuck', $result['group']);
        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['counts']['stuck']);
    }

    public function testCancelMarksTheDocumentAsAttended(): void
    {
        $doc = $this->doc(FiscalDocument::STATUS_QUEUED);
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('findOneBy')->willReturn($doc);

        $service = $this->service($documents, $this->createMock(FiscalQueueService::class));
        $service->cancelDocument('uuid-1', $this->createMock(FiscalAuditService::class));

        self::assertSame(FiscalDocument::STATUS_CANCELLED, $doc->getStatus());
        self::assertTrue($doc->isAttended());
        self::assertSame('Cancelado desde Operaciones fiscales', $doc->getAttendedReason());
        self::assertNotNull($doc->getAttendedAt());
    }

    public function testCancelStillRejectsDocumentsThatAreNotInTheQueue(): void
    {
        $doc = $this->doc(FiscalDocument::STATUS_ACCEPTED);
        $documents = $this->createMock(FiscalDocumentRepository::class);
        $documents->method('findOneBy')->willReturn($doc);

        $this->expectException(\InvalidArgumentException::class);
        $this->service($documents, $this->createMock(FiscalQueueService::class))
            ->cancelDocument('uuid-2', $this->createMock(FiscalAuditService::class));
    }

    private function service(FiscalDocumentRepository $documents, FiscalQueueService $queue): FiscalOperationsService
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
}
