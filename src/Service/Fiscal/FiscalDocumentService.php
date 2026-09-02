<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\Observability\FiscalAuditService;
use App\Entity\FiscalAuditLog;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Crea documentos fiscales idempotentes y los encola.
 * El ERP envía solo tenant_id + ruc + documento; modo fiscal se resuelve en emisión (empresa SSOT).
 */
class FiscalDocumentService
{
    public const SCHEMA_VERSION = '1.0';
    public const SNAPSHOT_VERSION = 1;
    public const GREENTER_VERSION = '5.2';

    private EntityManagerInterface $em;
    private FiscalDocumentRepository $repo;
    private FiscalCustomerEmailNormalizer $emailNormalizer;
    private FiscalQueueService $queue;
    private LoggerInterface $logger;
    private ?FiscalAuditService $audit;

    public function __construct(
        EntityManagerInterface $em,
        FiscalDocumentRepository $repo,
        FiscalQueueService $queue,
        LoggerInterface $logger,
        FiscalCustomerEmailNormalizer $emailNormalizer,
        ?FiscalAuditService $audit = null
    ) {
        $this->em = $em;
        $this->repo = $repo;
        $this->queue = $queue;
        $this->logger = $logger;
        $this->emailNormalizer = $emailNormalizer;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload): FiscalDocument
    {
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $tenantSlug = (string) ($payload['tenant_slug'] ?? '');
        $saleId = (int) ($payload['sale_id'] ?? 0);
        $ruc = trim((string) ($payload['ruc'] ?? ''));
        if ($tenantId <= 0 || $tenantSlug === '' || $saleId <= 0) {
            throw new \InvalidArgumentException('tenant_id, tenant_slug y sale_id son obligatorios');
        }
        if ($ruc === '') {
            throw new \InvalidArgumentException('ruc es obligatorio');
        }

        $snapshot = $payload['document'] ?? $payload['snapshot'] ?? $payload['snapshot_json'] ?? null;
        if (!is_array($snapshot)) {
            throw new \InvalidArgumentException('document JSON requerido');
        }
        unset($snapshot['_meta']);
        $snapshot = FiscalTextSanitizer::sanitize($snapshot);
        $snapshot = DespatchSnapshotEnricher::enrich($snapshot);

        $docType = (string) ($snapshot['tipoDoc'] ?? $payload['document_type'] ?? '03');
        $series = (string) ($snapshot['serie'] ?? $payload['series'] ?? '');
        $number = (string) ($snapshot['correlativo'] ?? $payload['number'] ?? '');

        $fingerprint = FiscalFingerprint::build($tenantId, $docType, $series, $number, $saleId);
        $automatic = ($payload['automatic_send'] ?? true) !== false;

        // Reemisión: corrección de soporte que reenvía un comprobante ya aceptado
        // con otra fecha de emisión (típicamente porque la aceptación fue contra
        // beta y no vale en producción). Es el único caso en que se sobrescribe el
        // snapshot de un documento aceptado, y llega solo desde el endpoint del
        // backend protegido por acceso maestro.
        $reissue = ($payload['reissue'] ?? false) === true;

        $existing = $this->repo->findOneBy(['fiscalFingerprint' => $fingerprint]);
        if ($existing !== null) {
            if ($existing->getStatus() === FiscalDocument::STATUS_ACCEPTED && !$reissue) {
                return $existing;
            }
            if (in_array($existing->getStatus(), [
                FiscalDocument::STATUS_QUEUED,
                FiscalDocument::STATUS_SENDING,
                FiscalDocument::STATUS_SENT,
                FiscalDocument::STATUS_RETRYING,
                FiscalDocument::STATUS_PENDING,
            ], true)) {
                if ($automatic && $this->needsEmitRequeue($existing)) {
                    $this->requeueEmitJob($existing, $fingerprint, $ruc, 'idempotent_requeue');
                }
                return $existing;
            }
        }

        if (!$this->queue->tryClaimEmit($fingerprint, 120)) {
            $locked = $this->repo->findOneBy(['fiscalFingerprint' => $fingerprint]);
            if ($locked !== null) {
                if ($automatic && $this->needsEmitRequeue($locked)) {
                    $this->requeueEmitJob($locked, $fingerprint, $ruc, 'claim_requeue');
                }
                return $locked;
            }
        }

        $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
        if ($snapshotJson === false) {
            throw new \InvalidArgumentException('document JSON inválido');
        }

        $customerEmail = $this->emailNormalizer->extractForStorage($snapshot, $payload);

        $doc = $existing ?? new FiscalDocument();
        if ($existing === null) {
            $doc->setDocumentUuid($this->newUuid());
            $doc->setTenantId($tenantId);
            $doc->setTenantSlug($tenantSlug);
            $doc->setSaleId($saleId);
            $doc->setFiscalFingerprint($fingerprint);
            $this->em->persist($doc);
        }

        if ($reissue && $existing !== null) {
            $doc->setReissueCount($doc->getReissueCount() + 1);
        }

        $doc->setDocumentType($docType);
        $doc->setSeries($series);
        $doc->setNumber($number);
        $doc->setSnapshotJson($snapshotJson);
        $doc->setSnapshotVersion(self::SNAPSHOT_VERSION);
        $doc->setSchemaVersion(self::SCHEMA_VERSION);
        $doc->setGreenterVersion(self::GREENTER_VERSION);
        $doc->setCustomerEmail($customerEmail);

        if (!$automatic) {
            $doc->setStatus(FiscalDocument::STATUS_PENDING);
            $doc->setQueuedAt(null);
            try {
                $this->em->flush();
            } catch (UniqueConstraintViolationException $e) {
                return $this->resolveDuplicate($fingerprint, $e);
            }
            return $doc;
        }

        // Pendiente hasta confirmar job en Redis (evita huérfanos status=queued sin cola).
        $doc->setStatus(FiscalDocument::STATUS_PENDING);
        $doc->setQueuedAt(null);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            return $this->resolveDuplicate($fingerprint, $e);
        }

