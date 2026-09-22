<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalBulkActionService;
use App\Service\Fiscal\FiscalCustomerEmailNormalizer;
use App\Service\Fiscal\FiscalQueueService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fase 4 (14.1.1 del plan): la acción masiva "Reintentar/Reenviar todos" del dashboard no debe
 * ignorar la clasificación de un documento agotado. 'force' sigue sin filtro (override
 * explícito). 'transient' NO se omite aunque esté agotado — reintentar manualmente un
 * transitorio tras una caída pasajera de SUNAT/PSE es exactamente el caso de uso del botón.
 *
 * Fase 1 del Panel Central Fiscal (decisión aprobada 2026-09-19, corrige H1 de la auditoría
 * AUDITORIA-PANEL-CENTRAL-FISCAL-FASE0-PLAN.md): el guard de business/permanent/manual_only
 * ahora se basa en `error_type`, NO en `status` — antes solo se evaluaba con status=ERROR, y
 * un documento `business` (que SIEMPRE queda en status=REJECTED, nunca ERROR) se colaba sin
 * protección. Ver FiscalBulkActionService::isBlockedForNormalAction().
 */
class FiscalBulkActionServiceShouldSkipTest extends TestCase
{
    private function shouldSkip(FiscalDocument $doc, string $action): bool
    {
        $service = new FiscalBulkActionService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalQueueService::class),
            new FiscalCustomerEmailNormalizer() // final, sin dependencias — no se ejercita en estas pruebas
        );
        $ref = new ReflectionMethod(FiscalBulkActionService::class, 'shouldSkip');
        $ref->setAccessible(true);

        return $ref->invoke($service, $doc, $action);
    }

    private function exhaustedDoc(string $errorType): FiscalDocument
    {
        $doc = new FiscalDocument();
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setErrorType($errorType);
        $doc->setRetryable(false);

        return $doc;
    }

    private function docWithStatusAndErrorType(string $status, string $errorType): FiscalDocument
    {
        $doc = new FiscalDocument();
        $doc->setStatus($status);
        $doc->setErrorType($errorType);
        $doc->setRetryable(false);

        return $doc;
    }

    /** @dataProvider nonRetryableBuckets */
    public function testRetryAndSendSkipNonRetryableBuckets(string $errorType): void
    {
        $doc = $this->exhaustedDoc($errorType);

        self::assertTrue($this->shouldSkip($doc, 'retry'), "retry debe omitir errorType={$errorType}");
        self::assertTrue($this->shouldSkip($doc, 'send'), "send debe omitir errorType={$errorType}");
    }

    public function nonRetryableBuckets(): array
    {
        return [
            'rechazo de negocio real' => [FiscalDocument::ERROR_BUSINESS],
            'requiere acción manual (0111, etc.)' => ['manual_only'],
            'permanente (certificado/config)' => [FiscalDocument::ERROR_PERMANENT],
        ];
    }

    /**
     * Test A/B: el caso REAL de producción — business siempre queda en status=REJECTED, nunca
     * ERROR. Antes de la Fase 1 (H1), esto NO se omitía porque el guard viejo solo miraba
     * status=ERROR. Cubre el flujo real: filtrar "Rechazado" en /fiscal y pulsar Bulk retry/send.
     */
    public function testRetrySkipsRejectedBusinessDocument(): void
    {
        $doc = $this->docWithStatusAndErrorType(FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);

        self::assertTrue($this->shouldSkip($doc, 'retry'));
    }

    public function testSendSkipsRejectedBusinessDocument(): void
    {
        $doc = $this->docWithStatusAndErrorType(FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_BUSINESS);

        self::assertTrue($this->shouldSkip($doc, 'send'));
    }

    /**
     * El guard debe ser puramente por error_type — status arbitrario (ni ERROR ni REJECTED)
     * con error_type=manual_only también debe omitirse, para dejar constancia de que ya no
     * hay ningún acoplamiento oculto a un status específico.
     */
    public function testRetrySkipsManualOnlyRegardlessOfArbitraryStatus(): void
    {
        $doc = $this->docWithStatusAndErrorType(FiscalDocument::STATUS_SENT, 'manual_only');

        self::assertTrue($this->shouldSkip($doc, 'retry'));
    }

    public function testRetryDoesNotSkipExhaustedTransient(): void
    {
        // Un transitorio agotado (retryable=false tras la Fase 4) SÍ debe quedar disponible
        // para reenvío masivo manual — es distinto de "condenado a fallar".
        $doc = $this->exhaustedDoc(FiscalDocument::ERROR_TRANSIENT);

        self::assertFalse($this->shouldSkip($doc, 'retry'));
        self::assertFalse($this->shouldSkip($doc, 'send'));
    }

    /** @dataProvider nonRetryableBuckets */
    public function testForceNeverSkipsRegardlessOfClassification(string $errorType): void
    {
        $doc = $this->exhaustedDoc($errorType);

        self::assertFalse($this->shouldSkip($doc, 'force'), 'force es el override explícito, nunca se omite');
    }

    /**
     * Fase 4 (3.3 de la auditoría): force debe seguir funcionando incluso sobre un documento
     * YA ACEPTADO en bulk — a diferencia de send/retry, que sí se saltan accepted
     * (testAcceptedStillSkippedForRetryAndSendAsBefore). force es override administrativo
     * total, sin ninguna excepción, ni siquiera para el caso más sensible.
     */
    public function testForceNeverSkipsAcceptedDocument(): void
    {
        $doc = new FiscalDocument();
        $doc->setStatus(FiscalDocument::STATUS_ACCEPTED);

        self::assertFalse($this->shouldSkip($doc, 'force'));
    }

    public function testAcceptedStillSkippedForRetryAndSendAsBefore(): void
    {
        // Regresión: comportamiento previo a la Fase 4, no debe cambiar.
        $doc = new FiscalDocument();
        $doc->setStatus(FiscalDocument::STATUS_ACCEPTED);

        self::assertTrue($this->shouldSkip($doc, 'retry'));
        self::assertTrue($this->shouldSkip($doc, 'send'));
    }

    public function testConsultStillOnlySkipsAcceptedWithCdrAsBefore(): void
    {
        // Regresión: 'consult' no debe verse afectado por el nuevo filtro de errorType.
        $doc = $this->exhaustedDoc(FiscalDocument::ERROR_BUSINESS);

        self::assertFalse($this->shouldSkip($doc, 'consult'), "consult sigue siendo útil incluso en rechazados");
    }

    // ------------------------------------------------------------------
    // isBlockedForNormalAction() directo — fuente de verdad compartida con
    // FiscalController::enqueueAction() (acciones individuales, Fase 1).
    // ------------------------------------------------------------------

    private function service(): FiscalBulkActionService
    {
        return new FiscalBulkActionService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalQueueService::class),
            new FiscalCustomerEmailNormalizer()
        );
    }

    /** @dataProvider nonRetryableBuckets */
    public function testIsBlockedForNormalActionTrueForTerminalBucketsRegardlessOfStatus(string $errorType): void
    {
        $rejected = $this->docWithStatusAndErrorType(FiscalDocument::STATUS_REJECTED, $errorType);
        $error = $this->docWithStatusAndErrorType(FiscalDocument::STATUS_ERROR, $errorType);

        self::assertTrue($this->service()->isBlockedForNormalAction($rejected));
        self::assertTrue($this->service()->isBlockedForNormalAction($error));
    }

    public function testIsBlockedForNormalActionTrueForAccepted(): void
    {
        $doc = new FiscalDocument();
        $doc->setStatus(FiscalDocument::STATUS_ACCEPTED);

        self::assertTrue($this->service()->isBlockedForNormalAction($doc));
    }

    public function testIsBlockedForNormalActionFalseForTransientEvenExhausted(): void
    {
        $doc = $this->exhaustedDoc(FiscalDocument::ERROR_TRANSIENT);

        self::assertFalse($this->service()->isBlockedForNormalAction($doc));
    }

    public function testIsBlockedForNormalActionFalseForNullErrorType(): void
    {
        $doc = new FiscalDocument();
        $doc->setStatus(FiscalDocument::STATUS_PENDING);

        self::assertFalse($this->service()->isBlockedForNormalAction($doc));
    }

    // ------------------------------------------------------------------
    // "Atendido" (2026-09-22): decisión humana explícita, bloquea TODO en bulk,
    // incluido 'force' — a diferencia de los guards por error_type de arriba.
    // ------------------------------------------------------------------

    /** @dataProvider allBulkActions */
    public function testAttendedSkipsEveryActionIncludingForce(string $action): void
    {
        $doc = $this->docWithStatusAndErrorType(FiscalDocument::STATUS_REJECTED, FiscalDocument::ERROR_TRANSIENT);
        $doc->setAttended(true);

        self::assertTrue($this->shouldSkip($doc, $action), "attended debe omitir incluso action={$action}");
    }

    public function allBulkActions(): array
    {
        return [
            'send' => ['send'],
            'retry' => ['retry'],
            'force' => ['force'],
            'consult' => ['consult'],
            'email' => ['email'],
        ];
    }

    public function testNotAttendedIsUnaffectedByAttendedGuard(): void
    {
        // Regresión: un transitorio agotado no-atendido debe seguir disponible para
        // retry/send manual, tal como antes de agregar el campo attended.
        $doc = $this->exhaustedDoc(FiscalDocument::ERROR_TRANSIENT);

        self::assertFalse($this->shouldSkip($doc, 'retry'));
        self::assertFalse($this->shouldSkip($doc, 'force'));
    }
}
