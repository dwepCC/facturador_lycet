<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStatusPollProcessor;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\SeeApiFactory;
use App\Service\SeeFactory;
use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Atendido" (2026-09-22): mismo riesgo que FiscalCdrConsultProcessor — un job de
 * QUEUE_STATUS_POLL programado antes de atender el documento (p. ej. "forzar" reemitió y dejó
 * un ticket viejo con poll pendiente) puede seguir corriendo y reescribir status/CDR/SUNAT code
 * por debajo de la decisión administrativa. El guard debe cortar ANTES de tocar SUNAT/PSE — este
 * test prueba justamente eso: ni siquiera se llega a resolver la empresa/cliente SUNAT.
 */
class FiscalStatusPollProcessorAttendedTest extends TestCase
{
    private function buildProcessor(FiscalDocumentRepository $repo, EmpresaRepository $empresaRepo): FiscalStatusPollProcessor
    {
        return new FiscalStatusPollProcessor(
            $this->createMock(EntityManagerInterface::class),
            $repo,
            $empresaRepo,
            $this->createMock(SerializerInterface::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(SeeFactory::class),
            $this->createMock(SeeApiFactory::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testAttendedDocumentNeverReachesSunatClient(): void
    {
        $doc = (new FiscalDocument())
            ->setDocumentUuid('uuid-attended-poll')
            ->setStatus(FiscalDocument::STATUS_ERROR)
            ->setTicket('ticket-123');
        $doc->setAttended(true);

        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        // Si el guard no corta antes de tiempo, buildStatusClient() resuelve la empresa por RUC
        // para armar el cliente SUNAT — probar que nunca se llega ahí confirma que el guard actúa
        // ANTES de cualquier llamada real a SUNAT/PSE.
        $empresaRepo = $this->createMock(EmpresaRepository::class);
        $empresaRepo->expects($this->never())->method('find');

        $processor = $this->buildProcessor($repo, $empresaRepo);
        $processor->processByUuid('uuid-attended-poll', 3);
    }

    public function testNonAttendedDocumentWithoutTicketStillNoOpsAsBefore(): void
    {
        // Regresión: el guard de "sin ticket" preexistente (línea previa a mi cambio) sigue
        // funcionando igual, sin interferencia del nuevo guard de atendido.
        $doc = (new FiscalDocument())->setDocumentUuid('uuid-no-ticket')->setStatus(FiscalDocument::STATUS_SENT);

        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        $empresaRepo = $this->createMock(EmpresaRepository::class);
        $empresaRepo->expects($this->never())->method('find');

        $processor = $this->buildProcessor($repo, $empresaRepo);
        $processor->processByUuid('uuid-no-ticket');
    }
}
