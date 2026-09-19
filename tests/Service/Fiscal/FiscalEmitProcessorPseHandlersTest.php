<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalEmitProcessor;
use App\Service\Fiscal\FiscalPdfService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\Fiscal\Provider\FiscalEmitResult;
use App\Service\Fiscal\Provider\FiscalProviderResolver;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Prueba los manejadores nuevos de la Fase 2 (handleSentPendingCdr, handleManualOnlyResult) y
 * la rama nueva del dispatch general, invocados vía Reflection — evita tener que orquestar todo
 * process() (deserialización de snapshot, resolución de proveedor) para probar lógica que no
 * depende de eso.
 */
class FiscalEmitProcessorPseHandlersTest extends TestCase
{
    private function makeProcessor(): FiscalEmitProcessor
    {
        return new FiscalEmitProcessor(
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
    }

    private function invokePrivate(FiscalEmitProcessor $processor, string $method, array $args): void
    {
        $ref = new ReflectionMethod(FiscalEmitProcessor::class, $method);
        $ref->setAccessible(true);
        $ref->invoke($processor, ...$args);
    }

    public function testHandleSentPendingCdrSetsStatusSentNeverAccepted(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('11111111-1111-1111-1111-111111111111');
        $doc->setTenantSlug('demo');

        $result = new FiscalEmitResult();
        $result->sunatCode = '200';
        $result->sunatMessage = 'Enviado a PSE, CDR pendiente de consulta';
        $result->sentPendingCdr = true;

        $this->invokePrivate($this->makeProcessor(), 'handleSentPendingCdr', [$doc, $result, 'validapse', 1, microtime(true)]);

        self::assertSame(FiscalDocument::STATUS_SENT, $doc->getStatus());
        self::assertNotSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
        self::assertNull($doc->getErrorType());
        self::assertFalse($doc->isRetryable(), 'no debe auto-reintentar un envío que ya llegó a PSE');
        self::assertNull($doc->getNextRetryAt());
        self::assertNotSame('0', $doc->getSunatCode(), 'nunca se fabrica sunat_code=0');
        self::assertSame('200', $doc->getSunatCode());
    }

    public function testHandleManualOnlyResultNeverAutoRetries(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('22222222-2222-2222-2222-222222222222');
        $doc->setTenantSlug('demo');

        $result = new FiscalEmitResult();
        $result->sunatCode = '0111';
        $result->sunatMessage = 'No tiene el perfil para enviar comprobantes electronicos';
        $result->errorType = 'manual_only';

        $this->invokePrivate($this->makeProcessor(), 'handleManualOnlyResult', [$doc, $result, 'validapse', 1, microtime(true)]);

        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertNotSame(FiscalDocument::STATUS_REJECTED, $doc->getStatus(), 'manual_only no es un rechazo real de SUNAT');
        self::assertSame('manual_only', $doc->getErrorType());
        self::assertTrue($doc->isRetryable(), 'debe quedar disponible para el botón "Reenviar" manual');
        self::assertNull($doc->getNextRetryAt(), 'nunca se programa un reintento automático');
    }

    public function testHandleAlreadySubmittedStillWorksAfterSharedHelperRefactor(): void
    {
        // Prueba de regresión: handleAlreadySubmitted() ahora usa storeSignedXmlIfMissing()
        // compartido — confirma que su comportamiento propio (STATUS_ERROR/permanent) no cambió.
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('33333333-3333-3333-3333-333333333333');
        $doc->setTenantSlug('demo');

        $result = new FiscalEmitResult();
        $result->sunatCode = '1033';
        $result->alreadySubmitted = true;

        $processor = $this->makeProcessor();
        $empresaRef = new \ReflectionClass(\App\Entity\Empresa::class);
        $empresa = $empresaRef->newInstanceWithoutConstructor();

        $this->invokePrivate($processor, 'handleAlreadySubmitted', [$doc, $result, 'validapse', 1, microtime(true), $empresa]);

        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertSame(FiscalDocument::ERROR_PERMANENT, $doc->getErrorType());
        self::assertFalse($doc->isRetryable());
        self::assertStringContainsString('informado anteriormente', (string) $doc->getSunatMessage());
    }
}
