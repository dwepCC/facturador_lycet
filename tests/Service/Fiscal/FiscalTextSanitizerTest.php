<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Service\Fiscal\FiscalTextSanitizer;
use PHPUnit\Framework\TestCase;

class FiscalTextSanitizerTest extends TestCase
{
    public function testStripsLineBreakInObservacion(): void
    {
        // Caso real: tenant aarservicios (RUC 20548414424), F001-78, rechazada
        // por SUNAT 3006 ("descripcion de leyenda no cumple con el formato
        // establecido") por un \n dentro de "observacion".
        $data = [
            'observacion' => "SERVICIOS PRESTADOS A LA CLÍNICA MONTESUR DURANTE EL MES DE AGOSTO 2026. MÉDICOS DE TURNO.\nOPERACIÓN SUJETA A DETRACCION DEL 12%",
        ];

        $clean = FiscalTextSanitizer::sanitize($data);

        self::assertSame(
            'SERVICIOS PRESTADOS A LA CLÍNICA MONTESUR DURANTE EL MES DE AGOSTO 2026. MÉDICOS DE TURNO. OPERACIÓN SUJETA A DETRACCION DEL 12%',
            $clean['observacion']
        );
        self::assertStringNotContainsString("\n", $clean['observacion']);
    }

    public function testStripsCrLfAndTabInNestedFields(): void
    {
        $data = [
            'legends' => [
                ['code' => '1000', 'value' => "DOS MIL\r\nNOVECIENTOS"],
            ],
            'details' => [
                ['descripcion' => "Servicio   con   espacios\ty tab"],
            ],
        ];

        $clean = FiscalTextSanitizer::sanitize($data);

        self::assertSame('DOS MIL NOVECIENTOS', $clean['legends'][0]['value']);
        self::assertSame('Servicio con espacios y tab', $clean['details'][0]['descripcion']);
    }

    public function testLeavesCleanTextAndNonStringsUntouched(): void
    {
        $data = [
            'observacion' => 'Sin saltos de línea',
            'mtoIGV' => 449.65,
            'cuotas' => [['monto' => 2593.98]],
            'nullable' => null,
        ];

        $clean = FiscalTextSanitizer::sanitize($data);

        self::assertSame($data, $clean);
    }
}
