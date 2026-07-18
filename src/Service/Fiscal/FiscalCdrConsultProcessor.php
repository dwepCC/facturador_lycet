<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use Psr\Log\LoggerInterface;

/**
 * Procesa la cola fiscal:cdr_consult — consulta la validez del comprobante y recupera el CDR
 * SIN reenviarlo. Es una operación de una sola pasada: consulta una vez y termina (no hay
 * reintentos periódicos automáticos; el usuario decide cuándo volver a consultar).
 *
 * El resultado actualiza el estado en el facturador; la sincronización a la BD del tenant
 * queda PENDIENTE de decisión manual (ver {@see FiscalCdrRecoveryService}).
 */
class FiscalCdrConsultProcessor
{
    private FiscalDocumentRepository $repo;
    private FiscalCdrRecoveryService $recovery;
    private LoggerInterface $logger;

    public function __construct(
        FiscalDocumentRepository $repo,
        FiscalCdrRecoveryService $recovery,
        LoggerInterface $logger
    ) {
        $this->repo = $repo;
        $this->recovery = $recovery;
        $this->logger = $logger;
    }

    /**
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    public function processByUuid(string $documentUuid, int $attempt = 1): array
    {
        $doc = $this->repo->findOneBy(['documentUuid' => $documentUuid]);
        if ($doc === null) {
            return $this->emptyResult('Documento no encontrado');
        }

        $hasCdr = $doc->getCdrUrl() !== null && $doc->getCdrUrl() !== '';
        // La consulta de validez/CDR es solo lectura (no reenvía): se permite en cualquier estado,
        // incluido RECHAZADO (se revalida el estado real del comprobante en SUNAT/PSE).
        // Solo se omite cuando no hay nada que hacer: anulado, o ya aceptado/observado CON su CDR.
        if ($doc->getStatus() === FiscalDocument::STATUS_CANCELLED
            || (in_array($doc->getStatus(), [FiscalDocument::STATUS_ACCEPTED, FiscalDocument::STATUS_OBSERVED], true) && $hasCdr)
        ) {
            return $this->emptyResult('El comprobante ya tiene estado definitivo: ' . $doc->getStatus());
        }

        return $this->recovery->recover($doc);
    }

    /**
     * @return array{found: bool, applied: bool, accepted: bool, status: string, sunat_code: ?string, sunat_message: ?string, message: string}
     */
    private function emptyResult(string $message): array
    {
        return [
            'found' => false,
            'applied' => false,
            'accepted' => false,
            'status' => '',
            'sunat_code' => null,
            'sunat_message' => null,
            'message' => $message,
        ];
    }
}
