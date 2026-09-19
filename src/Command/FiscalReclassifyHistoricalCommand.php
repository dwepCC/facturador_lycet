<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FiscalAuditLog;
use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalEmitProcessor;
use App\Service\Fiscal\Observability\FiscalAuditService;
use App\Service\Fiscal\Provider\FiscalErrorBucketClassifier;
use App\Service\Fiscal\Provider\PseResponseFormatter;
use App\Service\Fiscal\Provider\SunatDuplicateClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reclasifica documentos fiscales históricos que quedaron mal clasificados antes de las
 * correcciones de las secciones 12-14 de docs/PLAN-MEJORAS-VALIDACION-Y-REINTENTOS.md.
 *
 * NO toca el flujo de emisión en vivo — solo relee documentos ya guardados, aplicando los
 * MISMOS clasificadores ya corregidos en las Fases 1-4 (FiscalErrorBucketClassifier,
 * SunatDuplicateClassifier, FiscalEmitProcessor::isNonRetryableEmitError()). Ninguna lógica de
 * clasificación nueva ni duplicada aquí.
 *
 * Dos universos de documentos, con evidencia real documentada en el plan:
 *  - PSE con status=rejected (sección 12.1, ~291 documentos reales en producción al momento de
 *    la auditoría) — se releen desde `pse_response_json`, guardado en el intento de emisión
 *    original (antes del fix de PseResponseFormatter/ValidaPseProvider).
 *  - Canal directo con status IN (error, retrying), errorType=transient, retry_count>=5
 *    (sección 13.11.1, ~91 documentos reales) — se releen desde `sunat_message` (texto libre,
 *    sin JSON crudo disponible; el canal directo nunca guardó una respuesta estructurada).
 *
 * --dry-run es el comportamiento POR DEFECTO — hace falta pasar --apply explícitamente para
 * persistir cambios. Al revés de otros comandos de este repo (ej. requeue-orphaned, que es
 * dry-run solo si se pide) a propósito: son ~370 documentos reales de producción, no es
 * aceptable correrlo a ciegas (sección 13.8 del plan).
 */
class FiscalReclassifyHistoricalCommand extends Command
{
    protected static $defaultName = 'app:fiscal:reclassify-historical';

    private const BUCKET_ALREADY_SUBMITTED = 'already_submitted';
    private const BUCKET_SENT_PENDING_CDR = 'sent_pending_cdr';

    private FiscalDocumentRepository $repo;
    private EntityManagerInterface $em;
    private FiscalEmitProcessor $emitProcessor;
    private ?FiscalAuditService $audit;

    public function __construct(
        FiscalDocumentRepository $repo,
        EntityManagerInterface $em,
        FiscalEmitProcessor $emitProcessor,
        ?FiscalAuditService $audit = null
    ) {
        parent::__construct();
        $this->repo = $repo;
        $this->em = $em;
        $this->emitProcessor = $emitProcessor;
        $this->audit = $audit;
    }

