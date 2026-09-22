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
use Symfony\Component\HttpFoundation\Response;

/**
 * Pruebas de persistencia y encolado en FiscalController::enqueueAction() y de los guards
 * de Fase 1 (decisión aprobada 2026-09-19): send/retry NORMALES deben respetar
 * FiscalBulkActionService::isBlockedForNormalAction() (accepted, business, permanent,
 * manual_only); force sigue siendo el override administrativo explícito sin guard.
 */
class FiscalControllerEnqueueActionTest extends TestCase
{
    private function makeDocument(
        string $uuid,
        string $status,
        ?string $errorType = null,
        ?bool $retryable = null,
        ?int $retryCount = null
    ): FiscalDocument {
        $doc = (new FiscalDocument())
            ->setDocumentUuid($uuid)
            ->setTenantId(1)
            ->setTenantSlug('tenant-test')
            ->setSaleId(100)
            ->setDocumentType('03')
            ->setSeries('B001')
            ->setNumber('00001')
            ->setStatus($status)
            ->setSnapshotJson('{}');
        if ($errorType !== null) {
            $doc->setErrorType($errorType);
        }
        if ($retryable !== null) {
            $doc->setRetryable($retryable);
        }
        if ($retryCount !== null) {
            $doc->setRetryCount($retryCount);
        }

        return $doc;
    }

