<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;

/**
 * Acciones masivas sobre colas fiscales (sin duplicar lógica de emisión).
 */
class FiscalBulkActionService
{
    private FiscalDocumentRepository $repo;
    private FiscalQueueService $queue;
    private FiscalCustomerEmailNormalizer $emailNormalizer;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalQueueService $queue,
        FiscalCustomerEmailNormalizer $emailNormalizer
    ) {
        $this->repo = $repo;
        $this->queue = $queue;
        $this->emailNormalizer = $emailNormalizer;
    }

    /**
     * @param string[] $uuids
     * @return array{queued: int, skipped: int, errors: array<int, string>}
     */
    public function byUuids(array $uuids, string $action, int $max = 100, ?string $tenantSlug = null): array
    {
        $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids))));
        if (count($uuids) > $max) {
            $uuids = array_slice($uuids, 0, $max);
        }

        $queued = 0;
        $skipped = 0;
        $errors = [];
        $queueName = $this->queueForAction($action);

        foreach ($uuids as $uuid) {
            $doc = $this->repo->findOneBy(['documentUuid' => $uuid]);
            if (!$doc instanceof FiscalDocument) {
                $errors[] = $uuid . ': no encontrado';
                continue;
            }
            if ($tenantSlug !== null && $doc->getTenantSlug() !== $tenantSlug) {
                $errors[] = $uuid . ': fuera de tenant';
                continue;
            }
            if ($this->shouldSkip($doc, $action)) {
                $skipped++;
                continue;
            }
            try {
                $this->queue->push($queueName, $this->payloadFor($doc, $action));
                $queued++;
            } catch (\Throwable $e) {
                $errors[] = $uuid . ': ' . $e->getMessage();
            }
        }

        return ['queued' => $queued, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{queued: int, skipped: int, errors: array<int, string>}
     */
    public function byFilters(array $filters, string $action, int $max = 100): array
    {
        $max = min(500, max(1, $max));
        $tenantSlug = !empty($filters['tenant_slug']) ? (string) $filters['tenant_slug'] : null;
        $filters['limit'] = $max;
        $filters['offset'] = 0;
        $docs = $this->repo->findByFilters($filters);
        $uuids = array_map(static fn (FiscalDocument $d) => $d->getDocumentUuid(), $docs);

        return $this->byUuids($uuids, $action, $max, $tenantSlug);
    }

    private function queueForAction(string $action): string
    {
        switch ($action) {
            case 'email':
                return FiscalQueueService::QUEUE_EMAIL;
            case 'poll':
                return FiscalQueueService::QUEUE_STATUS_POLL;
            case 'consult':
                return FiscalQueueService::QUEUE_CDR_CONSULT;
            case 'send':
            case 'retry':
            case 'force':
                return FiscalQueueService::QUEUE_EMIT;
            default:
                throw new \InvalidArgumentException('acción bulk no soportada');
        }
    }

    private function shouldSkip(FiscalDocument $doc, string $action): bool
    {
        if ($action === 'force') {
            return false;
        }
        // Consulta de CDR: útil incluso en rechazados/errores; solo se omite si ya hay CDR.
        if ($action === 'consult') {
            return in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true)
                && $doc->getCdrUrl() !== null && $doc->getCdrUrl() !== '';
        }
        if (in_array($action, ['send', 'retry'], true) && $this->isBlockedForNormalAction($doc)) {
            return true;
        }
        if ($action === 'email' && !$this->emailNormalizer->isDeliverable($this->emailNormalizer->resolveFromDocument($doc))) {
            return true;
        }

        return false;
    }

    /**
     * Fuente única de verdad para "¿este documento admite un send/retry NORMAL (no force)?"
     * Reutilizada tanto por acciones masivas (shouldSkip) como por acciones individuales
     * (FiscalController::enqueueAction) — evita mantener la regla dos veces.
     *
     * Deliberadamente basada en `error_type`, NO en `status`: un bucket terminal
     * (business/permanent/manual_only) debe seguir bloqueado sin importar en qué `status`
     * haya quedado el documento — antes, el guard sólo miraba `status=ERROR`, y un
     * `business` (que siempre queda en `status=REJECTED`, ver FiscalEmitProcessor y
     * FiscalReclassifyHistoricalCommand) se colaba sin protección. 'transient' NO bloquea
     * aquí aunque esté agotado (retryable=false): el reintento MANUAL explícito de un
     * transitorio agotado sigue siendo un caso de uso válido (decisión aprobada — distingue
     * "ningún camino automático" de "una persona lo pide explícitamente"); lo único que la
     * regla de 5 intentos impide es que vuelva a entrar solo, por cron/requeue.
     */
    public function isBlockedForNormalAction(FiscalDocument $doc): bool
    {
        if ($doc->getStatus() === FiscalDocument::STATUS_ACCEPTED) {
            return true;
        }

        return in_array($doc->getErrorType(), [
            FiscalDocument::ERROR_BUSINESS,
            FiscalDocument::ERROR_PERMANENT,
            'manual_only',
        ], true);
    }

    private function payloadFor(FiscalDocument $doc, string $action): array
    {
        return ['document_uuid' => $doc->getDocumentUuid()];
    }
}
