<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

/**
 * Zona horaria del facturador: TODO se muestra y se filtra en hora de Perú (America/Lima).
 *
 * Hecho verificado en producción (2026-10-06, solo SELECT): las columnas datetime de la BD NO
 * comparten zona horaria, porque cada proceso PHP escribe con la suya:
 *   - peticiones web (PHP-FPM, default UTC): fiscal_documents.created_at / queued_at → UTC
 *   - worker/CLI (php.ini date.timezone = Europe/Berlin): updated_at, sent_at, accepted_at,
 *     next_retry_at, y las filas de fiscal_audit_logs → hora de Berlín (UTC+1/+2 según DST)
 * (los 3 000 documentos más recientes muestran exactamente +2 h entre created_at y su primer evento
 * de auditoría). Doctrine hidrata todo con la zona del proceso lector, así que una fecha escrita por
 * el worker y leída por la web se interpretaba 2 h corrida.
 *
 * Esta clase hace explícita esa diferencia: sabe a qué reloj pertenece cada tipo de columna y
 * convierte a hora de Lima en un solo lugar. Si algún día se unifica la zona de los procesos
 * (idealmente America/Lima en todos) y se normaliza el histórico, basta con poner
 * FISCAL_CLI_TIMEZONE igual a la zona web y esta clase deja de convertir.
 * Ver docs/ZONA-HORARIA.md.
 */
final class FiscalClock
{
    public const LIMA = 'America/Lima';
    private const DEFAULT_CLI_TIMEZONE = 'Europe/Berlin';

    public static function lima(): \DateTimeZone
    {
        return new \DateTimeZone(self::LIMA);
    }

    /** Zona con la que escribe el worker/CLI (php.ini del CLI). */
    public static function cliTimezone(): \DateTimeZone
    {
        $name = $_ENV['FISCAL_CLI_TIMEZONE'] ?? $_SERVER['FISCAL_CLI_TIMEZONE'] ?? (getenv('FISCAL_CLI_TIMEZONE') ?: null);
        try {
            return new \DateTimeZone(is_string($name) && $name !== '' ? $name : self::DEFAULT_CLI_TIMEZONE);
        } catch (\Exception) {
            return new \DateTimeZone(self::DEFAULT_CLI_TIMEZONE);
        }
    }

    /**
     * Valor guardado por el worker/CLI (hora de pared en la zona del CLI) → instante real.
     * Doctrine lo hidrata con la zona del proceso actual; aquí se reinterpreta la hora de pared.
     */
    public static function fromCli(?\DateTimeInterface $stored): ?\DateTimeImmutable
    {
        if ($stored === null) {
            return null;
        }

        return new \DateTimeImmutable($stored->format('Y-m-d H:i:s'), self::cliTimezone());
    }

    /** Valor escrito por una petición web: el instante hidratado ya es correcto. */
    public static function fromWeb(?\DateTimeInterface $stored): ?\DateTimeImmutable
    {
        return $stored === null ? null : \DateTimeImmutable::createFromInterface($stored);
    }

    /** Un instante expresado en el reloj de las columnas del worker, para compararlo en SQL con ellas. */
    public static function cliNow(?\DateTimeInterface $instant = null): \DateTimeImmutable
    {
        $i = $instant === null ? new \DateTimeImmutable() : \DateTimeImmutable::createFromInterface($instant);

        return $i->setTimezone(self::cliTimezone());
    }

    /** Medianoche de hoy en Lima, expresada en la zona dada (para filtrar columnas de esa zona). */
    public static function limaMidnightIn(\DateTimeZone $tz, ?\DateTimeInterface $now = null): \DateTimeImmutable
    {
        $base = $now === null ? new \DateTimeImmutable('now', self::lima()) : \DateTimeImmutable::createFromInterface($now)->setTimezone(self::lima());

        return $base->setTime(0, 0, 0)->setTimezone($tz);
    }

    /** ISO-8601 con offset de Lima (-05:00) de una columna escrita por la web. */
    public static function webToLimaIso(?\DateTimeInterface $stored): ?string
    {
        $i = self::fromWeb($stored);

        return $i === null ? null : $i->setTimezone(self::lima())->format(DATE_ATOM);
    }

    /** ISO-8601 con offset de Lima (-05:00) de una columna escrita por el worker/CLI. */
    public static function cliToLimaIso(?\DateTimeInterface $stored): ?string
    {
        $i = self::fromCli($stored);

        return $i === null ? null : $i->setTimezone(self::lima())->format(DATE_ATOM);
    }

    /** "2026-10-06 19:00" (hora de pared del CLI) → misma hora en Lima, con el mismo formato. */
    public static function cliWallToLima(string $wall, string $format = 'Y-m-d H:i'): string
    {
        try {
            return (new \DateTimeImmutable($wall, self::cliTimezone()))->setTimezone(self::lima())->format($format);
        } catch (\Exception) {
            return $wall;
        }
    }
}
