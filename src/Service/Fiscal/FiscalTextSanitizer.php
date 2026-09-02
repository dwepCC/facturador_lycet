<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

/**
 * Sanea texto libre del snapshot antes de construir el XML UBL.
 *
 * SUNAT rechaza (código 3006 "descripcion de leyenda no cumple con el formato
 * establecido") cualquier nodo tipo Note/leyenda que contenga saltos de línea
 * u otros caracteres de control — típicamente porque el usuario escribió la
 * observación con un Enter de por medio en el ERP. Caso real: tenant
 * aarservicios (RUC 20548414424), F001-78/79/80 rechazadas el 2026-09-02 por
 * un \n dentro de "observacion".
 *
 * Se aplica de forma defensiva a TODO el snapshot (no solo a los campos
 * conocidos) porque ningún campo de este documento debería llevar saltos de
 * línea legítimamente, y así queda protegido cualquier campo de texto libre
 * futuro sin tener que enumerar cada uno.
 */
final class FiscalTextSanitizer
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitize(array $data): array
    {
        array_walk_recursive($data, static function (&$value): void {
            if (is_string($value)) {
                $value = self::sanitizeString($value);
            }
        });

        return $data;
    }

    private static function sanitizeString(string $value): string
    {
        // Normaliza CRLF/CR/LF/tab a espacio y colapsa espacios repetidos.
        $clean = preg_replace('/[\r\n\t]+/u', ' ', $value) ?? $value;
        $clean = preg_replace('/ {2,}/u', ' ', $clean) ?? $clean;

        return trim($clean);
    }
}
