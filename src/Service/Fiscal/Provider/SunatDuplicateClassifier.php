<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

/**
 * Detecta la respuesta de SUNAT/PSE que indica que el comprobante YA fue
 * informado/registrado anteriormente (código 1033 y variantes de mensaje).
 *
 * Este caso NO es un rechazo de negocio ni un fallo transitorio: significa que
 * SUNAT ya aceptó el comprobante en un intento previo (típicamente cuando SUNAT
 * se cayó y no devolvió el CDR). La acción correcta es CONSULTAR el CDR
 * (consulta de validez), no volver a enviar el comprobante.
 */
final class SunatDuplicateClassifier
{
    /**
     * Códigos SUNAT que indican comprobante ya presentado/aceptado previamente.
     * 1033: "El comprobante fue registrado previamente con estado ACEPTADO".
     */
    private const DUPLICATE_CODES = ['1033'];

    /**
     * Fragmentos de mensaje (normalizados sin acentos, en minúsculas) que
     * indican que el comprobante ya existe en SUNAT.
     */
    private const DUPLICATE_NEEDLES = [
        'informado anteriormente',
        'registrado previamente',
        'ya fue presentado',
        'fue presentado anteriormente',
        'presentado anteriormente',
        'ya existe un comprobante',
        'con estado aceptado',
        'estado de aceptado',
    ];

    public static function isAlreadySubmitted(?string $code, ?string $message): bool
    {
        $code = $code !== null ? trim($code) : '';
        if ($code !== '' && in_array($code, self::DUPLICATE_CODES, true)) {
            return true;
        }

        $normalized = self::normalize((string) $message);
        if ($normalized === '') {
            return false;
        }
        foreach (self::DUPLICATE_NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Minúsculas + sin acentos, para comparar mensajes SUNAT de forma robusta.
     */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ];

        return strtr($value, $map);
    }
}