        $this->requeueEmitJob($doc, $fingerprint, $ruc, 'initial_enqueue');

        return $doc;
    }

    /**
     * Documentos que nunca llegaron a FiscalEmitProcessor (sin provider / sin audit de procesamiento).
     */
    public function needsEmitRequeue(FiscalDocument $doc): bool
    {
        if ($doc->getProvider() !== null && $doc->getProvider() !== '') {
            return false;
        }
        return in_array($doc->getStatus(), [
            FiscalDocument::STATUS_PENDING,
            FiscalDocument::STATUS_QUEUED,
            FiscalDocument::STATUS_RETRYING,
            FiscalDocument::STATUS_SENDING,
        ], true);
    }

    /**
     * Encola fiscal:emit y marca queued solo tras push exitoso.
     */
    public function requeueEmitJob(FiscalDocument $doc, string $fingerprint, string $ruc, string $reason = 'requeue'): void
    {
        if ($fingerprint === '' && $doc->getFiscalFingerprint() !== null) {
            $fingerprint = (string) $doc->getFiscalFingerprint();
        }
        if ($fingerprint === '') {
            $fingerprint = FiscalFingerprint::build(
                $doc->getTenantId(),
                $doc->getDocumentType(),
                $doc->getSeries(),
                $doc->getNumber(),
                $doc->getSaleId()
            );
        }

        $this->queue->push(FiscalQueueService::QUEUE_EMIT, [
            'document_uuid' => $doc->getDocumentUuid(),
            'fingerprint' => $fingerprint,
            'ruc' => $ruc,
            'reason' => $reason,
        ]);

        if ($doc->getStatus() !== FiscalDocument::STATUS_QUEUED || $doc->getQueuedAt() === null) {
            $doc->setStatus(FiscalDocument::STATUS_QUEUED);
            $doc->setQueuedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        $this->auditQueued($doc, $ruc, $reason);
    }

    private function auditQueued(FiscalDocument $doc, string $ruc, string $reason): void
    {
        try {
            if ($this->audit !== null) {
                $this->audit->fromDocument($doc, 'fiscal_document_queued', FiscalAuditLog::STATUS_QUEUED, [
                    'ruc' => $ruc,
                    'queue_job_id' => $doc->getDocumentUuid(),
                    'reason' => $reason,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('fiscal_audit_queued_failed', [
                'document_uuid' => $doc->getDocumentUuid(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveDuplicate(string $fingerprint, UniqueConstraintViolationException $e): FiscalDocument
    {
        $dup = $this->repo->findOneBy(['fiscalFingerprint' => $fingerprint]);
        if ($dup !== null) {
            return $dup;
        }
        throw $e;
    }

    private function newUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
