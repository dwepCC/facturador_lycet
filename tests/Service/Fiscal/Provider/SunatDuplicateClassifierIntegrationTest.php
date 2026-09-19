<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Service\Fiscal\Provider\PseResponseFormatter;
use App\Service\Fiscal\Provider\SunatDuplicateClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Prueba de integración entre el fix de 13.2/14.2.1 (PseResponseFormatter::message() ya lee
 * `errores`) y SunatDuplicateClassifier (sin cambios, ya soportaba el código '1033' y el
 * texto "registrado previamente" — solo que antes nunca le llegaba el mensaje real).
 *
 * No prueba la extracción del `code` embebido en ValidaPseProvider (eso se conecta en la
 * Fase 2) — aquí se prueba el caso límite más importante: aunque el `code` no se pasara
 * (o llegara vacío), el mensaje ya corregido es suficiente por sí solo para detectar el
 * caso "ya informado" por texto.
 */
class SunatDuplicateClassifierIntegrationTest extends TestCase
{
    public function testCode1033RealPseResponseIsDetectedAsAlreadySubmittedViaMessageAlone(): void
    {
        // Caso real: tenant `ortiz`, sección 12.1 del plan. `estado` de PSE (501) nunca es un
        // código SUNAT — antes del fix, si además el `code` embebido no se pasaba, este caso
        // se perdía por completo. Con el fix de 13.2, el mensaje solo ya alcanza.
        $realPseResponse = [
            'isSuccess' => false,
            'estado' => 501,
            'code' => '1033',
            'errores' => "El comprobante fue registrado previamente con otros datos - Detalle: xxx.xxx.xxx "
                . "value='ticket: 202621002062206 error: El comprobante B001-57 fue informado anteriormente'",
            'xml' => '',
        ];

        $message = PseResponseFormatter::message($realPseResponse);

        self::assertNotSame('', $message, 'El mensaje ya no debe llegar vacío tras el fix de errores');
        self::assertTrue(SunatDuplicateClassifier::isAlreadySubmitted(null, $message));
    }

    public function testCode1033DetectedDirectlyByCodeOnceExtractedCorrectly(): void
    {
        // Cuando la Fase 2 extraiga `$pseResp['code']` (no `estado`) y lo pase como primer
        // argumento, el código '1033' solo ya basta — sin depender del texto.
        self::assertTrue(SunatDuplicateClassifier::isAlreadySubmitted('1033', null));
    }

    public function testEstadoFieldAloneNeverTriggersDuplicateDetection(): void
    {
        // `estado` de PSE (ej. 501) NO es un código SUNAT — nunca debe activar por sí solo
        // la detección de "ya informado", ni aunque coincidiera numéricamente con algo.
        self::assertFalse(SunatDuplicateClassifier::isAlreadySubmitted('501', null));
    }
}
