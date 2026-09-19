<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

/**
 * Normaliza mensajes y resumen de respuestas PSE (ValidaPSE, etc.).
 */
final class PseResponseFormatter
{
    /**
     * @param array<string, mixed> $resp
     */
    public static function message(array $resp): string
    {
        foreach (['mensaje', 'message', 'errors', 'error'] as $key) {
            if (!empty($resp[$key]) && is_string($resp[$key])) {
                return trim($resp[$key]);
            }
        }

        // ValidaPSE devuelve el detalle real en `errores` (plural, español) — no documentado
        // así en la referencia de la API (sección 6 del plan), pero es lo que la API real envía.
        // El campo puede venir como string ("El comprobante fue...") o como arreglo (vacío []
        // en respuestas exitosas, o de strings con detalle en respuestas de error).
        if (isset($resp['errores'])) {
            if (is_string($resp['errores']) && trim($resp['errores']) !== '') {
                return trim($resp['errores']);
            }
            if (is_array($resp['errores'])) {
                $parts = [];
                foreach ($resp['errores'] as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $parts[] = trim($item);
                    }
                }
                if ($parts !== []) {
                    return implode(' | ', $parts);
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed>|null $resp
     * @return array<string, mixed>|null
     */
    public static function summarize(?array $resp): ?array
    {
        if ($resp === null || $resp === []) {
            return null;
        }

        return [
            'isSuccess' => $resp['isSuccess'] ?? null,
            'estado' => $resp['estado'] ?? null,
            'mensaje' => self::message($resp),
            'codigo_hash' => $resp['codigo_hash'] ?? null,
            'external_id' => $resp['external_id'] ?? null,
        ];
    }
}
