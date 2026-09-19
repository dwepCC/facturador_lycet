<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\FiscalReclassifyHistoricalCommand;
use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalEmitProcessor;
use App\Service\Fiscal\FiscalPdfService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\Fiscal\Provider\FiscalErrorBucketClassifier;
use App\Service\Fiscal\Provider\FiscalProviderResolver;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fase 7 (sección 13.8 del plan): el comando de reclasificación histórica reutiliza los mismos
 * clasificadores ya corregidos — se prueba aquí que la reutilización funciona con la evidencia
 * real documentada en las secciones 12.1 y 13.11.1, sin duplicar ninguna regla de clasificación.
 */
class FiscalReclassifyHistoricalCommandTest extends TestCase
{
    private function makeCommand(): FiscalReclassifyHistoricalCommand
    {
        $emitProcessor = new FiscalEmitProcessor(
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

        return new FiscalReclassifyHistoricalCommand(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $emitProcessor
        );
    }

    private function invokePrivate(object $obj, string $method, array $args)
    {
        $ref = new ReflectionMethod(get_class($obj), $method);
        $ref->setAccessible(true);

        return $ref->invoke($obj, ...$args);
    }

    // --- Universo PSE (evidencia real, sección 12.1) ---

    public function testPseCode1033IsAlreadySubmittedNotBusiness(): void
    {
        $resp = ['isSuccess' => false, 'estado' => 501, 'code' => '1033', 'errores' => 'El comprobante fue registrado previamente con otros datos'];
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyPse', ['1033', 'registrado previamente', $resp]);

        self::assertSame('already_submitted', $bucket);
    }

    public function testPseIsSuccessTrueWithoutCdrIsSentPendingCdrNotBusiness(): void
    {
        // Caso real: 2 documentos con isSuccess:true y XML pero sin CDR, que la lógica vieja
        // marcaba `rejected` (sección 12.1, última fila de la tabla).
        $resp = ['isSuccess' => true, 'estado' => 200, 'xml' => 'ZmFrZQ=='];
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyPse', [null, '', $resp]);

        self::assertSame('sent_pending_cdr', $bucket);
    }

    public function testPseCode0111IsManualOnly(): void
    {
        $resp = ['isSuccess' => false, 'code' => '0111'];
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyPse', ['0111', 'No tiene el perfil para enviar comprobantes electronicos', $resp]);

        self::assertSame(FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY, $bucket);
    }

    public function testPseCode1032IsBusiness(): void
    {
        $resp = ['isSuccess' => false, 'code' => '1032'];
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyPse', ['1032', 'estado anulado o rechazado', $resp]);

        self::assertSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, $bucket);
    }

    public function testPseCode0154IsTransient(): void
    {
        $resp = ['isSuccess' => false, 'code' => '0154'];
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyPse', ['0154', 'El RUC del archivo no corresponde', $resp]);

        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, $bucket);
    }

    public function testApplyPseBucketNeverFabricatesSunatCodeForSentPendingCdr(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('11111111-1111-1111-1111-111111111111');
        $doc->setStatus(FiscalDocument::STATUS_REJECTED);

        $this->invokePrivate($this->makeCommand(), 'applyPseBucket', [$doc, 'sent_pending_cdr', '200', 'mensaje']);

        self::assertSame(FiscalDocument::STATUS_SENT, $doc->getStatus());
        self::assertNotSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus(), 'nunca se auto-acepta al reclasificar');
        self::assertSame('200', $doc->getSunatCode());
        self::assertNotSame('0', $doc->getSunatCode());
    }

    public function testApplyPseBucketAlreadySubmittedNeverBecomesAccepted(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('22222222-2222-2222-2222-222222222222');
        $doc->setStatus(FiscalDocument::STATUS_REJECTED);

        $this->invokePrivate($this->makeCommand(), 'applyPseBucket', [$doc, 'already_submitted', '1033', 'registrado previamente']);

        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertNotSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
        self::assertFalse($doc->isRetryable());
    }

    public function testApplyPseBucketTransientIsRetryableButNotAutoScheduled(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('33333333-3333-3333-3333-333333333333');
        $doc->setStatus(FiscalDocument::STATUS_REJECTED);

        $this->invokePrivate($this->makeCommand(), 'applyPseBucket', [$doc, FiscalErrorBucketClassifier::BUCKET_TRANSIENT, '0154', 'x']);

        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertSame(FiscalDocument::ERROR_TRANSIENT, $doc->getErrorType());
        self::assertTrue($doc->isRetryable(), 'disponible para reenvío manual');
        self::assertNull($doc->getNextRetryAt(), 'nunca se auto-programa (sección 14.1)');
    }

    // --- Universo canal directo (evidencia real, sección 13.11.1) ---

    public function testDirectEmpresaDeshabilitadaIsPermanentNotTransient(): void
    {
        // Caso real: tenant consorciobarra, 203 reintentos acumulados con la lógica vieja.
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyDirect', ['Empresa fiscal deshabilitada o no registrada']);

        self::assertSame(FiscalDocument::ERROR_PERMANENT, $bucket);
    }

    public function testDirectCode0111MessageIsManualOnly(): void
    {
        // Caso real: 67 documentos, 22 tenants.
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyDirect', ['No tiene el perfil para enviar comprobantes electronicos - Detalle: Rejected by policy.']);

        self::assertSame(FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY, $bucket);
    }

    public function testDirectInvalidDatetimeIsPermanent(): void
    {
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyDirect', ['Invalid datetime "2026-09-04", expected one of the format "Y-m-d\\TH:i:sP".']);

        self::assertSame(FiscalDocument::ERROR_PERMANENT, $bucket);
    }

    public function testDirectGenuineTransientStaysTransient(): void
    {
        $bucket = $this->invokePrivate($this->makeCommand(), 'classifyDirect', ['SUNAT no disponible temporalmente, intente nuevamente']);

        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, $bucket);
    }

    public function testApplyDirectBucketBusinessMovesToRejected(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('44444444-4444-4444-4444-444444444444');
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setErrorType(FiscalDocument::ERROR_TRANSIENT);
        $doc->setRetryCount(194);

        $this->invokePrivate($this->makeCommand(), 'applyDirectBucket', [$doc, FiscalErrorBucketClassifier::BUCKET_BUSINESS]);

        self::assertSame(FiscalDocument::STATUS_REJECTED, $doc->getStatus());
        self::assertSame(FiscalDocument::ERROR_BUSINESS, $doc->getErrorType());
        self::assertFalse($doc->isRetryable());
        self::assertNotNull($doc->getRejectedAt());
    }

    public function testApplyDirectBucketPermanentNeverRetryable(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('55555555-5555-5555-5555-555555555555');
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setErrorType(FiscalDocument::ERROR_TRANSIENT);
        $doc->setRetryCount(203);

        $this->invokePrivate($this->makeCommand(), 'applyDirectBucket', [$doc, FiscalDocument::ERROR_PERMANENT]);

        self::assertSame(FiscalDocument::ERROR_PERMANENT, $doc->getErrorType());
        self::assertFalse($doc->isRetryable());
        self::assertNull($doc->getNextRetryAt());
    }
}