    protected function configure(): void
    {
        $this
            ->setDescription(
                'Reclasifica documentos históricos mal clasificados antes del fix de '
                . 'PLAN-MEJORAS-VALIDACION-Y-REINTENTOS.md (secciones 12-14). Dry-run por defecto.'
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Escribe los cambios. Sin esta opción, solo se muestra el resumen (dry-run).')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Máximo de documentos por universo', '1000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $limit = max(1, (int) $input->getOption('limit'));

        if (!$apply) {
            $io->warning('DRY-RUN — no se escribirá nada. Pase --apply para aplicar los cambios.');
        } else {
            $io->warning('--apply activo: los cambios se escriben y confirman (flush) documento por documento.');
        }

        $pseCounts = $this->reclassifyPseRejected($io, $apply, $limit);
        $io->newLine();
        $directCounts = $this->reclassifyDirectChannelExhausted($io, $apply, $limit);

        $io->newLine();
        $io->table(
            ['Universo', 'Bucket', 'Documentos'],
            array_merge(
                $this->countsToRows('PSE (status=rejected)', $pseCounts),
                $this->countsToRows('Directo (transient agotado)', $directCounts)
            )
        );

        if (!$apply) {
            $io->note('Nada escrito — fue un dry-run. Vuelva a correr con --apply para aplicar estos mismos resultados.');
        } else {
            $io->success('Reclasificación aplicada. Revise FiscalAuditLog (evento fiscal_historical_reclassified) para el detalle antes/después de cada documento.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, int>
     */
    private function reclassifyPseRejected(SymfonyStyle $io, bool $apply, int $limit): array
    {
        $docs = $this->repo->findPseRejectedForReclassification($limit);
        $io->section(sprintf('PSE con status=rejected: %d documento(s) encontrados', count($docs)));
        $counts = [];

        foreach ($docs as $doc) {
            $resp = $this->decodeEmitTimeResponse($doc);
            $code = isset($resp['code']) ? (string) $resp['code'] : null;
            $message = PseResponseFormatter::message($resp);
            $bucket = $this->classifyPse($code, $message, $resp);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;

            $io->writeln(sprintf(
                '  [%s] uuid=%s code=%s bucket=%s',
                $apply ? 'APLICANDO' : 'simulado',
                $doc->getDocumentUuid(),
                $code ?? '(vacío)',
                $bucket
            ));

            if ($apply) {
                $this->applyPseBucket($doc, $bucket, $code, $message);
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeEmitTimeResponse(FiscalDocument $doc): array
    {
        $raw = $doc->getPseResponseJson();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        // Un documento ya reclasificado por consulta (Fase 5) guarda {"last_consult": {...}}
        // en vez de la respuesta cruda de emisión — no es la forma que este comando espera,
        // y tampoco debería seguir en status=rejected si eso pasó, pero por seguridad no se
        // interpreta esa forma como respuesta de emisión.
        return isset($decoded['last_consult']) ? [] : $decoded;
    }

    /**
     * @param array<string, mixed> $resp
     */
    private function classifyPse(?string $code, string $message, array $resp): string
    {
        if (SunatDuplicateClassifier::isAlreadySubmitted($code, $message)) {
            return self::BUCKET_ALREADY_SUBMITTED;
        }
        $isSuccess = filter_var($resp['isSuccess'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $hasCdr = !empty($resp['cdr']) || !empty($resp['cdr_base64']) || !empty($resp['contenido_cdr']);
        if ($isSuccess && !$hasCdr) {
            return self::BUCKET_SENT_PENDING_CDR;
        }

        return FiscalErrorBucketClassifier::classify($code, $message);
    }

    private function applyPseBucket(FiscalDocument $doc, string $bucket, ?string $code, string $message): void
    {
        $before = ['status' => $doc->getStatus(), 'error_type' => $doc->getErrorType()];

        switch ($bucket) {
            case self::BUCKET_ALREADY_SUBMITTED:
                // Mismo tratamiento que FiscalEmitProcessor::handleAlreadySubmitted() en vivo:
                // terminal, no reintentable solo, requiere "Consultar CDR" manual.
                $doc->setStatus(FiscalDocument::STATUS_ERROR);
                $doc->setErrorType(FiscalDocument::ERROR_PERMANENT);
                $doc->setRetryable(false);
                $doc->setNextRetryAt(null);
                $doc->setSunatMessage(trim(
                    'El comprobante fue informado anteriormente a SUNAT (reclasificado histórico). '
                    . 'Use "Consultar CDR" para validar el estado y recuperar el CDR manualmente. ' . $message
                ));
                break;
            case self::BUCKET_SENT_PENDING_CDR:
                // Mismo tratamiento que FiscalEmitProcessor::handleSentPendingCdr() en vivo:
                // nunca se fabrica un código de aceptación.
                $doc->setStatus(FiscalDocument::STATUS_SENT);
                $doc->setErrorType(null);
                $doc->setRetryable(false);
                $doc->setNextRetryAt(null);
                if ($code !== null && $code !== '') {
                    $doc->setSunatCode($code);
                }
                $doc->setSunatMessage(trim('Enviado a PSE (reclasificado histórico); CDR pendiente de consulta. ' . $message));
                break;
            case FiscalErrorBucketClassifier::BUCKET_BUSINESS:
                // Ya estaba en STATUS_REJECTED (correcto) — solo se deja error_type explícito.
                $doc->setErrorType(FiscalDocument::ERROR_BUSINESS);
                $doc->setRetryable(false);
                $doc->setNextRetryAt(null);
                break;
            case FiscalErrorBucketClassifier::BUCKET_TRANSIENT:
                // Disponible para reenvío MANUAL — nunca se auto-programa (sección 14.1).
                $doc->setStatus(FiscalDocument::STATUS_ERROR);
                $doc->setErrorType(FiscalDocument::ERROR_TRANSIENT);
                $doc->setRetryable(true);
                $doc->setNextRetryAt(null);
                break;
            case FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY:
            default:
                $doc->setStatus(FiscalDocument::STATUS_ERROR);
                $doc->setErrorType('manual_only');
                $doc->setRetryable(true);
                $doc->setNextRetryAt(null);
                break;
        }

        $this->em->flush();
        $this->auditReclassification($doc, $before, $bucket);
    }

    /**
     * @return array<string, int>
     */
    private function reclassifyDirectChannelExhausted(SymfonyStyle $io, bool $apply, int $limit): array
    {
        $docs = $this->repo->findExhaustedTransientDirectChannel($limit);
        $io->section(sprintf('Canal directo, transitorio agotado: %d documento(s) encontrados', count($docs)));
        $counts = [];

        foreach ($docs as $doc) {
            $message = (string) ($doc->getSunatMessage() ?? '');
            $bucket = $this->classifyDirect($message);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;

            $io->writeln(sprintf(
                '  [%s] uuid=%s retry_count=%d bucket=%s',
                $apply ? 'APLICANDO' : 'simulado',
                $doc->getDocumentUuid(),
                $doc->getRetryCount(),
                $bucket
            ));

            if ($apply) {
                $this->applyDirectBucket($doc, $bucket);
            }
        }

        return $counts;
    }

    private function classifyDirect(string $message): string
    {
        // Primero las excepciones PHP que ni llegan a SUNAT (empresa deshabilitada, fecha
        // inválida, credenciales GRE, HTTP 401/403 de la API de guías — sección 13.11.5),
        // mismo chequeo que ya usa el envío en vivo. Si no matchea ahí, se clasifica por el
        // mismo bucket que un fault SOAP (sección 13.11.3).
        if ($this->emitProcessor->isNonRetryableEmitError($message)) {
            return FiscalDocument::ERROR_PERMANENT;
        }

        return FiscalErrorBucketClassifier::classify(null, $message);
    }

    private function applyDirectBucket(FiscalDocument $doc, string $bucket): void
    {
        $before = ['status' => $doc->getStatus(), 'error_type' => $doc->getErrorType()];

        switch ($bucket) {
            case FiscalDocument::ERROR_PERMANENT:
                $doc->setStatus(FiscalDocument::STATUS_ERROR);
                $doc->setErrorType(FiscalDocument::ERROR_PERMANENT);
                $doc->setRetryable(false);
                $doc->setNextRetryAt(null);
                break;
            case FiscalErrorBucketClassifier::BUCKET_BUSINESS:
                $doc->setStatus(FiscalDocument::STATUS_REJECTED);
                $doc->setRejectedAt($doc->getRejectedAt() ?? new \DateTimeImmutable());
                $doc->setErrorType(FiscalDocument::ERROR_BUSINESS);
                $doc->setRetryable(false);
                $doc->setNextRetryAt(null);
                break;
            case FiscalErrorBucketClassifier::BUCKET_TRANSIENT:
                // Agotado bajo el límite viejo (20) — con el nuevo (5) ya no se auto-programa
                // más (sección 14.1): queda disponible solo para reenvío manual.
                $doc->setStatus(FiscalDocument::STATUS_ERROR);
                $doc->setErrorType(FiscalDocument::ERROR_TRANSIENT);
                $doc->setRetryable(true);
                $doc->setNextRetryAt(null);
                break;
            case FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY:
            default:
                $doc->setStatus(FiscalDocument::STATUS_ERROR);
                $doc->setErrorType('manual_only');
                $doc->setRetryable(true);
                $doc->setNextRetryAt(null);
                break;
        }

        $this->em->flush();
        $this->auditReclassification($doc, $before, $bucket);
    }

    /**
     * @param array{status: string, error_type: ?string} $before
     */
    private function auditReclassification(FiscalDocument $doc, array $before, string $bucket): void
    {
        if ($this->audit === null) {
            return;
        }
        try {
            $this->audit->fromDocument($doc, 'fiscal_historical_reclassified', FiscalAuditLog::STATUS_SUCCESS, [
                'metadata_json' => json_encode([
                    'bucket' => $bucket,
                    'from_status' => $before['status'],
                    'to_status' => $doc->getStatus(),
                    'from_error_type' => $before['error_type'],
                    'to_error_type' => $doc->getErrorType(),
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // el registro de auditoría es best-effort; no debe frenar la reclasificación
        }
    }

    /**
     * @param array<string, int> $counts
     * @return array<int, array<int, string>>
     */
    private function countsToRows(string $universe, array $counts): array
    {
        if ($counts === []) {
            return [[$universe, '(sin documentos)', '0']];
        }
        $rows = [];
        foreach ($counts as $bucket => $n) {
            $rows[] = [$universe, $bucket, (string) $n];
        }

        return $rows;
    }
}
