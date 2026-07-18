<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

/**
 * Interpreta la respuesta de la CONSULTA DE ESTADO/VALIDEZ de SUNAT
 * (ConsultCdrService::getStatus/getStatusCdr) cuando NO viene el CDR adjunto.
 *
 * SUNAT puede confirmar que el comprobante existe y está ACEPTADO sin devolver
 * el ZIP del CDR; en ese caso el comprobante es válido y debe marcarse aceptado.
 *
 * La clasificación se basa en el `statusMessage` de SUNAT (texto inequívoco:
 * "aceptado" / "rechazado" / "no existe" / "en proceso"), con el `statusCode`
 * como señal secundaria. No se decide "aceptado" sin evidencia explícita.
 */
final class SunatValidityClassifier
{
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const NOT_FOUND = 'not_found';
    public const IN_PROCESS = 'in_process';
    public const UNKNOWN = 'unknown';

    public static function classify(?string $statusCode, ?string $statusMessage): string
    {
        $msg = self::normalize((string) $statusMessage);

        if ($msg !== '') {
            if (self::containsAny($msg, ['no existe', 'no ha sido informado', 'no fue informado', 'no se ha informado', 'no se encuentra el comprobante', 'comprobante no se encuentra'])) {
                return self::NOT_FOUND;
            }
            if (str_contains($msg, 'rechaz')) {
                return self::REJECTED;
            }
            if (self::containsAny($msg, ['en proceso', 'siendo procesad', 'procesando', 'en tramite'])) {
                return self::IN_PROCESS;
            }
            // "aceptado"/"aceptada" y no es una negación ("no ... aceptado", "no fue aceptado").
            if (str_contains($msg, 'aceptad') && preg_match('/\bno\b.{0,15}acept/', $msg) !== 1) {
                return self::ACCEPTED;
            }
        }

        // Sin mensaje claro: no se afirma validez (evita marcar aceptado sin evidencia).
        return self::UNKNOWN;
    }

    /**
     * @param string[] $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        return strtr($value, $map);
    }
}
