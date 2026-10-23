<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\Fiscal\Provider\PseAuthBuilder;
use App\Service\Fiscal\Provider\SunatValidityClassifier;
use App\Service\SeeApiFactory;
use Doctrine\ORM\EntityManagerInterface;
use Greenter\Api;
use Greenter\Model\Response\Error;
use Greenter\Model\Response\StatusResult;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Fase 5 (sección 14, reglas 2-4 del plan): un veredicto sin CDR real (isSuccess, texto
 * "aceptado", etc.) ya NO cambia `status` automáticamente en recover() — solo se persiste para
 * que una persona decida con la acción explícita acceptWithoutCdr().
 */
class FiscalCdrRecoveryServiceTest extends TestCase
{
    private function makeService(): FiscalCdrRecoveryService
    {
        return new FiscalCdrRecoveryService(
            $this->createMock(EmpresaRepository::class),
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(PseAuthBuilder::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(SeeApiFactory::class)
        );
    }

    /** Servicio con empresa SUNAT directo en producción y un API GRE simulado que responde $status. */
    private function makeGuideService(?StatusResult $status): FiscalCdrRecoveryService
    {
        $empresa = new Empresa();
        $empresa->setRuc('20546402933');
        $empresa->setAmbiente('produccion');
        $empresa->setSendMode('sunat_direct');
        $repo = $this->createMock(EmpresaRepository::class);
        $repo->method('find')->willReturn($empresa);

        $factory = $this->createMock(SeeApiFactory::class);
        if ($status === null) {
            $factory->expects(self::never())->method('build');
        } else {
            $api = $this->createMock(Api::class);
            $api->expects(self::once())->method('getStatus')->with('TICKET-1')->willReturn($status);
            $factory->expects(self::once())->method('build')->with('20546402933')->willReturn($api);
        }
        $em = $this->createMock(EntityManagerInterface::class);

        return new FiscalCdrRecoveryService(
            $repo,
            $this->createMock(FiscalStorageService::class),
            $this->createMock(FiscalWebhookService::class),
            $this->createMock(FiscalQueueService::class),
            $this->createMock(PseAuthBuilder::class),
            $em,
            $this->createMock(LoggerInterface::class),
            $factory
        );
    }

    private function makeGuide(?string $ticket): FiscalDocument
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('99999999-9999-9999-9999-999999999999');
        $doc->setDocumentType('09');
        $doc->setSeries('T001');
        $doc->setNumber('342');
        $doc->setStatus(FiscalDocument::STATUS_REJECTED);
        $doc->setSnapshotJson((string) json_encode(['document' => ['company' => ['ruc' => '20546402933']]]));
        if ($ticket !== null) {
            $doc->setTicket($ticket);
        }

        return $doc;
    }

    public function testGuideIsConsultedByTicketThroughGreApiNotTheInvoiceSoapService(): void
    {
        $error = new Error();
        $error->setCode('1033');
        $error->setMessage('El comprobante fue registrado previamente con otros datos');
        $status = new StatusResult();
        $status->setSuccess(false);
        $status->setError($error);

        $doc = $this->makeGuide('TICKET-1');
        $res = $this->makeGuideService($status)->recover($doc);

        self::assertFalse($res['applied'], 'una consulta sin CDR no cambia el estado');
        self::assertSame(FiscalDocument::STATUS_REJECTED, $doc->getStatus());
        self::assertStringContainsString('SUNAT GRE API', $res['detail']);
        self::assertStringContainsString('1033', $res['message']);
        self::assertStringNotContainsString('NO existe', $res['message'], 'nunca el veredicto "no existe" del servicio de facturas');
        $saved = json_decode((string) $doc->getPseResponseJson(), true);
        self::assertSame('sunat_gre', $saved['last_consult']['via']);
        self::assertSame(SunatValidityClassifier::UNKNOWN, $saved['last_consult']['verdict']);
    }

    public function testGuideWithoutTicketExplainsAndDoesNotCallSunat(): void
    {
        $doc = $this->makeGuide(null);
        $res = $this->makeGuideService(null)->recover($doc);

        self::assertFalse($res['applied']);
        self::assertStringContainsString('ticket', strtolower($res['message']));
    }

    public function testGuideInProcessIsReportedAsInProcess(): void
    {
        $error = new Error();
        $error->setCode('98');
        $error->setMessage('En proceso');
        $status = new StatusResult();
        $status->setSuccess(false);
        $status->setError($error);

        $doc = $this->makeGuide('TICKET-1');
        $res = $this->makeGuideService($status)->recover($doc);

        self::assertStringContainsString('EN PROCESO', $res['message']);
    }

