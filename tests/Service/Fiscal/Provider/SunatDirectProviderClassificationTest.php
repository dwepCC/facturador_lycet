<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Service\Fiscal\Provider\SunatDirectProvider;
use App\Service\SeeFactory;
use Greenter\Factory\FeFactory;
use Greenter\Model\Company\Company;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Response\Error;
use Greenter\Model\Sale\Invoice;
use Greenter\See;
use PHPUnit\Framework\TestCase;

/**
 * Fase 3: SunatDirectProvider::emit() ya no usa SunatTerminalFaultClassifier (eliminada,
 * obsoleta) — usa el mismo FiscalErrorBucketClassifier que PSE (13.11.3 del plan). Prueba la
 * rama "SOAP fault sin CDR parseable" con la evidencia real de producción.
 */
class SunatDirectProviderClassificationTest extends TestCase
{
    private function emitWithFault(?string $code, ?string $message): object
    {
        $doc = (new Invoice())->setCompany((new Company())->setRuc('20123456789'));

        $result = (new BaseResult())->setSuccess(false);
        if ($code !== null || $message !== null) {
            $result->setError(new Error($code, $message));
        }

        $factory = $this->createMock(FeFactory::class);
        $factory->method('getLastXml')->willReturn('<xml/>');

        $see = $this->createMock(See::class);
        $see->method('send')->willReturn($result);
        $see->method('getFactory')->willReturn($factory);

        $seeFactory = $this->createMock(SeeFactory::class);
        $seeFactory->method('build')->willReturn($see);

        $provider = new SunatDirectProvider($seeFactory, sys_get_temp_dir());

        return $provider->emit(new FiscalDocument(), new Empresa(), Invoice::class, $doc);
    }

    public function testCode0111IsManualOnlyNotTransient(): void
    {
        // Caso real: 67 documentos, 22 tenants (sección 13.11.1 del plan) — antes de este fix
        // quedaba 'transient' por defecto y se reintentaba indefinidamente sin éxito posible.
        $result = $this->emitWithFault('0111', 'No tiene el perfil para enviar comprobantes electronicos - Detalle: Rejected by policy.');

        self::assertSame('manual_only', $result->errorType);
        self::assertFalse($result->rejected);
        self::assertNotSame('transient', $result->errorType);
    }

    public function testCode1032IsBusinessTerminal(): void
    {
        // Caso real ya cubierto antes por SunatTerminalFaultClassifier (tenant aarservicios,
        // 2026-09-02) — debe seguir funcionando igual tras el reemplazo del clasificador.
        $result = $this->emitWithFault('1032', '1032 - El comprobante ya esta informado y se encuentra con estado anulado o rechazado');

        self::assertTrue($result->rejected);
        self::assertSame('business', $result->errorType);
        self::assertSame('1032', $result->sunatCode);
    }

    public function testCode1033IsAlreadySubmittedNotBusiness(): void
    {
        $result = $this->emitWithFault('1033', 'El comprobante fue registrado previamente con estado ACEPTADO');

        self::assertTrue($result->alreadySubmitted);
        self::assertFalse($result->rejected);
    }

    public function testBusinessRejectionAboveOrEqual2000WithoutCdrIsNoLongerStuckAsTransient(): void
    {
        // Caso real: maracay, código 3105 ("El XML debe contener al menos un tributo por
        // línea de afectación por IGV") llegando como fault SOAP sin CDR parseable — 12+
        // reintentos acumulados antes del fix (sección 9.2 del plan), porque el rango >= 2000
        // solo se aplicaba cuando había un CdrResponse real, nunca en esta rama.
        $result = $this->emitWithFault('3105', 'El XML debe contener al menos un tributo por linea de afectacion por IGV');

        self::assertTrue($result->rejected);
        self::assertSame('business', $result->errorType);
        self::assertSame('3105', $result->sunatCode);
    }

    public function testGenuinelyUnknownFaultStaysTransientAsBeforeSinceNoNeedleOrCodeMatches(): void
    {
        // Comportamiento preservado: un fault realmente no identificado sigue siendo
        // transitorio por defecto (SUNAT contactado, sin veredicto claro) — el límite de 5
        // intentos (Fase 4) es lo que evita que esto se vuelva indefinido, no la clasificación.
        $result = $this->emitWithFault(null, 'Timeout de conexión con SUNAT');

        self::assertSame('transient', $result->errorType);
        self::assertFalse($result->rejected);
    }

    public function testNoErrorAtAllStaysTransientDefault(): void
    {
        $result = $this->emitWithFault(null, null);

        self::assertSame('transient', $result->errorType);
    }
}
