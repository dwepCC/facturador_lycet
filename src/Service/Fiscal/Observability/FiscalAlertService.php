<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Observability;

use App\Entity\FiscalAlert;
use App\Entity\FiscalAuditLog;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalAlertRepository;
use App\Repository\FiscalAuditLogRepository;
use App\Service\Fiscal\FiscalQueueService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detección y persistencia de alertas proactivas (sin envío externo aún).
 */
class FiscalAlertService
{
    private const QUEUE_SATURATION_THRESHOLD = 500;
    private const RETRY_ANOMALY_THRESHOLD = 50;
    private const CONSECUTIVE_ERRORS_THRESHOLD = 5;

    private EntityManagerInterface $em;
    private FiscalAlertRepository $alerts;
    private FiscalAuditLogRepository $auditLogs;
    private EmpresaRepository $empresas;
    private FiscalQueueService $queue;

    /** @var array<string, bool> clave "tipo|tenant" de las condiciones que siguen activas en esta pasada */
    private array $activeKeys = [];

    public function __construct(
        EntityManagerInterface $em,
        FiscalAlertRepository $alerts,
        FiscalAuditLogRepository $auditLogs,
        EmpresaRepository $empresas,
        FiscalQueueService $queue
    ) {
        $this->em = $em;
        $this->alerts = $alerts;
        $this->auditLogs = $auditLogs;
        $this->empresas = $empresas;
        $this->queue = $queue;
    }

    public function runDetection(): int
    {
        $this->activeKeys = [];
        $created = 0;
        try {
            $created += $this->detectConnectionIssues();
            $created += $this->detectQueueSaturation();
            $created += $this->detectRetryAnomalies();
            $created += $this->detectConsecutiveErrors();
        } catch (\Throwable) {
            // Si alguna detección falló, el conjunto de condiciones activas está incompleto: no se
            // resuelve nada esta vez (mejor una alerta de más que cerrar una real).
            return $created;
        }
        $this->resolveCleared();

        return $created;
    }

    /**
     * Igual que runDetection() pero como máximo una vez cada $minSeconds (por servidor). Las
     * pantallas que consultan el resumen cada 30 s no deben disparar la detección completa (que
     * escribe en BD) en cada consulta.
     */
    public function runDetectionThrottled(int $minSeconds = 60): int
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fiscal_alert_detection.ts';
        $last = is_file($file) ? (int) @filemtime($file) : 0;
        if ($last > 0 && (time() - $last) < $minSeconds) {
            return 0;
        }
        @touch($file);

