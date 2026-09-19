<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalEmitProcessor;
use App\Service\Fiscal\FiscalPdfService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\Fiscal\Provider\FiscalProviderResolver;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fase 4 (sección 14.1 del plan): 5 intentos TOTALES de envío, incluyendo el inicial. Al
 * agotarse, ningún camino automático debe volver a programar un reintento.
 *
 * Prueba applyFailure() directamente (vía Reflection) simulando 5 fallas transitorias
 * consecutivas sobre el MISMO documento — es la función real que decide, en cada intento, si
 * se programa el siguiente o se termina. No se levanta un worker real: eso se prueba mejor así,
 * sin depender de Redis/Symfony Messenger.
 */
class FiscalEmitProcessorRetryLimitTest extends TestCase
{
    public function testFiveTotalAttemptsThenNoMoreAutomaticScheduling(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('44444444-4444-4444-4444-444444444444');
        $empresa = (new Empresa())->setRetryEnabled(true);

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects(self::exactly(4)) // se programa tras los intentos 1, 2, 3 y 4 — nunca tras el 5
            ->method('scheduleRetry');

        $processor = new FiscalEmitProcessor(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $queue,
            $this->createMock(FiscalProviderResolver::class),
            $this->createMock(FiscalPdfService::class),
            $this->createMock(LoggerInterface::class)
        );

        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'applyFailure');
        $ref->setAccessible(true);

        // Simula el mismo ciclo que hace process(): cada "intento" real llama a
        // applyFailure() una vez tras fallar. attemptNum aquí es solo para el log de
        // auditoría — lo que decide todo es $doc->getRetryCount(), que se acumula dentro
        // de applyFailure() en cada llamada, exactamente como en producción.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $ref->invoke($processor, $doc, $empresa, FiscalDocument::ERROR_TRANSIENT, 'SUNAT no disponible', $attempt, microtime(true));
        }

        self::assertSame(5, $doc->getRetryCount(), 'el intento inicial cuenta como intento 1, hasta 5 en total');
        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertFalse($doc->isRetryable(), 'agotados los 5, ningún camino automático debe poder re-encolarlo');
        self::assertNull($doc->getNextRetryAt());
    }

    public function testFirstFourAttemptsStayRetryingWithAutoSchedule(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('55555555-5555-5555-5555-555555555555');
        $empresa = (new Empresa())->setRetryEnabled(true);

        $processor = new FiscalEmitProcessor(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(FiscalProviderResolver::class),
            $this->createMock(FiscalPdfService::class),
            $this->createMock(LoggerInterface::class)
        );

        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'applyFailure');
        $ref->setAccessible(true);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $ref->invoke($processor, $doc, $empresa, FiscalDocument::ERROR_TRANSIENT, 'SUNAT no disponible', $attempt, microtime(true));
            self::assertSame(FiscalDocument::STATUS_RETRYING, $doc->getStatus(), "intento $attempt debe seguir reintentando");
            self::assertTrue($doc->isRetryable());
            self::assertNotNull($doc->getNextRetryAt());
        }
        self::assertSame(4, $doc->getRetryCount());
    }

    public function testPermanentErrorNeverRetriesEvenOnFirstAttempt(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('66666666-6666-6666-6666-666666666666');
        $empresa = (new Empresa())->setRetryEnabled(true);

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects(self::never())->method('scheduleRetry');

        $processor = new FiscalEmitProcessor(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $queue,
            $this->createMock(FiscalProviderResolver::class),
            $this->createMock(FiscalPdfService::class),
            $this->createMock(LoggerInterface::class)
        );

        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'applyFailure');
        $ref->setAccessible(true);
        $ref->invoke($processor, $doc, $empresa, FiscalDocument::ERROR_PERMANENT, 'Certificado inválido', 1, microtime(true));

        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertFalse($doc->isRetryable());
        self::assertNull($doc->getNextRetryAt());
    }

    public function testExhaustedDocumentCanNeverMatchOrphanRepairQueryCriteria(): void
    {
        // FiscalOrphanRepairService::repairBatch() -> findRetryableTransientErrors() re-encola
        // documentos con errorType='transient' AND retryable=true (13.10 Hallazgo 2 del plan).
        // No se levanta una base de datos real aquí — se prueba la precondición exacta que esa
        // consulta necesita para matchear, sobre el estado real que deja applyFailure() agotado.
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('77777777-7777-7777-7777-777777777777');
        $empresa = (new Empresa())->setRetryEnabled(true);

        $processor = new FiscalEmitProcessor(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(FiscalProviderResolver::class),
            $this->createMock(FiscalPdfService::class),
            $this->createMock(LoggerInterface::class)
        );
        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'applyFailure');
        $ref->setAccessible(true);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $ref->invoke($processor, $doc, $empresa, FiscalDocument::ERROR_TRANSIENT, 'SUNAT no disponible', $attempt, microtime(true));
        }

        $matchesOrphanRepairQuery = $doc->getStatus() === FiscalDocument::STATUS_ERROR
            && $doc->getErrorType() === FiscalDocument::ERROR_TRANSIENT
            && $doc->isRetryable() === true;

        self::assertFalse($matchesOrphanRepairQuery, 'un documento agotado nunca debe volver a calzar con la condición que usa el barrido de huérfanos');
    }
}
