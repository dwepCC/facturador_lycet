<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Repository\FiscalDocumentRepository;
use App\Service\Fiscal\CdrNormalizer;
use App\Service\Fiscal\FiscalBulkActionService;
use App\Service\Fiscal\FiscalCdrConsultProcessor;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalCustomerEmailNormalizer;
use App\Service\Fiscal\FiscalEmitProcessor;
use App\Service\Fiscal\FiscalPdfService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\Fiscal\Provider\FiscalEmitResult;
use App\Service\Fiscal\Provider\FiscalProviderResolver;
use App\Service\Fiscal\Provider\PseAuthBuilder;
use App\Service\SeeApiFactory;
use Doctrine\ORM\EntityManagerInterface;
use Greenter\Api;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\StatusResult;
use JMS\Serializer\SerializerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Guías (09/31) enviadas por PSE en producción: no tienen ticket, así que la consulta de CDR va al PSE
 * (GET /api/cpe/consultar/RUC-TIPO-SERIE-NUMERO) y no al API REST GRE; el ciclo automático las trata como
 * pendientes con una ventana más larga; y nunca se reenvía una guía ya enviada sin veredicto.
 */
class FiscalGrePseConsultTest extends TestCase
{
    private const RUC = '20546402933';

    private const CDR_XML = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<ar:ApplicationResponse xmlns:ar="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2"'
        . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
        . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
        . '<cbc:ID>1</cbc:ID><cac:DocumentResponse><cac:Response>'
        . '<cbc:ReferenceID>T002-66</cbc:ReferenceID><cbc:ResponseCode>0</cbc:ResponseCode>'
        . '<cbc:Description>La Guia de Remision numero T002-66, ha sido aceptada</cbc:Description>'
        . '</cac:Response></cac:DocumentResponse></ar:ApplicationResponse>';

    private function empresa(string $sendMode, string $ambiente = 'produccion'): Empresa
    {
        $e = new Empresa();
        $e->setRuc(self::RUC);
        $e->setAmbiente($ambiente);
        $e->setSendMode($sendMode);
        $e->setProvider('validapse');
        $e->setConnectionType('validapse');
        $e->setPseBaseUrl('https://pse.example.test');
        $e->setPseToken('tok');

        return $e;
    }

    private function guide(string $sendMode = 'pse', bool $nested = true): FiscalDocument
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('aaaaaaaa-0000-0000-0000-000000000066');
        $doc->setTenantSlug('demo');
        $doc->setDocumentType('09');
        $doc->setSeries('T002');
        $doc->setNumber('66');
        $doc->setSendMode($sendMode);
        $doc->setSunatMode('production');
        $doc->setStatus(FiscalDocument::STATUS_ERROR);
        $doc->setErrorType('manual_only');
        $doc->setSentAt(new \DateTimeImmutable());
        $company = ['ruc' => self::RUC];
        $doc->setSnapshotJson((string) json_encode($nested ? ['document' => ['company' => $company]] : ['company' => $company]));