        return $this->runDetection();
    }

    /**
     * Cierra automáticamente las alertas cuya condición ya no se cumple (tenant reconectado, cola
     * normalizada, etc.). Sin esto una alerta quedaba abierta para siempre.
     */
    private function resolveCleared(): void
    {
        $evaluated = [
            FiscalAlert::TYPE_CERT_EXPIRING,
            FiscalAlert::TYPE_TENANT_DISCONNECTED,
            FiscalAlert::TYPE_RETRY_ANOMALY,
            FiscalAlert::TYPE_CONSECUTIVE_ERRORS,
        ];
        if ($this->queue->isEnabled()) {
            $evaluated[] = FiscalAlert::TYPE_QUEUE_SATURATED;
        }
        $changed = false;
        foreach ($this->alerts->findBy(['resolvedAt' => null]) as $alert) {
            if (!in_array($alert->getAlertType(), $evaluated, true)) {
                continue;
            }
            if (isset($this->activeKeys[$alert->getAlertType() . '|' . ($alert->getTenantSlug() ?? '')])) {
                continue;
            }
            $alert->setResolvedAt(new \DateTimeImmutable());
            $alert->setMetadataJson($this->mergeMeta($alert->getMetadataJson(), ['resolved_by' => 'auto']));
            $changed = true;
        }
        if ($changed) {
            try {
                $this->em->flush();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * "Reconocer": alguien ya la vio. Deja de contar para el estado del sistema pero sigue visible
     * (atenuada) mientras la condición exista.
     *
     * @return array<string, mixed>|null null si no existe
     */
    public function acknowledge(int $id, ?string $by): ?array
    {
        $alert = $this->alerts->find($id);
        if ($alert === null) {
            return null;
        }
        if ($alert->getAcknowledgedAt() === null) {
            $alert->setAcknowledgedAt(new \DateTimeImmutable());
            $alert->setMetadataJson($this->mergeMeta($alert->getMetadataJson(), ['ack_by' => $by]));
            $this->em->flush();
        }

        return $this->serialize($alert);
    }

    /**
     * "Resolver": se cierra a mano. Si la condición sigue ocurriendo, la próxima detección abrirá
     * una alerta nueva.
     *
     * @return array<string, mixed>|null null si no existe
     */
    public function resolve(int $id, ?string $by): ?array
    {
        $alert = $this->alerts->find($id);
        if ($alert === null) {
            return null;
        }
        if ($alert->getResolvedAt() === null) {
            $now = new \DateTimeImmutable();
            $alert->setResolvedAt($now);
            if ($alert->getAcknowledgedAt() === null) {
                $alert->setAcknowledgedAt($now);
            }
            $alert->setMetadataJson($this->mergeMeta($alert->getMetadataJson(), ['resolved_by' => $by]));
            $this->em->flush();
        }

        return $this->serialize($alert);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function mergeMeta(?string $json, array $extra): string
    {
        $data = $json ? json_decode($json, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        return json_encode(array_merge($data, $extra), JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listOpen(int $limit = 50): array
    {
        $rank = ['critical' => 0, 'warning' => 1];
        $open = $this->alerts->findOpen($limit);
        // Sin reconocer primero, luego por severidad y las más recientes arriba.
        usort($open, static function (FiscalAlert $a, FiscalAlert $b) use ($rank): int {
            $ackA = $a->getAcknowledgedAt() === null ? 0 : 1;
            $ackB = $b->getAcknowledgedAt() === null ? 0 : 1;
            if ($ackA !== $ackB) {
                return $ackA <=> $ackB;
            }
            $sevA = $rank[$a->getSeverity()] ?? 2;
            $sevB = $rank[$b->getSeverity()] ?? 2;
            if ($sevA !== $sevB) {
                return $sevA <=> $sevB;
            }

            return $b->getCreatedAt() <=> $a->getCreatedAt();
        });

        return array_map(fn (FiscalAlert $a) => $this->serialize($a), $open);
    }

    public function countUnacknowledged(): int
    {
        return $this->alerts->countUnacknowledged();
    }

    public function countOpen(): int
    {
        return $this->alerts->countOpen();
    }

    private function detectConnectionIssues(): int
    {
        $created = 0;
        foreach ($this->empresas->findBy(['enabled' => true]) as $empresa) {
            $status = $empresa->getConnectionStatus();
            $slug = $empresa->getTenantSlug();
            if ($slug === null || $slug === '') {
                continue;
            }

            if ($status === 'certificate_expired') {
                $created += $this->openAlert(
                    FiscalAlert::TYPE_CERT_EXPIRING,
                    'critical',
                    $empresa->getTenantId(),
                    $slug,
                    $empresa->getRuc(),
                    'Certificado fiscal vencido para RUC ' . $empresa->getRuc()
                ) ? 1 : 0;
            } elseif (in_array($status, ['invalid_credentials', 'configuration_missing', 'error'], true)) {
                $created += $this->openAlert(
                    FiscalAlert::TYPE_TENANT_DISCONNECTED,
                    'warning',
                    $empresa->getTenantId(),
                    $slug,
                    $empresa->getRuc(),
                    'Tenant desconectado: ' . ($empresa->getConnectionError() ?? $status)
                ) ? 1 : 0;
            }
        }
        return $created;
    }

    private function detectQueueSaturation(): int
    {
        if (!$this->queue->isEnabled()) {
            return 0;
        }
        $pending = $this->queue->queueLength(FiscalQueueService::QUEUE_EMIT);
        if ($pending < self::QUEUE_SATURATION_THRESHOLD) {
            return 0;
        }
        return $this->openAlert(
            FiscalAlert::TYPE_QUEUE_SATURATED,
            'critical',
            null,
            null,
            null,
            sprintf('Cola fiscal saturada: %d jobs pendientes', $pending),
            ['pending_jobs' => $pending]
        ) ? 1 : 0;
    }

    private function detectRetryAnomalies(): int
    {
        // fiscal_audit_logs lo escribe el worker: la ventana de 24 h va en su reloj (ver FiscalClock).
        $since = \App\Service\Fiscal\FiscalClock::cliNow(new \DateTimeImmutable('-24 hours'));
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            'SELECT tenant_slug, COUNT(*) AS retries FROM fiscal_audit_logs WHERE status = :st AND created_at >= :since AND tenant_slug IS NOT NULL GROUP BY tenant_slug HAVING retries >= :min',
            [
                'st' => FiscalAuditLog::STATUS_RETRYING,
                'since' => $since->format('Y-m-d H:i:s'),
                'min' => self::RETRY_ANOMALY_THRESHOLD,
            ]
        );
        $created = 0;
        foreach ($rows as $row) {
            $created += $this->openAlert(
                FiscalAlert::TYPE_RETRY_ANOMALY,
                'warning',
                null,
                (string) $row['tenant_slug'],
                null,
                sprintf('Retries anormales en %s: %d en 24h', $row['tenant_slug'], $row['retries']),
                ['retries_24h' => (int) $row['retries']]
            ) ? 1 : 0;
        }
        return $created;
    }

    private function detectConsecutiveErrors(): int
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            <<<'SQL'
SELECT tenant_slug, COUNT(*) AS failures FROM (
  SELECT tenant_slug, status FROM fiscal_audit_logs
  WHERE tenant_slug IS NOT NULL AND event_type IN ('fiscal_emit_failed','fiscal_emit_success')
  ORDER BY created_at DESC LIMIT 500
) recent GROUP BY tenant_slug HAVING failures >= :min
SQL,
            ['min' => self::CONSECUTIVE_ERRORS_THRESHOLD]
        );
        $candidateSlugs = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['tenant_slug'],
            $rows
        )));
        if ($candidateSlugs === []) {
            return 0;
        }

        // Fase 5 (performance) — INTENTO DE ELIMINAR EL N+1 MEDIDO Y REVERTIDO: se probó
        // reemplazar las hasta N queries por-tenant (una por candidato) por UNA sola query
        // batched con ROW_NUMBER() OVER (PARTITION BY tenant_slug). Medido en producción
        // (2026-09-20, 26 tenants candidatos reales): la versión batched tardó 361ms (fuerza
        // un sort sobre ~20 600 filas porque un tenant de altísimo volumen —snjempresa, >170
        // eventos en la ventana de 500— domina el filtro `tenant_slug IN (...)` y hace que el
        // optimizador descarte el índice `idx_fal_tenant_created` en favor de un scan+filesort
        // completo). Las 26 queries por-tenant ORIGINALES, ejecutadas en UNA sola conexión
        // (el escenario real de Doctrine con conexión persistente, no 26 procesos SSH nuevos),
        // tardaron 75ms — cada una es un lookup indexado barato (`idx_fal_tenant_created`,
        // backward index scan + LIMIT 5). Es decir: la reescritura "sin N+1" era ~4.8x MÁS
        // LENTA que el código original. Se revirtió tras la remedición (regla de la Fase 5:
        // "no aceptar 'la consulta es más rápida' como única validación" — aquí ni siquiera
        // lo era). El patrón de N queries se mantiene sin cambios porque la medición demuestra
        // que, con el índice compuesto (tenant_slug, created_at) ya existente, NO es un cuello
        // de botella real a este volumen (~168ms totales por ejecución de runDetection(), no
        // los "N+1 catastrófico" que sugería la lectura estática del código). Ver
        // backend_go/docs/AUDITORIA-PANEL-CENTRAL-FISCAL-FASE5-PERFORMANCE.md sección de
        // detectConsecutiveErrors para la medición completa.
        //
        // Lo único que sí se conservó de este intento: la lógica de decisión pura se extrajo a
        // tenantsWithConsecutiveFailures() (sin I/O) para poder probarla con datos fijos, sin
        // mockear Doctrine/DBAL — refactor de testabilidad, cero impacto en las queries.
        $created = 0;
        foreach ($candidateSlugs as $slug) {
            $recent = $conn->fetchAllAssociative(
                'SELECT status FROM fiscal_audit_logs WHERE tenant_slug = :slug AND event_type IN (:e1,:e2) ORDER BY created_at DESC LIMIT :lim',
                ['slug' => $slug, 'e1' => 'fiscal_emit_failed', 'e2' => 'fiscal_emit_success', 'lim' => self::CONSECUTIVE_ERRORS_THRESHOLD],
                ['lim' => \Doctrine\DBAL\ParameterType::INTEGER]
            );
            $statuses = array_map(static fn (array $r): string => (string) $r['status'], $recent);

            if (self::tenantsWithConsecutiveFailures([$slug], [$slug => $statuses]) === []) {
                continue;
            }
            $created += $this->openAlert(
                FiscalAlert::TYPE_CONSECUTIVE_ERRORS,
                'critical',
                null,
                $slug,
                null,
                sprintf('%d errores consecutivos en tenant %s', self::CONSECUTIVE_ERRORS_THRESHOLD, $slug)
            ) ? 1 : 0;
        }
        return $created;
    }

    /**
     * Decisión pura (sin I/O): de los tenants candidatos, cuáles tienen sus últimos
     * CONSECUTIVE_ERRORS_THRESHOLD eventos TODOS en status=failed. Extraída de
     * detectConsecutiveErrors() para poder probarla con datos fijos sin mockear Doctrine/DBAL
     * — el mismo criterio exacto que antes de Fase 5 (cuando se verificaba tenant por tenant
     * con una query adicional cada uno).
     *
     * @param string[] $candidateSlugs
     * @param array<string, string[]> $recentStatusesByTenant tenant_slug => statuses ordenados
     *     más reciente primero (ya truncados a lo sumo a CONSECUTIVE_ERRORS_THRESHOLD)
     * @return string[] tenant_slugs que califican para la alerta
     */
    public static function tenantsWithConsecutiveFailures(array $candidateSlugs, array $recentStatusesByTenant): array
    {
        $qualifying = [];
        foreach ($candidateSlugs as $slug) {
            $recentStatuses = $recentStatusesByTenant[$slug] ?? [];
            if (count($recentStatuses) < self::CONSECUTIVE_ERRORS_THRESHOLD) {
                continue;
            }
            $allFailed = true;
            foreach ($recentStatuses as $status) {
                if ($status !== FiscalAuditLog::STATUS_FAILED) {
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

    /**
     * @param array<string, mixed> $meta
     */
    private function openAlert(
        string $type,
        string $severity,
        ?int $tenantId,
        ?string $tenantSlug,
        ?string $ruc,
        string $message,
        array $meta = []
    ): bool {
        // La condición sigue activa en esta pasada (aunque ya exista la alerta): no se auto-resuelve.
        $this->activeKeys[$type . '|' . ($tenantSlug ?? '')] = true;

        $existing = $this->alerts->createQueryBuilder('a')
            ->where('a.alertType = :t')
            ->andWhere('a.resolvedAt IS NULL')
            ->setParameter('t', $type);

        if ($tenantSlug !== null && $tenantSlug !== '') {
            $existing->andWhere('a.tenantSlug = :s')->setParameter('s', $tenantSlug);
        } else {
            $existing->andWhere('a.tenantSlug IS NULL');
        }

        if ($existing->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null) {
            return false;
        }

        $alert = new FiscalAlert();
        $alert->setAlertType($type);
        $alert->setSeverity($severity);
        $alert->setMessage($message);
        if ($tenantId !== null) {
            $alert->setTenantId($tenantId);
        }
        if ($tenantSlug !== null) {
            $alert->setTenantSlug($tenantSlug);
        }
        if ($ruc !== null) {
            $alert->setRuc($ruc);
        }
        if ($meta !== []) {
            $alert->setMetadataJson(json_encode($meta, JSON_UNESCAPED_UNICODE) ?: null);
        }

        try {
            $this->em->persist($alert);
            $this->em->flush();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FiscalAlert $a): array
    {
        return [
            'id' => $a->getId(),
            'tenant_id' => $a->getTenantId(),
            'tenant_slug' => $a->getTenantSlug(),
            'ruc' => $a->getRuc(),
            'alert_type' => $a->getAlertType(),
            'severity' => $a->getSeverity(),
            'message' => $a->getMessage(),
            'metadata' => $a->getMetadataJson() ? json_decode($a->getMetadataJson(), true) : null,
            'created_at' => $a->getCreatedAt()->format(DATE_ATOM),
            'acknowledged_at' => $a->getAcknowledgedAt() ? $a->getAcknowledgedAt()->format(DATE_ATOM) : null,
            'resolved_at' => $a->getResolvedAt() ? $a->getResolvedAt()->format(DATE_ATOM) : null,
            'age_seconds' => max(0, time() - $a->getCreatedAt()->getTimestamp()),
        ];
    }
}
