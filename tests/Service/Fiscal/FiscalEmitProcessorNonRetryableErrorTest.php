<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalEmitProcessor;
use App\Service\Fiscal\FiscalPdfService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use App\Service\Fiscal\Provider\FiscalProviderResolver;

/**
 * Fase 3 (13.11.5 del plan): isNonRetryableEmitError() ahora reconoce excepciones PHP que ni
 * llegan a contactar a SUNAT — evidencia real: documentos con 174-203 reintentos acumulados sin
 * poder tener éxito nunca (empresa deshabilitada, fecha inválida, credenciales GRE faltantes).
 */
class FiscalEmitProcessorNonRetryableErrorTest extends TestCase
{
    private function isNonRetryable(string $message): bool
    {
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
        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'isNonRetryableEmitError');
        $ref->setAccessible(true);

        return $ref->invoke($processor, $message);
    }

    public function testEmpresaDeshabilitadaIsNonRetryable(): void
    {
        // Caso real: tenant consorciobarra, 203 reintentos acumulados antes del fix.
        self::assertTrue($this->isNonRetryable('Empresa fiscal deshabilitada o no registrada'));
    }

    public function testEmpresaNoRegistradaDefaultMessageIsNonRetryable(): void
    {
        // Mensaje por defecto de EmpresaNoRegistradaException (SeeFactory/SeeApiFactory
        // cuando el RUC no está en companies.json) — mismo problema de fondo que el caso de
        // arriba (config faltante), distinto texto exacto.
        self::assertTrue($this->isNonRetryable('Empresa no registrada para el RUC indicado.'));
    }

    public function testCredencialesGreFaltantesIsNonRetryable(): void
    {
        self::assertTrue($this->isNonRetryable(
            'La empresa no tiene configuradas las credenciales SUNAT API GRE (Client ID/Secret) para producción.'
        ));
    }

    public function testInvalidDatetimeIsNonRetryable(): void
    {
        // Caso real: 2 documentos, 174 y 181 reintentos acumulados antes del fix.
        self::assertTrue($this->isNonRetryable('Invalid datetime "2026-09-04", expected one of the format "Y-m-d\\TH:i:sP".'));
    }

    public function testHttp401FromGreApiIsNonRetryable(): void
    {
        // Caso real: 5 documentos de un mismo tenant, 166 reintentos acumulados — Guzzle
        // ClientException de api-cpe.sunat.gob.pe.
        self::assertTrue($this->isNonRetryable('[401] Client error: `POST https://api-cpe.sunat.gob.pe/v1/token` resulted in a `401 Unauthorized` response'));
    }

    public function testHttp403FromGreApiIsNonRetryable(): void
    {
        self::assertTrue($this->isNonRetryable('[403] Client error: `POST https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/comprobantes/envio` resulted in a `403 Forbidden` response'));
    }

    public function testGenuineNetworkTimeoutStaysRetryable(): void
    {
        // No debe volverse permanente algo que sí es una caída transitoria real.
        self::assertFalse($this->isNonRetryable('cURL error 28: Connection timed out after 30000 milliseconds'));
        self::assertFalse($this->isNonRetryable('[500] Server Error: SUNAT no disponible'));
    }

    public function testExistingCertificateNeedlesStillWork(): void
    {
        // Regresión: los needles previos (certificado/clave privada) no deben romperse.
        self::assertTrue($this->isNonRetryable('openssl_sign(): supplied key param cannot be coerced into a private key'));
        self::assertTrue($this->isNonRetryable('Certificado inválido: expiró el 2026-01-01'));
    }
}
