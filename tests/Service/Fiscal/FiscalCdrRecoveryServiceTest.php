<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Repository\EmpresaRepository;
use App\Service\Fiscal\FiscalCdrRecoveryService;
use App\Service\Fiscal\FiscalQueueService;
use App\Service\Fiscal\FiscalStorageService;
use App\Service\Fiscal\FiscalWebhookService;
use App\Service\Fiscal\Provider\PseAuthBuilder;
use App\Service\Fiscal\Provider\SunatValidityClassifier;
use Doctrine\ORM\EntityManagerInterface;
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
            $this->createMock(LoggerInterface::class)
        );
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
