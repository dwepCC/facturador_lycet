<?php

declare(strict_types=1);

namespace App\Tests\Controller\v1;

use App\Controller\v1\FiscalController;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalBulkActionService;
use App\Service\Fiscal\FiscalCdrConsultProcessor;
use App\Service\Fiscal\FiscalCompanySyncService;
use App\Service\Fiscal\FiscalConnectionTestService;
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalDocumentPdfResolver;
use App\Service\Fiscal\FiscalDocumentService;
use App\Service\Fiscal\FiscalFileFetcher;
use App\Service\Fiscal\FiscalQueueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pruebas de persistencia y encolado en FiscalController::enqueueAction().
 */
class FiscalControllerEnqueueActionTest extends TestCase
{
    private function makeDocument(string $uuid, string $status): FiscalDocument
    {
        return (new FiscalDocument())
            ->setDocumentUuid($uuid)
            ->setTenantId(1)
            ->setTenantSlug('tenant-test')
            ->setSaleId(100)
            ->setDocumentType('03')
            ->setSeries('B001')
            ->setNumber('00001')
            ->setStatus($status)
            ->setSnapshotJson('{}');
    }

    /**
     * @return array{0: FiscalController, 1: FiscalDocumentRepository, 2: FiscalQueueService, 3: EntityManagerInterface}
     */
    private function buildController(
        ?FiscalDocument $doc,
        bool $queueEnabled = true,
        ?\Throwable $flushException = null
    ): array {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->method('isEnabled')->willReturn($queueEnabled);
        $queue->expects($this->once())
            ->method('push')
            ->with(FiscalQueueService::QUEUE_EMIT, ['document_uuid' => $doc !== null ? $doc->getDocumentUuid() : 'missing']);

        $em = $this->createMock(EntityManagerInterface::class);
        if ($flushException !== null) {
            $em->expects($this->once())->method('flush')->willThrowException($flushException);
        }

        $controller = new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $repo,
            $this->createMock(FiscalDocumentDetailService::class),
            $queue,
            $this->createMock(FiscalFileFetcher::class),
            $this->createMock(FiscalBulkActionService::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalCompanySyncService::class),
            $this->createMock(FiscalConnectionTestService::class),
            $this->createMock(FiscalDocumentPdfResolver::class),
            $this->createMock(FiscalCdrConsultProcessor::class),
            $em
        );

        return [$controller, $repo, $queue, $em];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function invokeEnqueue(FiscalController $controller, string $uuid, string $responseStatus): array
    {
        $method = new \ReflectionMethod(FiscalController::class, 'enqueueAction');
        $method->setAccessible(true);
        /** @var JsonResponse $response */
        $response = $method->invoke($controller, $uuid, FiscalQueueService::QUEUE_EMIT, $responseStatus);
        $decoded = json_decode((string) $response->getContent(), true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }

    public function testPendingDocumentSendQueuesAndFlushes(): void
    {
        $doc = $this->makeDocument('uuid-pending', FiscalDocument::STATUS_PENDING);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->once())->method('flush');

        $result = $this->invokeEnqueue($controller, 'uuid-pending', 'queued');

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('queued', $result['body']['status']);
        $this->assertSame(FiscalDocument::STATUS_QUEUED, $doc->getStatus());
        $this->assertNotNull($doc->getQueuedAt());
    }

    public function testFailedDocumentRetryQueuesWithoutFlush(): void
    {
        $doc = $this->makeDocument('uuid-failed', FiscalDocument::STATUS_ERROR);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->never())->method('flush');

        $result = $this->invokeEnqueue($controller, 'uuid-failed', 'retry_queued');

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('retry_queued', $result['body']['status']);
        $this->assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
    }

    public function testQueuedDocumentForceQueuesAndFlushes(): void
    {
        $doc = $this->makeDocument('uuid-queued', FiscalDocument::STATUS_QUEUED);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->once())->method('flush');

        $result = $this->invokeEnqueue($controller, 'uuid-queued', 'force_queued');

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('force_queued', $result['body']['status']);
        $this->assertSame(FiscalDocument::STATUS_QUEUED, $doc->getStatus());
        $this->assertNotNull($doc->getQueuedAt());
    }

    public function testRetryingDocumentRetryQueuesAndFlushes(): void
    {
        $doc = $this->makeDocument('uuid-retrying', FiscalDocument::STATUS_RETRYING);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->once())->method('flush');

        $result = $this->invokeEnqueue($controller, 'uuid-retrying', 'retry_queued');

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame(FiscalDocument::STATUS_QUEUED, $doc->getStatus());
    }

    public function testAcceptedDocumentSendQueuesWithoutStatusOverwrite(): void
    {
        $doc = $this->makeDocument('uuid-accepted', FiscalDocument::STATUS_ACCEPTED);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->never())->method('flush');

        $result = $this->invokeEnqueue($controller, 'uuid-accepted', 'queued');

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
    }

    public function testDocumentNotFoundReturns404(): void
    {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects($this->never())->method('push');

        $controller = new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $repo,
            $this->createMock(FiscalDocumentDetailService::class),
            $queue,
            $this->createMock(FiscalFileFetcher::class),
            $this->createMock(FiscalBulkActionService::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalCompanySyncService::class),
            $this->createMock(FiscalConnectionTestService::class),
            $this->createMock(FiscalDocumentPdfResolver::class),
            $this->createMock(FiscalCdrConsultProcessor::class),
            $this->createMock(EntityManagerInterface::class)
        );

        $result = $this->invokeEnqueue($controller, 'missing-uuid', 'queued');

        $this->assertSame(Response::HTTP_NOT_FOUND, $result['status']);
        $this->assertSame('no encontrado', $result['body']['error']);
    }

    public function testFlushFailureAfterPushStillReturnsAccepted(): void
    {
        $doc = $this->makeDocument('uuid-flush-fail', FiscalDocument::STATUS_PENDING);
        [$controller] = $this->buildController($doc, true, new \RuntimeException('flush failed'));

        $result = $this->invokeEnqueue($controller, 'uuid-flush-fail', 'queued');

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('queued', $result['body']['status']);
    }

    public function testRepositoryDoesNotExposePublicGetEntityManager(): void
    {
        $repo = new \ReflectionClass(FiscalDocumentRepository::class);
        $this->assertFalse(
            $repo->hasMethod('getEntityManager') && $repo->getMethod('getEntityManager')->isPublic(),
            'getEntityManager no debe ser público en el repositorio; usar EntityManagerInterface inyectado.'
        );
    }
}