    private function invokePrivate(object $service, string $method, array $args)
    {
        $ref = new ReflectionMethod(FiscalCdrRecoveryService::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($service, ...$args);
    }

    public function testPersistConsultDetailNeverChangesStatusOrSunatCode(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('88888888-8888-8888-8888-888888888888');
        $doc->setStatus(FiscalDocument::STATUS_SENT);
        $doc->setSunatCode(null);

        $service = $this->makeService();
        $this->invokePrivate($service, 'persistConsultDetail', [
            $doc,
            'pse',
            ['provider_detail' => 'PSE · HTTP 200 · isSuccess=true', 'raw_response' => ['isSuccess' => true, 'estado' => 200]],
            SunatValidityClassifier::ACCEPTED,
            '200',
            'El comprobante existe y esta aceptado',
        ]);

        // Regla 2-4: persistir la respuesta cruda de una consulta NUNCA debe tocar status,
        // sunat_code ni acceptedAt — eso solo lo hace un CDR real o la decisión manual.
        self::assertSame(FiscalDocument::STATUS_SENT, $doc->getStatus(), 'persistir la consulta no debe cambiar el status');
        self::assertNull($doc->getSunatCode(), 'persistir la consulta no debe fabricar sunat_code');
        self::assertNull($doc->getAcceptedAt());
    }

    public function testPersistConsultDetailAndReadLastConsultRoundTrip(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('99999999-9999-9999-9999-999999999999');

        $service = $this->makeService();
        $this->invokePrivate($service, 'persistConsultDetail', [
            $doc, 'pse',
            ['provider_detail' => 'detalle crudo', 'raw_response' => ['estado' => 200]],
            SunatValidityClassifier::ACCEPTED, '200', 'mensaje real',
        ]);

        $lastConsult = $this->invokePrivate($service, 'readLastConsult', [$doc]);

        self::assertIsArray($lastConsult);
        self::assertSame('pse', $lastConsult['via']);
        self::assertSame(SunatValidityClassifier::ACCEPTED, $lastConsult['verdict']);
        self::assertSame('200', $lastConsult['status_code']);
        self::assertSame(['estado' => 200], $lastConsult['raw_response'], 'la respuesta cruda debe quedar persistida, no solo el texto formateado');
    }

    public function testReadLastConsultReturnsNullWhenNothingPersistedYet(): void
    {
        $doc = new FiscalDocument();
        self::assertNull($this->invokePrivate($this->makeService(), 'readLastConsult', [$doc]));
    }

    public function testApplyValidWithoutCdrNeverFabricatesSunatCodeZero(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        $result = $this->invokePrivate($this->makeService(), 'applyValidWithoutCdr', [$doc, '200', 'El comprobante existe y esta aceptado']);

        self::assertSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
        self::assertSame('200', $doc->getSunatCode(), 'debe conservar el status_code real de la consulta, no inventar 0');
        self::assertNotSame('0', $doc->getSunatCode());
        self::assertStringContainsString('manualmente', (string) $doc->getSunatMessage());
        self::assertTrue($result['accepted']);
    }

    public function testApplyValidWithoutCdrWithNullStatusCodeStaysNullNeverZero(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');

        $this->invokePrivate($this->makeService(), 'applyValidWithoutCdr', [$doc, null, '']);

        self::assertNull($doc->getSunatCode());
    }

    public function testAcceptWithoutCdrFailsWithoutPriorConsult(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('cccccccc-cccc-cccc-cccc-cccccccccccc');

        $result = $this->makeService()->acceptWithoutCdr($doc);

        self::assertFalse($result['ok']);
        self::assertNotSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
    }

    public function testAcceptWithoutCdrFailsWhenLastConsultWasNotAccepted(): void
    {
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('dddddddd-dddd-dddd-dddd-dddddddddddd');

        $service = $this->makeService();
        $this->invokePrivate($service, 'persistConsultDetail', [
            $doc, 'pse', ['provider_detail' => 'x'], SunatValidityClassifier::NOT_FOUND, null, 'no existe',
        ]);

        $result = $service->acceptWithoutCdr($doc);

        self::assertFalse($result['ok']);
        self::assertNotSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
    }

    public function testAcceptWithoutCdrSucceedsAfterAcceptedVerdictPersisted(): void
    {
        // El flujo real: una consulta persiste verdict=ACCEPTED sin CDR (persistConsultDetail,
        // nunca cambia status por sí sola) — LUEGO, como acción separada, acceptWithoutCdr()
        // sí puede aplicar la aceptación, exactamente el diseño de separar consulta y decisión.
        $doc = new FiscalDocument();
        $doc->setDocumentUuid('eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee');

        $service = $this->makeService();
        $this->invokePrivate($service, 'persistConsultDetail', [
            $doc, 'sunat', ['provider_detail' => 'SUNAT dice aceptado'], SunatValidityClassifier::ACCEPTED, '0001', 'El comprobante existe y esta aceptado',
        ]);
        self::assertNotSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus(), 'la consulta sola todavía no debe haber aceptado nada');

        $result = $service->acceptWithoutCdr($doc);

        self::assertTrue($result['ok']);
        self::assertSame(FiscalDocument::STATUS_ACCEPTED, $doc->getStatus());
        self::assertSame('0001', $doc->getSunatCode());
    }
}
