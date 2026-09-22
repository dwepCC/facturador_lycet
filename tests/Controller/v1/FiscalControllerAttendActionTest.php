<?php

declare(strict_types=1);

namespace App\Tests\Controller\v1;

use App\Controller\v1\FiscalController;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalBulkActionService;
use App\Service\Fiscal\FiscalCdrConsultProcessor;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalCompanySyncService;
use App\Service\Fiscal\FiscalConnectionTestService;
use App\Service\Fiscal\FiscalCustomerEmailNormalizer;
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalDocumentPdfResolver;
use App\Service\Fiscal\FiscalDocumentService;
use App\Service\Fiscal\FiscalFileFetcher;
use App\Service\Fiscal\FiscalQueueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pruebas de FiscalController::attend()/unattend() (2026-09-22) — decisión administrativa
 * independiente del status técnico SUNAT/PSE (ver comentario en FiscalDocument::$attended).
 * Solo se puede marcar atendido un documento en un status terminal que requiere decisión
 * (self::ATTENDABLE_STATUSES); unattend() no tiene esa restricción, siempre revierte.
 */
class FiscalControllerAttendActionTest extends TestCase
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
     * @return array{0: FiscalController, 1: EntityManagerInterface}
     */
    private function buildController(?FiscalDocument $doc, bool $expectFlush = true): array
    {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        $em = $this->createMock(EntityManagerInterface::class);
        if ($expectFlush) {
            $em->expects($this->once())->method('flush');
        } else {
            $em->expects($this->never())->method('flush');
        }

        $bulkService = new FiscalBulkActionService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalQueueService::class),
            new FiscalCustomerEmailNormalizer()
        );

        $controller = new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $repo,
            $this->createMock(FiscalDocumentDetailService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(FiscalFileFetcher::class),
            $bulkService,
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalCompanySyncService::class),
            $this->createMock(FiscalConnectionTestService::class),
            $this->createMock(FiscalDocumentPdfResolver::class),
            $this->createMock(FiscalCdrConsultProcessor::class),
            $this->createMock(FiscalCdrRecoveryService::class),
            $em
        );

        return [$controller, $em];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function decode(JsonResponse $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }

    // ------------------------------------------------------------------
    // attend()
    // ------------------------------------------------------------------

    /** @dataProvider attendableStatuses */
    public function testAttendSucceedsOnEachAttendableStatus(string $status): void
    {
        $doc = $this->makeDocument('uuid-attend-ok', $status);
        [$controller] = $this->buildController($doc);

        $result = $this->decode($controller->attend('uuid-attend-ok', new Request([], [], [], [], [], [], '{}')));

        $this->assertSame(Response::HTTP_OK, $result['status']);
        $this->assertTrue($result['body']['attended']);
        $this->assertTrue($doc->isAttended());
        $this->assertNotNull($doc->getAttendedAt());
    }

    public function attendableStatuses(): array
    {
        return [
            'error' => [FiscalDocument::STATUS_ERROR],
            'rejected' => [FiscalDocument::STATUS_REJECTED],
            'observed' => [FiscalDocument::STATUS_OBSERVED],
            'cancelled' => [FiscalDocument::STATUS_CANCELLED],
        ];
    }

    public function testAttendPersistsReasonAndAttendedBy(): void
    {
        $doc = $this->makeDocument('uuid-attend-reason', FiscalDocument::STATUS_REJECTED);
        [$controller] = $this->buildController($doc);

        $body = json_encode(['reason' => 'cliente resolvió por WhatsApp', 'attended_by' => 'admin@tukifac.com']);
        $controller->attend('uuid-attend-reason', new Request([], [], [], [], [], [], (string) $body));

        $this->assertSame('cliente resolvió por WhatsApp', $doc->getAttendedReason());
        $this->assertSame('admin@tukifac.com', $doc->getAttendedBy());
    }

    public function testAttendWithoutReasonLeavesReasonNull(): void
    {
        // El reason es opcional (a diferencia de skip-tenant-sync) — un admin debe poder marcar
        // atendido sin escribir nada, sin que quede una cadena vacía persistida.
        $doc = $this->makeDocument('uuid-attend-no-reason', FiscalDocument::STATUS_ERROR);
        [$controller] = $this->buildController($doc);

        $controller->attend('uuid-attend-no-reason', new Request([], [], [], [], [], [], '{}'));

        $this->assertNull($doc->getAttendedReason());
        $this->assertNull($doc->getAttendedBy());
    }

    /** @dataProvider nonAttendableStatuses */
    public function testAttendRejectsNonTerminalStatus(string $status): void
    {
        $doc = $this->makeDocument('uuid-attend-blocked', $status);
        [$controller] = $this->buildController($doc, false);

        $result = $this->decode($controller->attend('uuid-attend-blocked', new Request([], [], [], [], [], [], '{}')));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
        $this->assertFalse($doc->isAttended());
    }

    public function nonAttendableStatuses(): array
    {
        return [
            'pending' => [FiscalDocument::STATUS_PENDING],
            'queued' => [FiscalDocument::STATUS_QUEUED],
            'sending' => [FiscalDocument::STATUS_SENDING],
            'sent' => [FiscalDocument::STATUS_SENT],
            'accepted' => [FiscalDocument::STATUS_ACCEPTED],
            'retrying' => [FiscalDocument::STATUS_RETRYING],
        ];
    }

    public function testAttendDocumentNotFoundReturns404(): void
    {
        [$controller] = $this->buildController(null, false);

        $result = $this->decode($controller->attend('missing-uuid', new Request([], [], [], [], [], [], '{}')));

        $this->assertSame(Response::HTTP_NOT_FOUND, $result['status']);
    }

    // ------------------------------------------------------------------
    // unattend()
    // ------------------------------------------------------------------

    public function testUnattendClearsAllAttendedFieldsAndHasNoStatusRestriction(): void
    {
        // A diferencia de attend(), unattend() no exige ningún status particular: siempre debe
        // poder revertirse, incluso si el documento técnico avanzó de estado mientras tanto.
        $doc = $this->makeDocument('uuid-unattend-ok', FiscalDocument::STATUS_ACCEPTED);
        $doc->setAttended(true);
        $doc->setAttendedReason('algo');
        $doc->setAttendedBy('admin@tukifac.com');
        $doc->setAttendedAt(new \DateTimeImmutable());
        [$controller] = $this->buildController($doc);

        $result = $this->decode($controller->unattend('uuid-unattend-ok'));

        $this->assertSame(Response::HTTP_OK, $result['status']);
        $this->assertFalse($result['body']['attended']);
        $this->assertFalse($doc->isAttended());
        $this->assertNull($doc->getAttendedReason());
        $this->assertNull($doc->getAttendedBy());
        $this->assertNull($doc->getAttendedAt());
    }

    public function testUnattendDocumentNotFoundReturns404(): void
    {
        [$controller] = $this->buildController(null, false);

        $result = $this->decode($controller->unattend('missing-uuid'));

        $this->assertSame(Response::HTTP_NOT_FOUND, $result['status']);
    }
}