        return $doc;
    }

    /** Servicio con el GET al PSE simulado: guarda el endpoint pedido en $spy y responde $body. */
    private function service(Empresa $empresa, ?string $body, ?SeeApiFactory $factory, \stdClass $spy): FiscalCdrRecoveryService
    {
        $repo = $this->createMock(EmpresaRepository::class);
        $repo->method('find')->willReturn($empresa);
        $spy->endpoints = [];

        return new class(
            $repo,
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(PseAuthBuilder::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $factory ?? $this->createMock(SeeApiFactory::class),
            $spy,
            $body
        ) extends FiscalCdrRecoveryService {
            private \stdClass $spy;
            private ?string $body;

            public function __construct($a, $b, $c, $d, $e, $f, $g, $h, \stdClass $spy, ?string $body)
            {
                parent::__construct($a, $b, $c, $d, $e, $f, $g, $h);
                $this->spy = $spy;
                $this->body = $body;
            }

            protected function pseHttpGet(string $endpoint, array $headers): array
            {
                $this->spy->endpoints[] = $endpoint;

                return [200, (string) $this->body];
            }
        };
    }

    public function testProductionPseGuideIsConsultedAtPseWithRucTipoSerieNumero(): void
    {
        $factory = $this->createMock(SeeApiFactory::class);
        $factory->expects(self::never())->method('build');
        $spy = new \stdClass();
        $svc = $this->service($this->empresa('pse'), (string) json_encode(['isSuccess' => true, 'estado' => 200, 'mensaje' => 'sin cdr']), $factory, $spy);

        $doc = $this->guide('pse', true);
        $res = $svc->recover($doc);

        self::assertSame(['https://pse.example.test/api/cpe/consultar/' . self::RUC . '-09-T002-66'], $spy->endpoints);
        self::assertFalse($res['applied']);
        self::assertTrue($res['queried']);
        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus(), 'sin CDR real no cambia el estado');
        $saved = json_decode((string) $doc->getPseResponseJson(), true);
        self::assertSame('pse', $saved['last_consult']['via']);
    }

    public function testFilenameBaseNeverHasEmptyRuc(): void
    {
        self::assertSame(self::RUC . '-09-T002-66', CdrNormalizer::filenameBaseFromDocument($this->guide('pse', true)));
        self::assertSame(self::RUC . '-09-T002-66', CdrNormalizer::filenameBaseFromDocument($this->guide('pse', false)));
        $noRuc = $this->guide();
        $noRuc->setSnapshotJson('{}');
        self::assertSame(self::RUC . '-09-T002-66', CdrNormalizer::filenameBaseFromDocument($noRuc, self::RUC));
        $v = $this->guide();
        $v->setDocumentType('31');
        $v->setSeries('V001');
        $v->setNumber('1');
        self::assertSame(self::RUC . '-31-V001-1', CdrNormalizer::filenameBaseFromDocument($v));
    }

    public function testPseGuideCdrIsAppliedAndClassified(): void
    {
        $body = (string) json_encode(['isSuccess' => true, 'estado' => 200, 'cdr' => base64_encode(self::CDR_XML)]);
        $svc = $this->service($this->empresa('pse'), $body, null, new \stdClass());

        $doc = $this->guide();
        $res = $svc->recover($doc);

        self::assertTrue($res['applied']);
        self::assertTrue($res['accepted']);
        self::assertSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
        self::assertSame('0', $doc->getSunatCode());
        self::assertNull($doc->getErrorType());
    }

    public function testRestGuideStillUsesTicketChannelAndNeverCallsPse(): void
    {
        $api = $this->createMock(Api::class);
        $api->expects(self::once())->method('getStatus')->with('TICKET-1')->willReturn(new StatusResult());
        $factory = $this->createMock(SeeApiFactory::class);
        $factory->expects(self::once())->method('build')->with(self::RUC)->willReturn($api);
        $spy = new \stdClass();
        $svc = $this->service($this->empresa('sunat_direct'), null, $factory, $spy);

        $doc = $this->guide('sunat_direct');
        $doc->setTicket('TICKET-1');
        $res = $svc->recover($doc);

        self::assertSame([], $spy->endpoints);
        self::assertStringContainsString('SUNAT GRE API', $res['detail']);
    }

    public function testBetaPseEmpresaGuideStaysOnRestTicketChannel(): void
    {
        // En pruebas las guías van por NubeFact (REST) aunque la empresa sea PSE: no se toca ese flujo.
        $spy = new \stdClass();
        $svc = $this->service($this->empresa('pse', 'beta'), null, null, $spy);
        $doc = $this->guide('pse');
        $res = $svc->recover($doc);

        self::assertSame([], $spy->endpoints);
        self::assertFalse($res['queried'], 'sin ticket no se consultó a nadie');
    }

    // ---- ciclo automático ----------------------------------------------------------------------------

    private function processor(FiscalQueueService $queue, FiscalCdrRecoveryService $recovery, FiscalDocument $doc): FiscalCdrConsultProcessor
    {
        $repo = $this->createMock(FiscalDocumentRepository::class);
        $repo->method('findOneBy')->willReturn($doc);

        return new FiscalCdrConsultProcessor(
            $repo,
            $recovery,
            $queue,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function recoveryReturning(bool $queried): FiscalCdrRecoveryService
    {
        $recovery = $this->createMock(FiscalCdrRecoveryService::class);
        $recovery->method('recover')->willReturn([
            'found' => false, 'applied' => false, 'accepted' => false, 'status' => 'sent',
            'sunat_code' => null, 'sunat_message' => null, 'message' => 'sin CDR', 'queried' => $queried,
        ]);

        return $recovery;
    }

    public function testPseGuideKeepsPollingWithGrowingDelayPastTheRestWindow(): void
    {
        $doc = $this->guide('pse');
        $doc->setStatus(FiscalDocument::STATUS_SENT);
        $doc->setErrorType(null);
        $queue = $this->createMock(FiscalQueueService::class);
        $calls = [];
        $queue->method('scheduleCdrConsultRetry')->willReturnCallback(function (string $u, int $attempt, int $delay) use (&$calls): void {
            $calls[$attempt] = $delay;
        });

        $p = $this->processor($queue, $this->recoveryReturning(true), $doc);
        foreach ([1, 2, 3, 7, 8, 11] as $attempt) {
            $p->processByUuid($doc->getDocumentUuid(), $attempt, null, true);
        }

        self::assertSame(60, $calls[2]);
        self::assertSame(120, $calls[3]);
        self::assertSame(240, $calls[4]);
        self::assertSame(3600, $calls[8]);
        self::assertSame(3600, $calls[9], 'el intento 8 (fin de la ventana REST) sigue consultando por PSE');
        self::assertSame(3600, $calls[12]);
        self::assertSame(FiscalDocument::STATUS_SENT, $doc->getStatus());
    }

    public function testPseGuideBecomesManualOnlyOnlyAfterTheLongWindow(): void
    {
        $doc = $this->guide('pse');
        $doc->setStatus(FiscalDocument::STATUS_SENT);
        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects(self::never())->method('scheduleCdrConsultRetry');

        $this->processor($queue, $this->recoveryReturning(true), $doc)->processByUuid($doc->getDocumentUuid(), 12, null, true);

        self::assertSame(FiscalDocument::STATUS_ERROR, $doc->getStatus());
        self::assertSame('manual_only', $doc->getErrorType());
        self::assertStringContainsString('12 consultas automáticas (~6 h)', (string) $doc->getSunatMessage());
    }

    public function testRestGuideKeepsTheShortWindow(): void
    {
        $doc = $this->guide('sunat_direct');
        $doc->setStatus(FiscalDocument::STATUS_SENT);
        $queue = $this->createMock(FiscalQueueService::class);
        $queue->expects(self::never())->method('scheduleCdrConsultRetry');

        $this->processor($queue, $this->recoveryReturning(true), $doc)->processByUuid($doc->getDocumentUuid(), 8, null, true);

        self::assertSame('manual_only', $doc->getErrorType());
        self::assertStringContainsString('8 consultas automáticas (~50 min)', (string) $doc->getSunatMessage());
    }

    public function testExhaustedWithoutAnyRealQueryDoesNotClaimTheProviderDidNotConfirm(): void
    {
        $doc = $this->guide('pse');
        $doc->setStatus(FiscalDocument::STATUS_SENT);

        $this->processor($this->createMock(FiscalQueueService::class), $this->recoveryReturning(false), $doc)
            ->processByUuid($doc->getDocumentUuid(), 12, null, true);

        self::assertStringContainsString('no se pudo consultar', (string) $doc->getSunatMessage());
    }

    // ---- conteo de intentos, ticket y reenvíos ------------------------------------------------------

    private function emitProcessor(?EntityManagerInterface $em = null, ?FiscalProviderResolver $resolver = null): FiscalEmitProcessor
    {
        return new FiscalEmitProcessor(
            $em ?? $this->createMock(EntityManagerInterface::class),
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(EmpresaRepository::class),
            $this->createMock(SerializerInterface::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $resolver ?? $this->createMock(FiscalProviderResolver::class),
            $this->createMock(FiscalPdfService::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testSentPendingCdrAdvancesRetryCountSoResendsAreNotHiddenAsAttempt1(): void
    {
        $doc = $this->guide('pse');
        $doc->setStatus(FiscalDocument::STATUS_QUEUED);
        $result = new FiscalEmitResult();
        $result->sunatCode = '200';
        $result->sentPendingCdr = true;

        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'handleSentPendingCdr');
        $ref->setAccessible(true);
        $p = $this->emitProcessor();
        $ref->invoke($p, $doc, $result, 'validapse', $doc->getRetryCount() + 1, microtime(true));
        self::assertSame(1, $doc->getRetryCount());
        $ref->invoke($p, $doc, $result, 'validapse', $doc->getRetryCount() + 1, microtime(true));
        self::assertSame(2, $doc->getRetryCount());
    }

    public function testTicketOnlyResultKeepsTheOriginalTicket(): void
    {
        $doc = $this->guide('sunat_direct');
        $doc->setStatus(FiscalDocument::STATUS_QUEUED);
        $doc->setTicket('TICKET-ORIGINAL');
        $result = new FiscalEmitResult();
        $result->ticket = 'TICKET-REENVIO';

        $ref = new ReflectionMethod(FiscalEmitProcessor::class, 'handleTicketOnlyResult');
        $ref->setAccessible(true);
        $handled = $ref->invoke(
            $this->emitProcessor(),
            $doc,
            \Greenter\Model\Despatch\Despatch::class,
            $this->createMock(DocumentInterface::class),
            $result,
            'sunat_direct',
            1,
            microtime(true),
            $this->empresa('sunat_direct')
        );

        self::assertTrue($handled);
        self::assertSame('TICKET-ORIGINAL', $doc->getTicket());
        self::assertSame(1, $doc->getRetryCount());
        self::assertSame(FiscalDocument::STATUS_SENT, $doc->getStatus());
    }

    public function testGuideAlreadySentIsNotResentWithoutForce(): void
    {
        $doc = $this->guide('pse');
        $doc->setStatus(FiscalDocument::STATUS_SENT);
        $resolver = $this->createMock(FiscalProviderResolver::class);
        $resolver->expects(self::never())->method('emit');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->emitProcessor($em, $resolver)->process($doc);

        self::assertSame(FiscalDocument::STATUS_SENT, $doc->getStatus(), 'ni siquiera pasa a SENDING');
    }

    public function testAwaitingVerdictGuideRule(): void
    {
        $sent = $this->guide();
        $sent->setStatus(FiscalDocument::STATUS_SENT);
        self::assertTrue(FiscalEmitProcessor::isAwaitingVerdictGuide($sent));
        self::assertTrue(FiscalEmitProcessor::isAwaitingVerdictGuide($this->guide()), 'error/manual_only tras envío real');

        $neverSent = $this->guide();
        $neverSent->setSentAt(null);
        self::assertFalse(FiscalEmitProcessor::isAwaitingVerdictGuide($neverSent));

        $transient = $this->guide();
        $transient->setErrorType('transient');
        self::assertFalse(FiscalEmitProcessor::isAwaitingVerdictGuide($transient), 'un fallo transitorio sí se reintenta');

        $invoice = $this->guide();
        $invoice->setDocumentType('01');
        $invoice->setStatus(FiscalDocument::STATUS_SENT);
        self::assertFalse(FiscalEmitProcessor::isAwaitingVerdictGuide($invoice), 'factura/boleta no cambian');
    }

    public function testNormalSendRetryIsBlockedForSentGuideButNotForSentInvoice(): void
    {
        $bulk = new FiscalBulkActionService(
            $this->createMock(FiscalDocumentRepository::class),
            $this->createMock(FiscalQueueService::class),
            new FiscalCustomerEmailNormalizer()
        );
        $sent = $this->guide();
        $sent->setStatus(FiscalDocument::STATUS_SENT);
        $sent->setErrorType(null);
        self::assertTrue($bulk->isBlockedForNormalAction($sent));

        $invoice = $this->guide();
        $invoice->setDocumentType('01');
        $invoice->setStatus(FiscalDocument::STATUS_SENT);
        $invoice->setErrorType(null);
        self::assertFalse($bulk->isBlockedForNormalAction($invoice));
    }
}
