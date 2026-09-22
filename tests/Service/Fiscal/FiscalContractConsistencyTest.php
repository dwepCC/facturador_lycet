<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Controller\v1\FiscalController;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalAlertRepository;
use App\Repository\FiscalAuditLogRepository;
use App\Repository\FiscalDocumentRepository;
use App\Repository\FiscalEmitAttemptRepository;
use App\Repository\FiscalWebhookEventRepository;
use App\Repository\OutboundEmailLogRepository;
use App\Service\Fiscal\FiscalBulkActionService;
use App\Service\Fiscal\FiscalCdrConsultProcessor;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalCompanySyncService;
use App\Service\Fiscal\FiscalConnectionTestService;
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalDocumentPdfResolver;
use App\Service\Fiscal\FiscalDocumentService;
use App\Service\Fiscal\FiscalFileFetcher;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\Observability\FiscalOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fase 4 (sección 4 de la auditoría): prueba de consistencia de contrato entre los 3
 * serializadores de documento fiscal que existen en todo el proyecto —
 * FiscalController::serializeDocSummary() (GET /documents, corregido en Fase 2 — H3),
 * FiscalDocumentDetailService::serializeDocument() (GET /documents/{uuid}), y
 * FiscalOperationsService::serializeQueueItem() (GET /operations/queue) — para el MISMO
 * documento, error_type/retryable/next_retry_at/retry_count/status deben ser idénticos. No debe
 * volver a ocurrir que list mande error_type ausente mientras detail sí lo manda (bug H3).
 */
class FiscalContractConsistencyTest extends TestCase
{
    private function makeDocument(): FiscalDocument
    {
        return (new FiscalDocument())
            ->setDocumentUuid('uuid-contract-1')
            ->setTenantId(1)
            ->setTenantSlug('tenant-test')
            ->setSaleId(100)
            ->setDocumentType('03')
            ->setSeries('B001')
            ->setNumber('00001')
            ->setStatus(FiscalDocument::STATUS_ERROR)
            ->setErrorType(FiscalDocument::ERROR_TRANSIENT)
            ->setRetryable(true)
            ->setRetryCount(3)
            ->setNextRetryAt(new \DateTimeImmutable('2026-09-20T00:16:01+00:00'))
            ->setSnapshotJson('{}');
    }

    private function serializeViaList(FiscalDocument $doc): array
    {
        $controller = new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalDocumentDetailService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(FiscalFileFetcher::class),
            $this->createMock(FiscalBulkActionService::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalCompanySyncService::class),
            $this->createMock(FiscalConnectionTestService::class),
            $this->createMock(FiscalDocumentPdfResolver::class),
            $this->createMock(FiscalCdrConsultProcessor::class),
            $this->createMock(FiscalCdrRecoveryService::class),
            $this->createMock(EntityManagerInterface::class)
        );
        $ref = new ReflectionMethod(FiscalController::class, 'serializeDocSummary');
        $ref->setAccessible(true);

