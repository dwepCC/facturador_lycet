<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalCdrConsultProcessor;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalQueueService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Atendido" (2026-09-22): FiscalCdrConsultProcessor::processByUuid() es una de las dos rutas
 * (junto con FiscalStatusPollProcessor) que puede seguir corriendo automáticamente desde un job
 * ya programado en Redis ANTES de que un documento se marque como atendido (p. ej. "forzar" deja
 * un job de reintento CDR huérfano de un envío anterior). A diferencia de enqueueAction(), este
 * processor NO pasaba por ningún guard de "atendido" — recover() sí puede reescribir
 * status/CDR/mensajes del documento. Ver comentario en processByUuid().
 */
class FiscalCdrConsultProcessorAttendedTest extends TestCase
{
    private function buildProcessor(
        FiscalDocumentRepository $repo,
        FiscalCdrRecoveryService $recovery,
        ?FiscalQueueService $queue = null
    ): FiscalCdrConsultProcessor {
        return new FiscalCdrConsultProcessor(
            $repo,
            $recovery,
            $queue ?? $this->createMock(FiscalQueueService::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function attendedDoc(string $status = FiscalDocument::STATUS_ERROR): FiscalDocument
    {
        $doc = (new FiscalDocument())->setDocumentUuid('uuid-attended-cdr')->setStatus($status);
        $doc->setAttended(true);

        return $doc;
    }

    public function testAttendedDocumentNeverCallsRecoverOnManualConsult(): void
    {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($this->attendedDoc());

        $recovery = $this->createMock(FiscalCdrRecoveryService::class);
        $recovery->expects($this->never())->method('recover');

        $processor = $this->buildProcessor($repo, $recovery);
        $result = $processor->processByUuid('uuid-attended-cdr', 1, null, false);

        $this->assertFalse($result['found']);
        $this->assertStringContainsString('atendido', $result['message']);
    }

    /**
     * El caso real que motivó este guard: un job automático (autoRetry=true) del ciclo GRE
     * que quedó programado en Redis antes de atender el documento no debe reprogramarse NI
     * llamar a recover() al dispararse.
     */
    public function testAttendedDocumentNeverCallsRecoverOrReschedulesOnAutomaticRetry(): void
    {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($this->attendedDoc());

        $recovery = $this->createMock(FiscalCdrRecoveryService::class);
        $recovery->expects($this->never())->method('recover');

        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects($this->never())->method('scheduleCdrConsultRetry');

        $processor = $this->buildProcessor($repo, $recovery, $queue);
        $processor->processByUuid('uuid-attended-cdr', 3, null, true);
    }

    public function testNotAttendedDocumentStillCallsRecoverAsBefore(): void
    {
        // Regresión: un documento no atendido (default) debe seguir funcionando exactamente
        // igual que antes de agregar este guard.
        $doc = (new FiscalDocument())->setDocumentUuid('uuid-not-attended-cdr')->setStatus(FiscalDocument::STATUS_SENT);
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        $recovery = $this->createMock(FiscalCdrRecoveryService::class);
        $recovery->expects($this->once())
            ->method('recover')
            ->willReturn(['found' => false, 'applied' => false, 'accepted' => false, 'status' => 'sent', 'sunat_code' => null, 'sunat_message' => null, 'message' => 'sin novedad']);

        $processor = $this->buildProcessor($repo, $recovery);
        $processor->processByUuid('uuid-not-attended-cdr');
    }
}
