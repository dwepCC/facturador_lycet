<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Normaliza fechas GRE al formato JMS (Y-m-d\TH:i:sP) antes de deserializar.
 */
final class DespatchDateNormalizer
{
    private const FIELDS_ROOT = ['fechaEmision'];
    private const FIELDS_ENVIO = ['fecTraslado', 'fecEntregaBienes', 'fecEntregaTransportista'];

    /**
     * @param array<string, mixed>|null $data
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $data): ?array
    {
        if (!is_array($data)) {
            return $data;
        }

        $ref = self::normalizeValue($data['fechaEmision'] ?? null);
        if ($ref !== null) {
            $data['fechaEmision'] = $ref;
        }

        if (isset($data['envio']) && is_array($data['envio'])) {
            foreach (self::FIELDS_ENVIO as $field) {
                if (!array_key_exists($field, $data['envio'])) {
                    continue;
                }
                $normalized = self::normalizeValue($data['envio'][$field], $ref);
                if ($normalized !== null) {
                    $data['envio'][$field] = $normalized;
                }
            }
        }

        return $data;
    }

    private static function normalizeValue(mixed $value, ?string $fallback = null): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $raw = trim((string) ($fallback ?? ''));
        }
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $raw)) {
                return (new \DateTimeImmutable($raw, new \DateTimeZone('America/Lima')))->format('Y-m-d\TH:i:sP');
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
                return (new \DateTimeImmutable($raw . ' 12:00:00', new \DateTimeZone('America/Lima')))->format('Y-m-d\TH:i:sP');
            }

            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('America/Lima'))->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
