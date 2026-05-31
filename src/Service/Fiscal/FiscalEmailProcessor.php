<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Entity\OutboundEmailLog;
use App\Repository\FiscalDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Envía correo fiscal (PDF+XML+CDR) usando email del snapshot — sin consultar tenant DB.
 */
class FiscalEmailProcessor
{
    private EntityManagerInterface $em;
    private FiscalDocumentRepository $repo;
    private FiscalQueueService $queue;
    private FiscalMailerService $mailer;
    private FiscalCustomerEmailNormalizer $emailNormalizer;
    private LoggerInterface $logger;
    private string $mailFrom;

    public function __construct(
        EntityManagerInterface $em,
        FiscalDocumentRepository $repo,
        FiscalQueueService $queue,
        FiscalMailerService $mailer,
        FiscalCustomerEmailNormalizer $emailNormalizer,
        LoggerInterface $logger,
        string $mailFrom = 'noreply@tukifac.com'
    ) {
        $this->em = $em;
        $this->repo = $repo;
        $this->queue = $queue;
        $this->mailer = $mailer;
        $this->emailNormalizer = $emailNormalizer;
        $this->logger = $logger;
        $this->mailFrom = $mailFrom;
    }

    public function processByUuid(string $documentUuid): void
    {
        $doc = $this->repo->findOneBy(['documentUuid' => $documentUuid]);
        if ($doc === null) {
            return;
        }

        $email = $this->emailNormalizer->forDelivery($this->emailNormalizer->resolveFromDocument($doc));
        if ($email === null) {
            $doc->setEmailStatus(FiscalCustomerEmailNormalizer::STATUS_NOT_AVAILABLE);
            $this->em->flush();
            $this->logger->info('fiscal_email_not_available', ['uuid' => $documentUuid]);

            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $log = new OutboundEmailLog();
            $log->setDocumentUuid($documentUuid);
            $log->setRecipientEmail($email);
            $log->setStatus(OutboundEmailLog::STATUS_FAILED);
            $log->setErrorMessage('email inválido');
            $log->setAttempts(1);
            $this->em->persist($log);
            $doc->setEmailStatus('invalid');
            $this->em->flush();

            return;
        }

        $log = new OutboundEmailLog();
        $log->setDocumentUuid($documentUuid);
        $log->setRecipientEmail($email);
        $this->em->persist($log);

        try {
            $this->sendMail($doc, $email, $log);
            $log->setStatus(OutboundEmailLog::STATUS_SENT);
            $log->setSentAt(new \DateTimeImmutable());
            $doc->setEmailStatus('sent');
        } catch (\Throwable $e) {
            $log->setStatus(OutboundEmailLog::STATUS_FAILED);
            $log->setErrorMessage($e->getMessage());
            $log->setAttempts($log->getAttempts() + 1);
            $doc->setEmailStatus('failed');
            if ($log->getAttempts() < 5) {
                $this->queue->scheduleRetry($documentUuid, min(3600, 60 * $log->getAttempts()), FiscalQueueService::QUEUE_EMAIL);
            } elseif (stripos($e->getMessage(), 'invalid') !== false) {
                $doc->setEmailStatus('invalid');
            }
            $this->logger->error('fiscal_email_failed', ['uuid' => $documentUuid, 'error' => $e->getMessage()]);
        }

        $this->em->flush();
    }

    private function sendMail(FiscalDocument $doc, string $to, OutboundEmailLog $log): void
    {
        $response = $this->mailer->sendFiscalDocument($doc, $to);
        $log->setAttempts($log->getAttempts() + 1);
        $log->setProviderResponse($response);
    }
}
