<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

/**
 * Detecta faults SOAP de SUNAT que llegan SIN CDR parseable pero que son en
 * realidad rechazos DEFINITIVOS (terminales), no fallas transitorias del
 * sistema — la rama sin CDR de los providers los trata como 'transient' por
 * defecto (ver SunatDirectProvider::emit()), lo cual es correcto para caídas
 * de SUNAT/timeouts pero incorrecto para estos códigos puntuales.
 *
 * 1032: "El comprobante ya está informado y se encuentra con estado anulado o
 * rechazado". A diferencia de 1033 (ver SunatDuplicateClassifier, que indica
 * que SUNAT ya lo ACEPTÓ antes), el 1032 confirma que el correlativo quedó
 * "quemado" tras un rechazo previo — SUNAT nunca lo va a aceptar por más que
 * se reintente el mismo número. Caso real: tenant aarservicios (RUC
 * 20548414424), F001-78/79/80, 2026-09-02 — el worker reintentó 6 veces contra
 * SUNAT antes de detectarse el bucle.
 */
final class SunatTerminalFaultClassifier
{
    /**
     * Códigos de fault SOAP que son rechazos definitivos aunque no traigan CDR.
     */
    private const TERMINAL_CODES = ['1032'];

    /**
     * Fragmentos de mensaje (normalizados sin acentos, en minúsculas) que
     * confirman el mismo caso cuando el código no viene o viene distinto.
     */
    private const TERMINAL_NEEDLES = [
        'estado anulado o rechazado',
        'con estado anulado',
        'con estado rechazado',
    ];

    public static function isTerminal(?string $code, ?string $message): bool
    {
        $code = $code !== null ? trim($code) : '';
        if ($code !== '' && in_array($code, self::TERMINAL_CODES, true)) {
            return true;
        }

        $normalized = self::normalize((string) $message);
        if ($normalized === '') {
            return false;
        }
        foreach (self::TERMINAL_NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($value, $map);
    }
}
