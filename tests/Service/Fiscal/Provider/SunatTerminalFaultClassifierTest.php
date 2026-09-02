<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Service\Fiscal\Provider\SunatTerminalFaultClassifier;
use PHPUnit\Framework\TestCase;

class SunatTerminalFaultClassifierTest extends TestCase
{
    public function testCode1032IsTerminal(): void
    {
        // Caso real: tenant aarservicios (RUC 20548414424), F001-78/79/80,
        // 2026-09-02. Sin este fix, el worker lo trataba como transitorio y
        // reintentó 6 veces contra SUNAT sin poder tener éxito nunca.
        self::assertTrue(SunatTerminalFaultClassifier::isTerminal(
            '1032',
            '1032 - El comprobante ya esta informado y se encuentra con estado anulado o rechazado'
        ));
    }

    public function testMessageFragmentMatchesEvenWithoutExactCode(): void
    {
        self::assertTrue(SunatTerminalFaultClassifier::isTerminal(
            null,
            'Detalle: comprobante registrado con estado ANULADO'
        ));
    }

    public function testUnrelatedFaultIsNotTerminal(): void
    {
        self::assertFalse(SunatTerminalFaultClassifier::isTerminal(
            '0160',
            'Sunat no disponible, intente nuevamente'
        ));
    }

    public function testCode1033DuplicateIsNotTerminal(): void
    {
        // 1033 es el caso opuesto (ya aceptado): lo maneja SunatDuplicateClassifier,
        // no este clasificador.
        self::assertFalse(SunatTerminalFaultClassifier::isTerminal(
            '1033',
            'El comprobante fue registrado previamente con estado ACEPTADO'
        ));
    }

    public function testNullOrEmptyInputsAreNotTerminal(): void
    {
        self::assertFalse(SunatTerminalFaultClassifier::isTerminal(null, null));
        self::assertFalse(SunatTerminalFaultClassifier::isTerminal('', ''));
    }
}
