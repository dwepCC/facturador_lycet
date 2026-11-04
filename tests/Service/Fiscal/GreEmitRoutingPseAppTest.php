<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Service\Fiscal\GreEmitRouting;
use App\Service\Fiscal\Provider\FiscalProviderResolver;
use App\Service\Fiscal\Provider\PseAppProvider;
use PHPUnit\Framework\TestCase;

/**
 * Las guías 09/31 de una empresa con send_mode=pse y provider=pseapp (nuestro PSE) van a PseAppProvider en
 * cualquier ambiente; el resto de combinaciones conserva su enrutamiento (ValidaPSE en producción, NubeFact en
 * pruebas, SUNAT directo).
 */
class GreEmitRoutingPseAppTest extends TestCase
{
    private function empresa(string $mode, string $provider, string $ambiente): Empresa
    {
        $e = new Empresa();
        $e->setSendMode($mode);
        $e->setProvider($provider);
        $e->setAmbiente($ambiente);

        return $e;
    }

    private function doc(string $tipo, Empresa $empresa): FiscalDocument
    {
        $d = new FiscalDocument();
        $d->setDocumentType($tipo);
        $d->setSnapshotJson('{}');
        $d->setSendMode($empresa->getSendMode());

        return $d;
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool, 4: bool}>
     */
    public static function routingProvider(): array
    {
        return [
            'pseapp produccion guía 09' => ['pse', 'pseapp', 'produccion', false, true],
            'pseapp pruebas guía 09' => ['pse', 'pseapp', 'pruebas', false, true],
            'validapse produccion (sin cambio)' => ['pse', 'validapse', 'produccion', false, false],
            'validapse pruebas usa NubeFact (sin cambio)' => ['pse', 'validapse', 'pruebas', true, false],
            'sunat_direct produccion (sin cambio)' => ['sunat_direct', '', 'produccion', true, false],
        ];
    }

    /**
     * @dataProvider routingProvider
     */
    public function testGreRestRoutingAndPseAppDetection(string $mode, string $provider, string $ambiente, bool $expectedRest, bool $expectedPseApp): void
    {
        $empresa = $this->empresa($mode, $provider, $ambiente);
        $doc = $this->doc('09', $empresa);

        self::assertSame($expectedRest, GreEmitRouting::shouldUseGreRestApi($doc, $empresa));
        self::assertSame($expectedPseApp, GreEmitRouting::isPseApp($doc, $empresa));
    }

    public function testResolverRoutesPseAppGuiasToPseAppProvider(): void
    {
        $resolver = new FiscalProviderResolver([new PseAppProvider()]);

        foreach (['produccion', 'pruebas'] as $ambiente) {
            $empresa = $this->empresa('pse', 'pseapp', $ambiente);
            self::assertSame('pseapp', $resolver->resolveName($this->doc('09', $empresa), $empresa));
            self::assertSame('pseapp', $resolver->resolveName($this->doc('31', $empresa), $empresa));
        }
    }
}
