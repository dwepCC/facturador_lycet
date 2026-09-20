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
use App\Service\Fiscal\FiscalDocumentDetailService;
use App\Service\Fiscal\FiscalDocumentPdfResolver;
use App\Service\Fiscal\FiscalDocumentService;
use App\Service\Fiscal\FiscalFileFetcher;
use App\Service\Fiscal\FiscalQueueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fase 2 del Panel Central Fiscal (corrige H3 de la auditoría
 * AUDITORIA-PANEL-CENTRAL-FISCAL-FASE0-PLAN.md): el listado (`GET /documents`, usado por
 * frontend_central/FiscalDocumentsPage.tsx) no enviaba `error_type`/`retryable`/`next_retry_at`
 * aunque el detalle y la cola de operaciones sí — rompía silenciosamente el badge de estado en
 * la tabla principal. Estos tests prueban que `serializeDocSummary()` ahora expone esos 3
 * campos tal cual están guardados en `FiscalDocument`, sin inventar ni reclasificar por texto,
 * y sin dejar de mandar ningún campo que ya existía (retry_count incluido).
 */
class FiscalControllerSerializeDocSummaryTest extends TestCase
{
    private function makeDocument(
        ?string $errorType = null,
        ?bool $retryable = null,
        ?int $retryCount = null,
        ?\DateTimeImmutable $nextRetryAt = null,
        ?string $sunatMessage = null
    ): FiscalDocument {
        $doc = (new FiscalDocument())
            ->setDocumentUuid('uuid-summary-1')
            ->setTenantId(1)
            ->setTenantSlug('tenant-test')
            ->setSaleId(100)
            ->setDocumentType('03')
            ->setSeries('B001')
            ->setNumber('00001')
            ->setStatus(FiscalDocument::STATUS_ERROR)
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
        if ($nextRetryAt !== null) {
            $doc->setNextRetryAt($nextRetryAt);
        }
        if ($sunatMessage !== null) {
            $doc->setSunatMessage($sunatMessage);
        }

        return $doc;
    }

    private function controller(): FiscalController
    {
        return new FiscalController(
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
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FiscalDocument $doc): array
    {
        $ref = new ReflectionMethod(FiscalController::class, 'serializeDocSummary');
        $ref->setAccessible(true);

        return $ref->invoke($this->controller(), $doc, null);
    }

    /** Caso 1: transient — ambos campos presentes y correctos. */
    public function testTransientDocumentExposesErrorTypeAndRetryable(): void
    {
        $doc = $this->makeDocument(FiscalDocument::ERROR_TRANSIENT, true, 2);
        $result = $this->serialize($doc);

        self::assertArrayHasKey('error_type', $result);
        self::assertArrayHasKey('retryable', $result);
        self::assertSame(FiscalDocument::ERROR_TRANSIENT, $result['error_type']);
        self::assertTrue($result['retryable']);
    }

    /**
     * Caso 2: manual_only — conserva exactamente los valores guardados, y NO los recalcula
     * a partir del texto de sunat_message (que a propósito contiene una frase típica de
     * rechazo de negocio, para probar que el serializador no reclasifica por texto).
     */
    public function testManualOnlyDocumentPreservesExactStoredValues(): void
    {
        $doc = $this->makeDocument(
            'manual_only',
            false,
            1,
            null,
            'El comprobante ya esta informado y se encuentra con estado anulado o rechazado'
        );
        $result = $this->serialize($doc);

        self::assertSame('manual_only', $result['error_type']);
        self::assertFalse($result['retryable']);
    }

    /** Caso 3: business — conserva exactamente los valores guardados. */
    public function testBusinessDocumentPreservesExactStoredValues(): void
    {
        $doc = $this->makeDocument(FiscalDocument::ERROR_BUSINESS, false, 1);
        $result = $this->serialize($doc);

        self::assertSame(FiscalDocument::ERROR_BUSINESS, $result['error_type']);
        self::assertFalse($result['retryable']);
    }

    /** Caso 4: sin clasificación — debe llegar `null`, nunca un valor inventado. */
    public function testUnclassifiedDocumentKeepsErrorTypeNull(): void
    {
        $doc = $this->makeDocument();
        $result = $this->serialize($doc);

        self::assertArrayHasKey('error_type', $result);
        self::assertNull($result['error_type']);
        self::assertTrue($result['retryable'], 'retryable por defecto en la entidad es true (FiscalDocument::$retryable)');
    }

    /**
     * Caso 5: next_retry_at — mismo formato (DATE_ATOM) que usan
     * FiscalDocumentDetailService::serializeDocument() y FiscalOperationsService::serializeQueueItem(),
     * y `null` cuando no hay reintento programado.
     */
    public function testNextRetryAtUsesSameFormatAsDetailAndOperationsEndpoints(): void
    {
        $when = new \DateTimeImmutable('2026-09-20T00:16:01+00:00');
        $doc = $this->makeDocument(FiscalDocument::ERROR_TRANSIENT, true, 2, $when);
        $result = $this->serialize($doc);

        self::assertSame($when->format(DATE_ATOM), $result['next_retry_at']);

        $docNoRetry = $this->makeDocument(FiscalDocument::ERROR_BUSINESS, false, 1);
        $resultNoRetry = $this->serialize($docNoRetry);
        self::assertNull($resultNoRetry['next_retry_at']);
    }

    /** Caso 6: retry_count sigue mandándose igual que antes — el cambio es aditivo. */
    public function testRetryCountUnchangedAndExistingFieldsStillPresent(): void
    {
        $doc = $this->makeDocument(FiscalDocument::ERROR_TRANSIENT, true, 3);
        $result = $this->serialize($doc);

        self::assertSame(3, $result['retry_count']);
        // Campos preexistentes que no debían tocarse (regresión de H3):
        foreach ([
            'document_uuid', 'tenant_id', 'tenant_slug', 'sale_id', 'document_type',
            'series', 'number', 'status', 'send_mode', 'provider', 'sunat_mode',
            'original_sunat_mode', 'reissue_count', 'sunat_code', 'sunat_message', 'has_cdr',
            'tenant_sync_state', 'customer_name', 'company_ruc', 'company_name',
            'company_environment', 'total', 'customer_email', 'email_status', 'created_at',
            'accepted_at',
        ] as $key) {
            self::assertArrayHasKey($key, $result, "campo preexistente '{$key}' no debe desaparecer");
        }
    }
}