    /**
     * @return array{0: FiscalController, 1: FiscalDocumentRepository, 2: FiscalQueueService, 3: EntityManagerInterface}
     */
    private function buildController(
        ?FiscalDocument $doc,
        bool $queueEnabled = true,
        ?\Throwable $flushException = null,
        bool $expectPush = true,
        string $expectedQueue = FiscalQueueService::QUEUE_EMIT
    ): array {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->method('isEnabled')->willReturn($queueEnabled);
        if ($expectPush) {
            $queue->expects($this->once())
                ->method('push')
                ->with($expectedQueue, ['document_uuid' => $doc !== null ? $doc->getDocumentUuid() : 'missing']);
        } else {
            $queue->expects($this->never())->method('push');
        }

        $em = $this->createMock(EntityManagerInterface::class);
        if ($flushException !== null) {
            $em->expects($this->once())->method('flush')->willThrowException($flushException);
        }

        // Instancia REAL (no mock): isBlockedForNormalAction() es pura sobre $doc, no toca
        // sus dependencias — así los tests ejercitan la regla real, no un doble hueco.
        $bulkService = new FiscalBulkActionService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalQueueService::class),
            new FiscalCustomerEmailNormalizer()
        );

        $controller = new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $repo,
            $this->createMock(FiscalDocumentDetailService::class),
            $queue,
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

        return [$controller, $repo, $queue, $em];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function invokeEnqueue(FiscalController $controller, string $uuid, string $responseStatus, bool $enforceGuard = false): array
    {
        $method = new \ReflectionMethod(FiscalController::class, 'enqueueAction');
        $method->setAccessible(true);
        /** @var JsonResponse $response */
        $response = $method->invoke($controller, $uuid, FiscalQueueService::QUEUE_EMIT, $responseStatus, $enforceGuard);

        return $this->decode($response);
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
    // Comportamiento genérico de enqueueAction (sin relación con el guard)
    // ------------------------------------------------------------------

    public function testPendingDocumentSendQueuesAndFlushes(): void
    {
        $doc = $this->makeDocument('uuid-pending', FiscalDocument::STATUS_PENDING);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->once())->method('flush');

        $result = $this->decode($controller->sendManual('uuid-pending'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('queued', $result['body']['status']);
        $this->assertSame(FiscalDocument::STATUS_QUEUED, $doc->getStatus());
        $this->assertNotNull($doc->getQueuedAt());
    }

    public function testFailedDocumentRetryQueuesWithoutFlush(): void
    {
        // status=error sin error_type (legado/desconocido) no debe bloquearse: solo
        // business/permanent/manual_only bloquean, no "cualquier error".
        $doc = $this->makeDocument('uuid-failed', FiscalDocument::STATUS_ERROR);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->never())->method('flush');

        $result = $this->decode($controller->retry('uuid-failed'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('retry_queued', $result['body']['status']);
        $this->assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
    }

    public function testQueuedDocumentForceQueuesAndFlushes(): void
    {
        $doc = $this->makeDocument('uuid-queued', FiscalDocument::STATUS_QUEUED);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->once())->method('flush');

        $result = $this->decode($controller->forceSend('uuid-queued'));

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

        $result = $this->decode($controller->retry('uuid-retrying'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame(FiscalDocument::STATUS_QUEUED, $doc->getStatus());
    }

    public function testDocumentNotFoundReturns404(): void
    {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn(null);

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects($this->never())->method('push');

        $bulkService = new FiscalBulkActionService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalQueueService::class),
            new FiscalCustomerEmailNormalizer()
        );

        $controller = new FiscalController(
            $this->createMock(FiscalDocumentService::class),
            $repo,
            $this->createMock(FiscalDocumentDetailService::class),
            $queue,
            $this->createMock(FiscalFileFetcher::class),
            $bulkService,
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalCompanySyncService::class),
            $this->createMock(FiscalConnectionTestService::class),
            $this->createMock(FiscalDocumentPdfResolver::class),
            $this->createMock(FiscalCdrConsultProcessor::class),
            $this->createMock(FiscalCdrRecoveryService::class),
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

        $result = $this->decode($controller->sendManual('uuid-flush-fail'));

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

    // ------------------------------------------------------------------
    // Fase 1 — guards de send/retry normales (decisión aprobada 2026-09-19)
    // ------------------------------------------------------------------

    /** Test G: status=accepted, send individual → NO ENCOLAR (409). */
    public function testAcceptedDocumentSendIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-accepted', FiscalDocument::STATUS_ACCEPTED);
        [$controller, , , $em] = $this->buildController($doc, true, null, false);
        $em->expects($this->never())->method('flush');

        $result = $this->decode($controller->sendManual('uuid-accepted'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
        $this->assertSame(FiscalDocument::STATUS_ACCEPTED, $result['body']['status']);
        $this->assertSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
    }

    public function testAcceptedDocumentRetryIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-accepted-2', FiscalDocument::STATUS_ACCEPTED);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-accepted-2'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    /** Test H: status=accepted, force individual → ENCOLAR (force sigue siendo override). */
    public function testAcceptedDocumentForceStillQueues(): void
    {
        $doc = $this->makeDocument('uuid-accepted-3', FiscalDocument::STATUS_ACCEPTED);
        [$controller] = $this->buildController($doc, true, null, true);

        $result = $this->decode($controller->forceSend('uuid-accepted-3'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
        $this->assertSame('force_queued', $result['body']['status']);
    }

    /** Test E: status=error, error_type=business, retry individual → NO ENCOLAR. */
    public function testBusinessErrorTypeRetryIsBlockedRegardlessOfStatus(): void
    {
        // Deliberadamente con status=ERROR (no el REJECTED real de negocio) para probar que
        // el guard depende de error_type, no de status — ver testBusinessRejectedRetryIsBlocked
        // para el caso real (status=rejected).
        $doc = $this->makeDocument('uuid-business-1', FiscalDocument::STATUS_ERROR, FiscalDocument::ERROR_BUSINESS);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-business-1'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
        $this->assertSame(FiscalDocument::ERROR_BUSINESS, $result['body']['error_type']);
    }

    /**
     * El caso REAL de producción: business siempre queda en status=REJECTED (nunca ERROR) —
     * antes de esta fase, el guard viejo (acoplado a status=ERROR) no cubría esto (bug H1).
     */
    public function testBusinessRejectedRetryIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-business-2', FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-business-2'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    public function testBusinessRejectedSendIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-business-3', FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->sendManual('uuid-business-3'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    /** Test I: business + force individual → ENCOLAR (override explícito, ningún guard nuevo lo toca). */
    public function testBusinessRejectedForceStillQueues(): void
    {
        $doc = $this->makeDocument('uuid-business-4', FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);
        [$controller] = $this->buildController($doc, true, null, true);

        $result = $this->decode($controller->forceSend('uuid-business-4'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
    }

    /** Test F: status=error, error_type=manual_only, retry individual → NO ENCOLAR. */
    public function testManualOnlyRetryIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-manual-1', FiscalDocument::STATUS_ERROR, 'manual_only');
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-manual-1'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
        $this->assertSame('manual_only', $result['body']['error_type']);
    }

    public function testManualOnlySendIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-manual-2', FiscalDocument::STATUS_ERROR, 'manual_only');
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->sendManual('uuid-manual-2'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    public function testPermanentErrorTypeRetryIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-permanent-1', FiscalDocument::STATUS_ERROR, FiscalDocument::ERROR_PERMANENT);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-permanent-1'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    /** Fase 4 (3.2): permanent también debe bloquear `send`, no solo `retry`. */
    public function testPermanentErrorTypeSendIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-permanent-2', FiscalDocument::STATUS_ERROR, FiscalDocument::ERROR_PERMANENT);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->sendManual('uuid-permanent-2'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    /**
     * Fase 4 (3.2 de la auditoría): el cuerpo del 409 debe conservar EXACTAMENTE las 5 claves
     * documentadas (error, status, error_type, retryable, hint) — ningún contrato paralelo.
     */
    public function testBlockedActionResponseBodyHasExactContractShape(): void
    {
        $doc = $this->makeDocument('uuid-shape-1', FiscalDocument::STATUS_ERROR, 'manual_only', false, 3);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-shape-1'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
        $this->assertEqualsCanonicalizing(
            ['error', 'status', 'error_type', 'retryable', 'hint'],
            array_keys($result['body'])
        );
        $this->assertSame(FiscalDocument::STATUS_ERROR, $result['body']['status']);
        $this->assertSame('manual_only', $result['body']['error_type']);
        $this->assertFalse($result['body']['retryable']);
        $this->assertNotSame('', trim((string) $result['body']['hint']));
    }

    /**
     * Fase 4 (1. reglas importantes): poll/email nunca estuvieron guardados y deben seguir sin
     * estarlo — ni accepted ni business deben bloquear estas dos acciones (regresión de
     * "no cambiar la semántica de poll/consult/email salvo necesidad estrictamente probada").
     */
    public function testPollAndEmailStillWorkOnAcceptedAndBusinessDocuments(): void
    {
        // pollTicket() ahora exige un ticket SUNAT real (ver guard agregado: sin ticket, /poll
        // era un no-op silencioso — FiscalStatusPollProcessor se sale sin hacer nada ni avisar).
        // Con ticket presente, accepted/business siguen sin bloquear poll — la regla de esta
        // prueba (Fase 4) sigue vigente, solo se agregó la precondición del ticket.
        $acceptedForPoll = $this->makeDocument('uuid-poll-accepted', FiscalDocument::STATUS_ACCEPTED);
        $acceptedForPoll->setTicket('ticket-123');
        [$pollController] = $this->buildController($acceptedForPoll, true, null, true, FiscalQueueService::QUEUE_STATUS_POLL);
        $pollResult = $this->decode($pollController->pollTicket('uuid-poll-accepted'));
        $this->assertSame(Response::HTTP_ACCEPTED, $pollResult['status']);

        $businessForEmail = $this->makeDocument('uuid-email-business', FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);
        [$emailController] = $this->buildController($businessForEmail, true, null, true, FiscalQueueService::QUEUE_EMAIL);
        $emailResult = $this->decode($emailController->resendEmail('uuid-email-business'));
        $this->assertSame(Response::HTTP_ACCEPTED, $emailResult['status']);
    }

    /**
     * Guard nuevo (dashboard: "Consultar ticket" no hacía nada en silencio para documentos sin
     * ticket real — ej. guías vía PSE con `sentPendingCdr`, ticket=null a propósito). Ahora
     * pollTicket() corta antes de encolar y explica qué botón usar en su lugar.
     */
    public function testPollWithoutTicketIsRejectedWithHelpfulMessage(): void
    {
        $doc = $this->makeDocument('uuid-poll-no-ticket', FiscalDocument::STATUS_SENT);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->pollTicket('uuid-poll-no-ticket'));

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $result['status']);
        $this->assertStringContainsString('Consultar CDR', $result['body']['error']);
    }

    /** Test J: transient, retryable=true, retry_count<5, retry individual → ENCOLAR. */
    public function testTransientWithinBudgetRetryStillQueues(): void
    {
        $doc = $this->makeDocument(
            'uuid-transient-1',
            FiscalDocument::STATUS_ERROR,
            FiscalDocument::ERROR_TRANSIENT,
            true,
            2
        );
        [$controller] = $this->buildController($doc, true, null, true);

        $result = $this->decode($controller->retry('uuid-transient-1'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
    }

    /**
     * Test K: transient agotado (retry_count=5, retryable=false) — reintento MANUAL explícito
     * → ENCOLAR (decisión aprobada: el límite de 5 solo bloquea el camino AUTOMÁTICO — ver
     * FiscalBulkActionServiceShouldSkipTest::testRetryDoesNotSkipExhaustedTransient, misma
     * regla, no se duplica aquí, solo se confirma que el guard nuevo de acciones individuales
     * tampoco la contradice).
     */
    public function testExhaustedTransientManualRetryStillQueues(): void
    {
        $doc = $this->makeDocument(
            'uuid-transient-exhausted',
            FiscalDocument::STATUS_ERROR,
            FiscalDocument::ERROR_TRANSIENT,
            false,
            5
        );
        [$controller] = $this->buildController($doc, true, null, true);

        $result = $this->decode($controller->retry('uuid-transient-exhausted'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
    }

    // ------------------------------------------------------------------
    // "Atendido" (2026-09-22): bloquea toda acción individual, INCLUIDO force —
    // se evalúa antes que enforceGuard, así que ni siquiera un documento por lo
    // demás desbloqueado (ej. transient con presupuesto) debe poder encolarse.
    // ------------------------------------------------------------------

    public function testAttendedDocumentRetryIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-attended-1', FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);
        $doc->setAttended(true);
        $doc->setAttendedReason('cliente resolvió por otra vía');
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->retry('uuid-attended-1'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
        $this->assertSame('cliente resolvió por otra vía', $result['body']['attended_reason']);
    }

    public function testAttendedDocumentSendIsBlocked(): void
    {
        $doc = $this->makeDocument('uuid-attended-2', FiscalDocument::STATUS_ERROR);
        $doc->setAttended(true);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->sendManual('uuid-attended-2'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    /** A diferencia de todos los demás guards de esta clase, "atendido" SÍ bloquea force. */
    public function testAttendedDocumentForceIsBlockedUnlikeEveryOtherGuard(): void
    {
        $doc = $this->makeDocument('uuid-attended-3', FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);
        $doc->setAttended(true);
        [$controller] = $this->buildController($doc, true, null, false);

        $result = $this->decode($controller->forceSend('uuid-attended-3'));

        $this->assertSame(Response::HTTP_CONFLICT, $result['status']);
    }

    public function testNotAttendedDocumentIsUnaffectedByAttendedGuard(): void
    {
        // Regresión: un documento con attended=false (default) debe comportarse exactamente
        // como antes de agregar el campo.
        $doc = $this->makeDocument('uuid-not-attended', FiscalDocument::STATUS_PENDING);
        [$controller, , , $em] = $this->buildController($doc);
        $em->expects($this->once())->method('flush');

        $result = $this->decode($controller->sendManual('uuid-not-attended'));

        $this->assertSame(Response::HTTP_ACCEPTED, $result['status']);
    }
}
