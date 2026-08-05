<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Service\ConfigProviderInterface;
use App\Service\Fiscal\Provider\NubefactGreProvider;
use App\Service\SeeApiFactory;
use Greenter\Api;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Response\BaseResult;
use PHPUnit\Framework\TestCase;

/**
 * El sandbox de NubeFact rechaza con un 500 genérico ("Error inesperado") cualquier guía cuyo
 * RUC emisor no coincida con el RUC del SOL_USER de pruebas autenticado. Por eso, en pruebas,
 * el RUC dentro del documento se sustituye por el de la cuenta de sandbox justo antes de
 * enviarlo — pero DEBE restaurarse al RUC real del tenant inmediatamente después, porque
 * FiscalEmitProcessor reutiliza el mismo objeto $greenterDoc para renderizar el PDF (que hace
 * su propio lookup de empresa/logo por RUC y fallaría con el RUC de sandbox).
 */
final class NubefactGreProviderRucFallbackTest extends TestCase
{
    private const TENANT_RUC = '10726187938';
    private const SANDBOX_SOL_USER = '20161515648MODDATOS';

    public function testPruebasSustituyeElRucAlEnviarYLoRestauraDespues(): void
    {
        $greenterDoc = (new Despatch())->setCompany((new Company())->setRuc(self::TENANT_RUC));

        $seenRucDuringSend = null;
        $api = $this->createMock(Api::class);
        $api->method('send')
            ->willReturnCallback(function (Despatch $doc) use (&$seenRucDuringSend) {
                $seenRucDuringSend = $doc->getCompany()->getRuc();

                return (new BaseResult())->setSuccess(true);
            });
        $api->method('getLastXml')->willReturn('<xml/>');

        $seeApiFactory = $this->createMock(SeeApiFactory::class);
        $seeApiFactory->method('build')->with(self::TENANT_RUC)->willReturn($api);
        $seeApiFactory->method('getPruebasSolRucPart')->willReturn('20161515648');

        $empresa = (new Empresa())->setAmbiente('pruebas');

        $provider = new NubefactGreProvider($seeApiFactory, $this->fakeConfig(), '/tmp');
        $provider->emit(new FiscalDocument(), $empresa, Despatch::class, $greenterDoc);

        self::assertSame('20161515648', $seenRucDuringSend, 'El RUC enviado a NubeFact debe ser el del SOL de sandbox');
        self::assertSame(self::TENANT_RUC, $greenterDoc->getCompany()->getRuc(), 'El RUC del documento debe quedar restaurado al real del tenant tras el envío');
    }

    public function testProduccionNuncaSustituyeElRuc(): void
    {
        $greenterDoc = (new Despatch())->setCompany((new Company())->setRuc(self::TENANT_RUC));

        $seenRucDuringSend = null;
        $api = $this->createMock(Api::class);
        $api->method('send')
            ->willReturnCallback(function (Despatch $doc) use (&$seenRucDuringSend) {
                $seenRucDuringSend = $doc->getCompany()->getRuc();

                return (new BaseResult())->setSuccess(true);
            });
        $api->method('getLastXml')->willReturn('<xml/>');

        $seeApiFactory = $this->createMock(SeeApiFactory::class);
        $seeApiFactory->method('build')->with(self::TENANT_RUC)->willReturn($api);
        $seeApiFactory->expects(self::never())->method('getPruebasSolRucPart');

        $empresa = (new Empresa())->setAmbiente('produccion');

        $provider = new NubefactGreProvider($seeApiFactory, $this->fakeConfig(), '/tmp');
        $provider->emit(new FiscalDocument(), $empresa, Despatch::class, $greenterDoc);

        self::assertSame(self::TENANT_RUC, $seenRucDuringSend, 'En producción se debe enviar el RUC real del tenant, sin sustituir');
        self::assertSame(self::TENANT_RUC, $greenterDoc->getCompany()->getRuc());
    }

    public function testPruebasRestauraElRucInclusoSiElEnvioFalla(): void
    {
        $greenterDoc = (new Despatch())->setCompany((new Company())->setRuc(self::TENANT_RUC));

        $seeApiFactory = $this->createMock(SeeApiFactory::class);
        $seeApiFactory->method('build')->willThrowException(new \RuntimeException('Cliente No autorizado'));
        $seeApiFactory->method('getPruebasSolRucPart')->willReturn('20161515648');

        $empresa = (new Empresa())->setAmbiente('pruebas');

        $provider = new NubefactGreProvider($seeApiFactory, $this->fakeConfig(), '/tmp');

        try {
            $provider->emit(new FiscalDocument(), $empresa, Despatch::class, $greenterDoc);
            self::fail('Se esperaba una excepción');
        } catch (\RuntimeException $e) {
            // esperado
        }

        self::assertSame(self::TENANT_RUC, $greenterDoc->getCompany()->getRuc(), 'El RUC debe restaurarse aunque el envío falle');
    }

    private function fakeConfig(): ConfigProviderInterface
    {
        return new class () implements ConfigProviderInterface {
            public function get($key)
            {
                return '';
            }

            public function store($key, $value)
            {
                return true;
            }
        };
    }
}
