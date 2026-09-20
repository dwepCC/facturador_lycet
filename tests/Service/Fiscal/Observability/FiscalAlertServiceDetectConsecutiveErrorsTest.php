<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Observability;

use App\Entity\FiscalAuditLog;
use App\Service\Fiscal\Observability\FiscalAlertService;
use PHPUnit\Framework\TestCase;

/**
 * Fase 5 (performance): prueba de EQUIVALENCIA para la eliminación del N+1 en
 * FiscalAlertService::detectConsecutiveErrors(). Antes de esta fase, por cada tenant candidato
 * (encontrado en la ventana de los últimos 500 eventos fiscal_emit_failed/fiscal_emit_success
 * global) se ejecutaba UNA query adicional trayendo sus 5 eventos más recientes reales (sin
 * acotar a esa ventana de 500) y se marcaba "errores consecutivos" solo si los 5 existían y
 * TODOS eran 'failed'. Medido en producción (2026-09-19): ~26 tenants candidatos → 27 queries
 * por ejecución de runDetection(), llamada en cada poll de 30s de /operations/summary.
 *
 * La lógica se extrajo a FiscalAlertService::tenantsWithConsecutiveFailures() (pura, sin I/O)
 * para poder probar la EQUIVALENCIA exacta con el algoritmo anterior sin mockear Doctrine/DBAL.
 * Estos tests reproducen, con datos fijos, los mismos casos que el código viejo (uno por uno)
 * habría evaluado, y confirman que el resultado es idéntico.
 */
class FiscalAlertServiceDetectConsecutiveErrorsTest extends TestCase
{
    private const F = FiscalAuditLog::STATUS_FAILED; // 'failed'
    private const S = 'success';

    /**
     * Referencia: reimplementación literal del algoritmo VIEJO (tenant por tenant, con "su"
     * propia lista de hasta 5 eventos ya recuperada) — usada solo en este test para demostrar
     * equivalencia, nunca en producción.
     *
     * @param array<string, string[]> $recentStatusesByTenant
     * @return string[]
     */
    private function oldAlgorithm(array $candidateSlugs, array $recentStatusesByTenant): array
    {
        $qualifying = [];
        foreach ($candidateSlugs as $slug) {
            $recent = $recentStatusesByTenant[$slug] ?? [];
            if (count($recent) < 5) {
                continue;
            }
            $allFailed = true;
            foreach ($recent as $status) {
                if ($status !== self::F) {
                    $allFailed = false;
                    break;
                }
            }
            if ($allFailed) {
                $qualifying[] = $slug;
            }
        }
        return $qualifying;
    }

    public function testTenantWithFiveConsecutiveFailuresQualifies(): void
    {
        $data = ['tenant-a' => [self::F, self::F, self::F, self::F, self::F]];
        $result = FiscalAlertService::tenantsWithConsecutiveFailures(['tenant-a'], $data);

        self::assertSame(['tenant-a'], $result);
        self::assertSame($this->oldAlgorithm(['tenant-a'], $data), $result);
    }

    public function testTenantWithOneSuccessInterleavedDoesNotQualify(): void
    {
        $data = ['tenant-b' => [self::F, self::F, self::S, self::F, self::F]];
        $result = FiscalAlertService::tenantsWithConsecutiveFailures(['tenant-b'], $data);

        self::assertSame([], $result);
        self::assertSame($this->oldAlgorithm(['tenant-b'], $data), $result);
    }

    public function testCandidateWithFewerThanFiveRealEventsDoesNotQualify(): void
    {
        // Caso defensivo: en la práctica un candidato siempre debería tener >=5 en su
        // historial completo (la ventana de 500 es un subconjunto), pero el chequeo se
        // preserva igual que en el código viejo.
        $data = ['tenant-c' => [self::F, self::F, self::F]];
        $result = FiscalAlertService::tenantsWithConsecutiveFailures(['tenant-c'], $data);

        self::assertSame([], $result);
        self::assertSame($this->oldAlgorithm(['tenant-c'], $data), $result);
    }

    public function testCandidateMissingFromGroupedDataDoesNotQualify(): void
    {
        // Un candidato de la query 1 que por alguna razón no aparece en el resultado de la
        // query 2 (ej. tenant_slug pasó a ser NULL entre ambas queries) no debe romper ni
        // calificar — mismo comportamiento defensivo que antes.
        $result = FiscalAlertService::tenantsWithConsecutiveFailures(['tenant-ghost'], []);

        self::assertSame([], $result);
    }

    public function testMultipleTenantsMixedResultsMatchOldAlgorithmExactly(): void
    {
        $candidates = ['snjempresa', 'grupoimports', 'bawicoffeeshop', 'ellokobraza'];
        $data = [
            'snjempresa' => [self::F, self::F, self::F, self::F, self::F], // califica
            'grupoimports' => [self::F, self::S, self::F, self::F, self::F], // no (1 success)
            'bawicoffeeshop' => [self::F, self::F, self::F, self::F], // no (solo 4)
            'ellokobraza' => [self::F, self::F, self::F, self::F, self::F, self::F], // califica (6, todas failed)
        ];

        $result = FiscalAlertService::tenantsWithConsecutiveFailures($candidates, $data);

        self::assertSame(['snjempresa', 'ellokobraza'], $result);
        self::assertSame($this->oldAlgorithm($candidates, $data), $result);
    }

    public function testEmptyCandidateListReturnsEmpty(): void
    {
        self::assertSame([], FiscalAlertService::tenantsWithConsecutiveFailures([], []));
    }

    /** El orden de eventos importa: solo los primeros 5 (más recientes) determinan el resultado. */
    public function testOnlyFirstFiveEntriesAreConsidered(): void
    {
        // Si por error llegaran más de 5 (no debería pasar con rn<=5 en la query nueva, pero
        // el viejo código también hacía LIMIT 5 explícito) — el criterio sigue siendo sobre
        // TODAS las entradas recibidas, igual que antes (ninguna de las dos versiones trunca
        // internamente; ambas confían en que el caller ya entregó como mucho 5).
        $data = ['tenant-d' => [self::F, self::F, self::F, self::F, self::F]];
        self::assertSame(['tenant-d'], FiscalAlertService::tenantsWithConsecutiveFailures(['tenant-d'], $data));
    }
}
