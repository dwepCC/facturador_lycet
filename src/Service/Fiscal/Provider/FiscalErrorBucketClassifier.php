<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

/**
 * Clasifica un fallo de emisión (código y/o mensaje, de SUNAT directo o de PSE) en un
 * "bucket" que decide si el ENVÍO se reintenta automáticamente o no. Único clasificador
 * de este tipo — reemplaza a SunatTerminalFaultClassifier (obsoleta desde que su lógica
 * vive aquí) y es compartido entre ValidaPseProvider y SunatDirectProvider para que las
 * dos vías nunca se desincronicen.
 *
 * No decide `status` (accepted/observed/rejected) — eso es exclusivamente de un CdrResponse
 * real vía SunatCdrClassifier. Este clasificador solo responde: ¿se reintenta el envío solo,
 * requiere una persona, o es un rechazo real de negocio?
 *
 * Ver docs/PLAN-MEJORAS-VALIDACION-Y-REINTENTOS.md secciones 13.4 y 13.11.3 para la evidencia
 * de producción (PSE y canal directo) detrás de cada código/needle.
 */
final class FiscalErrorBucketClassifier
{
    public const BUCKET_TRANSIENT = 'transient';    // reintento automático de envío (tope 5, sección 11.4)
    public const BUCKET_MANUAL_ONLY = 'manual_only'; // nunca reintenta el envío solo
    public const BUCKET_BUSINESS = 'business';       // rechazo real de negocio, terminal

    /**
     * Códigos PSE (campo `code` del JSON) confirmados en datos reales de producción como
     * fallas transitorias del proveedor/SUNAT — 0154: la corrección real es manual (afiliación
     * PSE/credenciales en el Padrón de PSE de SUNAT), pero el reenvío del documento una vez
     * corregida esa configuración sí debe poder ser automático.
     */
    private const TRANSIENT_CODES = ['0109', '0100', '0154'];

    /**
     * Códigos de negocio puntuales por DEBAJO de 2000, que el rango oficial de
     * SunatCdrClassifier::isBusinessRejectionCode() no cubre. Heredado de
     * SunatTerminalFaultClassifier::TERMINAL_CODES.
     * 1032: "comprobante ya informado, estado anulado o rechazado" — el correlativo quedó
     * quemado, reintentar el MISMO documento nunca va a funcionar (distinto de 1033, que
     * significa que SUNAT ya lo ACEPTÓ antes — ver SunatDuplicateClassifier).
     */
    private const BUSINESS_CODES_BELOW_2000 = ['1032'];

    /**
     * Fragmentos de mensaje (normalizados sin acentos, en minúsculas) que valen para AMBOS
     * canales — el texto de SUNAT es idéntico salga por SOAP fault (directo) o reenviado
     * dentro del JSON de PSE. Confirmado en datos reales: 67 documentos del canal directo
     * (22 tenants) y 84 de PSE (29 tenants) con este mismo mensaje.
     */
    private const MANUAL_ONLY_NEEDLES = [
        'no tiene el perfil para enviar comprobantes',
    ];

    /**
     * Heredado de SunatTerminalFaultClassifier::TERMINAL_NEEDLES — mismo caso que
     * BUSINESS_CODES_BELOW_2000, cuando el código no viene limpio y solo hay mensaje.
     */
    private const BUSINESS_NEEDLES = [
        'estado anulado o rechazado',
        'con estado anulado',
        'con estado rechazado',
    ];

    public static function classify(?string $code, ?string $message = null): string
    {
        $code = $code !== null ? trim($code) : '';
        $normalizedMessage = self::normalize((string) $message);

        if ($code !== '' && in_array($code, self::BUSINESS_CODES_BELOW_2000, true)) {
            return self::BUCKET_BUSINESS;
        }
        foreach (self::BUSINESS_NEEDLES as $needle) {
            if ($normalizedMessage !== '' && str_contains($normalizedMessage, $needle)) {
                return self::BUCKET_BUSINESS;
            }
        }
        foreach (self::MANUAL_ONLY_NEEDLES as $needle) {
            if ($normalizedMessage !== '' && str_contains($normalizedMessage, $needle)) {
                return self::BUCKET_MANUAL_ONLY;
            }
        }
        if ($code === '0111') {
            return self::BUCKET_MANUAL_ONLY;
        }
        if ($code !== '' && in_array($code, self::TRANSIENT_CODES, true)) {
            return self::BUCKET_TRANSIENT;
        }
        // Reutiliza el rango oficial SUNAT ya implementado (>= 2000 = rechazo de negocio),
        // en vez de mantener una segunda lista de códigos de negocio a mano.
        if (SunatCdrClassifier::isBusinessRejectionCode($code !== '' ? $code : null)) {
            return self::BUCKET_BUSINESS;
        }
        if ($code === '') {
            return self::BUCKET_TRANSIENT; // "Server Error" / sin código — infraestructura, transitorio
        }

        // 0151, 1079, HTTP, o cualquier código nuevo no visto todavía: no inventar una
        // clasificación (sección 11.3) — tratamiento conservador, requiere acción manual.
        return self::BUCKET_MANUAL_ONLY;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $map = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];

        return strtr($value, $map);
    }
}
