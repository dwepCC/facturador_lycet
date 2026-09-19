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
}
