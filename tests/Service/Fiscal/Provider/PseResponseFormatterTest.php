<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Service\Fiscal\Provider\PseResponseFormatter;
use PHPUnit\Framework\TestCase;

class PseResponseFormatterTest extends TestCase
{
    public function testLegacyKeysStillWork(): void
    {
        self::assertSame('hola mensaje', PseResponseFormatter::message(['mensaje' => 'hola mensaje']));
        self::assertSame('hola message', PseResponseFormatter::message(['message' => 'hola message']));
        self::assertSame('hola errors', PseResponseFormatter::message(['errors' => 'hola errors']));
        self::assertSame('hola error', PseResponseFormatter::message(['error' => 'hola error']));
    }

    public function testErroresAsStringIsRead(): void
    {
        // Caso real: tenant `ortiz`, boleta B001-1 (sección 12.1 del plan). Antes del fix,
        // `errores` (plural, español) no estaba en la lista de claves y el mensaje quedaba
        // vacío, rompiendo la detección de "ya informado" (1033) y el texto mostrado.
        $resp = [
            'isSuccess' => false,
            'estado' => 501,
            'code' => '1033',
            'errores' => 'El comprobante fue registrado previamente con otros datos - Detalle: '
                . "xxx.xxx.xxx value='ticket: 202621002062206 error: El comprobante B001-57 fue informado anteriormente'",
            'xml' => '',
        ];

        self::assertStringContainsString('registrado previamente', PseResponseFormatter::message($resp));
    }

    public function testErroresAsEmptyArrayReturnsEmptyString(): void
    {
        // Caso real: respuesta exitosa de ValidaPSE trae "errores":[] — no debe fallar ni
        // devolver un texto extraño.
        $resp = ['isSuccess' => true, 'estado' => 200, 'errores' => [], 'observaciones' => []];

        self::assertSame('', PseResponseFormatter::message($resp));
    }

    public function testErroresAsArrayOfStringsIsJoined(): void
    {
        $resp = ['errores' => ['Primer detalle', '', 'Segundo detalle']];

        self::assertSame('Primer detalle | Segundo detalle', PseResponseFormatter::message($resp));
    }

    public function testErroresIsIgnoredWhenLegacyKeyAlreadyMatched(): void
    {
        // Prioridad: si ya hay 'mensaje'/'message'/'errors'/'error' con contenido, no se
        // pisa con 'errores' — mantiene el comportamiento previo para no romper nada que
        // ya funcionaba.
        $resp = ['mensaje' => 'mensaje original', 'errores' => 'no debería usarse esto'];

        self::assertSame('mensaje original', PseResponseFormatter::message($resp));
    }

    public function testNoMessageFieldsReturnsEmptyString(): void
    {
        self::assertSame('', PseResponseFormatter::message([]));
        self::assertSame('', PseResponseFormatter::message(['isSuccess' => true, 'estado' => 200]));
    }

    public function testSummarizeUsesFixedMessage(): void
    {
        $resp = ['isSuccess' => false, 'estado' => 501, 'errores' => 'Detalle real del error'];
        $summary = PseResponseFormatter::summarize($resp);

        self::assertNotNull($summary);
        self::assertSame('Detalle real del error', $summary['mensaje']);
    }
}