        return $ref->invoke($controller, $doc, null);
    }

    private function serializeViaDetail(FiscalDocument $doc): array
    {
        $service = new FiscalDocumentDetailService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalEmitAttemptRepository::class),
            $this->createMock(OutboundEmailLogRepository::class),
            $this->createMock(FiscalWebhookEventRepository::class),
            $this->createMock(FiscalAuditLogRepository::class),
            null
        );
        $ref = new ReflectionMethod(FiscalDocumentDetailService::class, 'serializeDocument');
        $ref->setAccessible(true);

        return $ref->invoke($service, $doc);
    }

    private function serializeViaQueue(FiscalDocument $doc): array
    {
        $service = new FiscalOperationsService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalAuditLogRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalDocumentDetailService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(\App\Service\Fiscal\Observability\FiscalAlertService::class)
        );
        $ref = new ReflectionMethod(FiscalOperationsService::class, 'serializeQueueItem');
        $ref->setAccessible(true);

        return $ref->invoke($service, $doc);
    }

    public function testErrorTypeIsIdenticalAcrossListDetailAndQueue(): void
    {
        $doc = $this->makeDocument();

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        self::assertSame(FiscalDocument::ERROR_TRANSIENT, $list['error_type']);
        self::assertSame($list['error_type'], $detail['error_type']);
        self::assertSame($list['error_type'], $queue['error_type']);
    }

    public function testRetryableIsIdenticalAcrossListDetailAndQueue(): void
    {
        $doc = $this->makeDocument();

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        self::assertTrue($list['retryable']);
        self::assertSame($list['retryable'], $detail['retryable']);
        self::assertSame($list['retryable'], $queue['retryable']);
    }

    public function testRetryCountIsIdenticalAcrossListDetailAndQueue(): void
    {
        $doc = $this->makeDocument();

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        self::assertSame(3, $list['retry_count']);
        self::assertSame($list['retry_count'], $detail['retry_count']);
        self::assertSame($list['retry_count'], $queue['retry_count']);
    }

    /** next_retry_at debe tener el MISMO formato (DATE_ATOM) en las 3 capas. */
    public function testNextRetryAtIsIdenticalFormatAcrossListDetailAndQueue(): void
    {
        $doc = $this->makeDocument();

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        $expected = $doc->getNextRetryAt()->format(DATE_ATOM);
        self::assertSame($expected, $list['next_retry_at']);
        self::assertSame($expected, $detail['next_retry_at']);
        self::assertSame($expected, $queue['next_retry_at']);
    }

    public function testStatusIsIdenticalAcrossListDetailAndQueue(): void
    {
        $doc = $this->makeDocument();

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        self::assertSame(FiscalDocument::STATUS_ERROR, $list['status']);
        self::assertSame($list['status'], $detail['status']);
        self::assertSame($list['status'], $queue['status']);
    }

    /**
     * Regresión directa de H3: antes de Fase 2, `error_type` existía en detail/queue pero
     * estaba AUSENTE en list. Esta prueba falla explícitamente si esa ausencia regresa.
     */
    public function testListNeverOmitsErrorTypeRetryableOrNextRetryAt(): void
    {
        $doc = $this->makeDocument();
        $list = $this->serializeViaList($doc);

        self::assertArrayHasKey('error_type', $list, 'H3 regresó: list ya no manda error_type');
        self::assertArrayHasKey('retryable', $list, 'H3 regresó: list ya no manda retryable');
        self::assertArrayHasKey('next_retry_at', $list, 'H3 regresó: list ya no manda next_retry_at');
    }

    /**
     * "Atendido" (2026-09-22): regresión directa de un bug real encontrado en verificación E2E —
     * serializeDocSummary() (list) se actualizó para mandar `attended`, pero
     * FiscalDocumentDetailService::serializeDocument() (detail, GET /documents/{uuid}) se quedó
     * sin el campo — el modal de detalle del panel central re-consultaba tras marcar "atendido"
     * y seguía mostrando "No atendido" porque el backend nunca lo mandaba ahí. Mismo patrón que
     * H3 con error_type, pero con attended.
     */
    public function testAttendedIsIdenticalAcrossListDetailAndQueue(): void
    {
        $doc = $this->makeDocument();
        $doc->setAttended(true);
        $doc->setAttendedReason('cliente resolvió por WhatsApp');
        $doc->setAttendedBy('admin@tukifac.com');
        $doc->setAttendedAt(new \DateTimeImmutable('2026-09-22T13:00:00+00:00'));

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        self::assertTrue($list['attended']);
        self::assertSame($list['attended'], $detail['attended'], 'detail se quedó sin attended (bug real encontrado en E2E)');
        self::assertSame($list['attended'], $queue['attended']);
        self::assertSame('cliente resolvió por WhatsApp', $detail['attended_reason']);
        self::assertSame('admin@tukifac.com', $detail['attended_by']);
        self::assertSame($doc->getAttendedAt()->format(DATE_ATOM), $detail['attended_at']);
    }

    public function testListNeverOmitsAttendedFields(): void
    {
        $doc = $this->makeDocument();
        $list = $this->serializeViaList($doc);

        self::assertArrayHasKey('attended', $list);
        self::assertArrayHasKey('attended_reason', $list);
        self::assertArrayHasKey('attended_by', $list);
        self::assertArrayHasKey('attended_at', $list);
    }

    /** Regresión directa del bug real: detail (GET /documents/{uuid}) también debe mandar los 4 campos. */
    public function testDetailNeverOmitsAttendedFields(): void
    {
        $doc = $this->makeDocument();
        $detail = $this->serializeViaDetail($doc);

        self::assertArrayHasKey('attended', $detail);
        self::assertArrayHasKey('attended_reason', $detail);
        self::assertArrayHasKey('attended_by', $detail);
        self::assertArrayHasKey('attended_at', $detail);
    }

    /** Documento sin clasificación (legado/histórico): los 3 serializadores deben devolver null, nunca inventar un valor, y no deben romper (sección 10 — documentos históricos sin error_type). */
    public function testUnclassifiedDocumentIsNullEverywhereWithoutCrashing(): void
    {
        $doc = (new FiscalDocument())
            ->setDocumentUuid('uuid-legacy-1')
            ->setTenantId(1)
            ->setTenantSlug('tenant-legacy')
            ->setSaleId(1)
            ->setDocumentType('01')
            ->setSeries('F001')
            ->setNumber('1')
            ->setStatus(FiscalDocument::STATUS_ACCEPTED)
            ->setSnapshotJson('{}');

        $list = $this->serializeViaList($doc);
        $detail = $this->serializeViaDetail($doc);
        $queue = $this->serializeViaQueue($doc);

        self::assertNull($list['error_type']);
        self::assertNull($detail['error_type']);
        self::assertNull($queue['error_type']);
        self::assertNull($list['next_retry_at']);
        self::assertNull($detail['next_retry_at']);
        self::assertNull($queue['next_retry_at']);
    }
}
