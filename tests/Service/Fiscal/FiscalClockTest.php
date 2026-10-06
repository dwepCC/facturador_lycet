<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Service\Fiscal\FiscalClock;
use PHPUnit\Framework\TestCase;

/**
 * Zona horaria del facturador (ver docs/ZONA-HORARIA.md). Las columnas escritas por la web están en el
 * reloj del proceso web; las que mueve el worker, en el reloj del CLI (Berlín en producción). Todo se
 * muestra en hora de Lima.
 */
class FiscalClockTest extends TestCase
{
    private function withCliTimezone(string $tz, callable $fn): void
    {
        $old = $_ENV['FISCAL_CLI_TIMEZONE'] ?? null;
        $_ENV['FISCAL_CLI_TIMEZONE'] = $tz;
        try {
            $fn();
        } finally {
            if ($old === null) {
                unset($_ENV['FISCAL_CLI_TIMEZONE']);
            } else {
                $_ENV['FISCAL_CLI_TIMEZONE'] = $old;
            }
        }
    }

    public function testWebColumnIsAlreadyAnInstantAndIsShownInLima(): void
    {
        // Producción: la web hidrata en UTC. 17:54:54 UTC = 12:54:54 en Lima.
        $stored = new \DateTimeImmutable('2026-10-06 17:54:54', new \DateTimeZone('UTC'));

        self::assertSame('2026-10-06T12:54:54-05:00', FiscalClock::webToLimaIso($stored));
    }

    public function testWorkerColumnIsReinterpretedWithTheCliClock(): void
    {
        $this->withCliTimezone('Europe/Berlin', function (): void {
            // El worker guardó 19:54:56 (hora de Berlín, CEST = UTC+2) = 17:54:56 UTC = 12:54:56 Lima.
            // La web lo hidrata como si fuera UTC: la hora de pared se reinterpreta, no se convierte.
            $hydratedByWeb = new \DateTimeImmutable('2026-10-06 19:54:56', new \DateTimeZone('UTC'));

            self::assertSame('2026-10-06T12:54:56-05:00', FiscalClock::cliToLimaIso($hydratedByWeb));
        });
    }

    public function testBerlinDaylightSavingIsHandled(): void
    {
        $this->withCliTimezone('Europe/Berlin', function (): void {
            // Invierno (CET = UTC+1): 18:00 Berlín = 17:00 UTC = 12:00 Lima.
            $winter = new \DateTimeImmutable('2026-01-15 18:00:00', new \DateTimeZone('UTC'));
            self::assertSame('2026-01-15T12:00:00-05:00', FiscalClock::cliToLimaIso($winter));
        });
    }

    public function testNullStaysNull(): void
    {
        self::assertNull(FiscalClock::webToLimaIso(null));
        self::assertNull(FiscalClock::cliToLimaIso(null));
        self::assertNull(FiscalClock::fromCli(null));
    }

    public function testWhenTheCliClockIsTheSameAsTheWebClockNothingIsShifted(): void
    {
        // Equivale a unificar las zonas: con FISCAL_CLI_TIMEZONE = zona web, la columna del worker se
        // interpreta igual que la de la web.
        $this->withCliTimezone('UTC', function (): void {
            $stored = new \DateTimeImmutable('2026-10-06 17:00:00', new \DateTimeZone('UTC'));
            self::assertSame(FiscalClock::webToLimaIso($stored), FiscalClock::cliToLimaIso($stored));
        });
    }

    public function testLimaMidnightExpressedInTheCliClock(): void
    {
        $this->withCliTimezone('Europe/Berlin', function (): void {
            // 2026-10-06 15:00 UTC = 10:00 Lima → la medianoche de Lima es 00:00 -05:00 = 05:00 UTC = 07:00 Berlín.
            $now = new \DateTimeImmutable('2026-10-06 15:00:00', new \DateTimeZone('UTC'));
            $midnight = FiscalClock::limaMidnightIn(FiscalClock::cliTimezone(), $now);

            self::assertSame('2026-10-06 07:00:00', $midnight->format('Y-m-d H:i:s'));
        });
    }

    public function testLimaMidnightRollsBackWhenItIsStillYesterdayInLima(): void
    {
        $this->withCliTimezone('Europe/Berlin', function (): void {
            // 02:00 UTC del día 6 todavía es el día 5 en Lima (21:00): "hoy" empezó el día 5 a las 00:00 Lima.
            $now = new \DateTimeImmutable('2026-10-06 02:00:00', new \DateTimeZone('UTC'));
            $midnight = FiscalClock::limaMidnightIn(new \DateTimeZone('UTC'), $now);

            self::assertSame('2026-10-05 05:00:00', $midnight->format('Y-m-d H:i:s'));
        });
    }

    public function testHourLabelsFromTheWorkerClockAreShownInLima(): void
    {
        $this->withCliTimezone('Europe/Berlin', function (): void {
            self::assertSame('2026-10-06 12:00', FiscalClock::cliWallToLima('2026-10-06 19:00'));
            self::assertSame('2026-10-06 12:00', FiscalClock::cliWallToLima('2026-10-06 19:00', 'Y-m-d H:00'));
        });
    }

    public function testInvalidCliTimezoneFallsBackToTheDefault(): void
    {
        $this->withCliTimezone('No/Existe', function (): void {
            self::assertSame('Europe/Berlin', FiscalClock::cliTimezone()->getName());
        });
    }
}
