<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Service\Fiscal\Provider\FiscalErrorBucketClassifier;
use App\Service\Fiscal\Provider\PseAuthBuilder;
use App\Service\Fiscal\Provider\ValidaPseProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Prueba la clasificación de ValidaPseProvider::buildEmitResult() (privado) vía Reflection —
 * es lógica pura (sin red/DB), así que se prueba directamente en vez de mockear curl.
 *
 * Casos basados en la evidencia real de producción (docs/PLAN-MEJORAS-VALIDACION-Y-REINTENTOS.md
 * secciones 12.1 y 14) y en las reglas 2-4 de la sección 14 (CDR real ≠ aceptado, sunat_code=0
 * nunca se fabrica, isSuccess:true no implica alreadySubmitted).
 */
class ValidaPseProviderBuildEmitResultTest extends TestCase
{
    private function invoke(array $pseResp, bool $isSuccess): object
    {
        $provider = new ValidaPseProvider($this->createMock(PseAuthBuilder::class));
        $method = new ReflectionMethod(ValidaPseProvider::class, 'buildEmitResult');
        $method->setAccessible(true);

        return $method->invoke($provider, '<xml/>', $pseResp, $isSuccess, '20123456789-01-F001-1');
    }

    private function cdrXml(string $code, string $description, array $notes = []): string
    {
        $notesXml = implode('', array_map(static fn (string $n): string => "<cbc:Note>{$n}</cbc:Note>", $notes));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ApplicationResponse xmlns="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" '
            . 'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" '
            . 'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . $notesXml
            . '<cac:DocumentResponse><cac:Response>'
            . "<cbc:ReferenceID>F001-1</cbc:ReferenceID>"
            . "<cbc:ResponseCode>{$code}</cbc:ResponseCode>"
            . "<cbc:Description>{$description}</cbc:Description>"
            . '</cac:Response><cac:DocumentReference><cbc:ID>F001-1</cbc:ID></cac:DocumentReference>'
            . '</cac:DocumentResponse></ApplicationResponse>';
    }

    // --- Regla 2/3: CDR real, nunca se fabrica el código ---

    public function testRealCdrWithCode0IsAccepted(): void
    {
        $xml = $this->cdrXml('0', 'La Factura F001-1 ha sido aceptada');
        $result = $this->invoke(['isSuccess' => true, 'cdr' => base64_encode($xml)], true);

        self::assertTrue($result->success);
        self::assertFalse($result->rejected);
        self::assertFalse($result->observed);
        self::assertSame('0', $result->sunatCode);
        self::assertNull($result->errorType);
    }

    public function testRealCdrWithRejectionCodeIsRejectedNeverAccepted(): void
    {
        // Caso pedido explícitamente por el usuario: PSE puede devolver isSuccess:true en su
        // propia operación mientras el CDR real, ya procesado por SUNAT, indica rechazo.
        $xml = $this->cdrXml('3105', 'El XML debe contener al menos un tributo por linea de afectacion por IGV');
        $result = $this->invoke(['isSuccess' => true, 'cdr' => base64_encode($xml)], true);

        self::assertFalse($result->success);
        self::assertTrue($result->rejected);
        self::assertSame('business', $result->errorType);
        self::assertSame('3105', $result->sunatCode);
        self::assertNotSame('0', $result->sunatCode, 'sunat_code nunca debe fabricarse como 0 cuando el CDR real dice otra cosa');
    }

    public function testRealCdrWithObservedCodeIsObserved(): void
    {
        $xml = $this->cdrXml('4000', 'Aceptado con observaciones', ['Nota de observación']);
        $result = $this->invoke(['isSuccess' => true, 'cdr' => base64_encode($xml)], true);

        self::assertTrue($result->success);
        self::assertTrue($result->observed);
        self::assertFalse($result->rejected);
        self::assertSame('4000', $result->sunatCode);
    }

    public function testRealCdrWithSystemExceptionCodeIsTransientNotAccepted(): void
    {
        // Código 0100-1999 dentro de un CDR real: excepción de sistema, transitorio — nunca
        // aceptado ni rechazo de negocio.
        $xml = $this->cdrXml('0160', 'SUNAT no disponible temporalmente');
        $result = $this->invoke(['isSuccess' => true, 'cdr' => base64_encode($xml)], true);

        self::assertFalse($result->success);
        self::assertFalse($result->rejected);
        self::assertSame('transient', $result->errorType);
    }

    // --- Regla 4: isSuccess:true sin CDR NO es alreadySubmitted, ni accepted ---

    public function testIsSuccessTrueWithoutCdrNeverBecomesAccepted(): void
    {
        $result = $this->invoke(['isSuccess' => true, 'estado' => 200, 'mensaje' => 'Comprobante recibido'], true);

        self::assertFalse($result->success, 'success debe quedar false — no hay CDR real que lo confirme');
        self::assertFalse($result->rejected);
        self::assertFalse($result->alreadySubmitted, 'isSuccess:true no implica alreadySubmitted (regla 4)');
        self::assertTrue($result->sentPendingCdr);
        self::assertNotSame('0', $result->sunatCode, 'no debe fabricarse sunat_code=0 sin CDR real');
    }

    public function testIsSuccessTrueWithoutCdrUsesEstadoNotFabricatedCode(): void
    {
        $result = $this->invoke(['isSuccess' => true, 'estado' => 200], true);

        self::assertSame('200', $result->sunatCode, 'sunat_code debe reflejar el estado real de PSE, no un valor inventado');
    }

    // --- Código 1033 embebido: ya informado (evidencia concreta, no isSuccess) ---

    public function testCode1033IsAlreadySubmittedNotAccepted(): void
    {
        $result = $this->invoke([
            'isSuccess' => false,
            'estado' => 501,
            'code' => '1033',
            'errores' => 'El comprobante fue registrado previamente con otros datos - Detalle: informado anteriormente',
        ], false);

        self::assertTrue($result->alreadySubmitted);
        self::assertFalse($result->success);
        self::assertFalse($result->rejected);
        self::assertSame('1033', $result->sunatCode);
    }

    // --- Bucket de FiscalErrorBucketClassifier vía código embebido ---

    public function testCode1032IsBusinessTerminal(): void
    {
        $result = $this->invoke([
            'isSuccess' => false,
            'estado' => 501,
            'code' => '1032',
            'errores' => '1032 - El comprobante ya esta informado y se encuentra con estado anulado o rechazado',
        ], false);

        self::assertTrue($result->rejected);
        self::assertSame('business', $result->errorType);
        self::assertSame('1032', $result->sunatCode);
    }

    public function testCode0111IsManualOnly(): void
    {
        $result = $this->invoke([
            'isSuccess' => false,
            'estado' => 501,
            'code' => '0111',
            'errores' => 'No tiene el perfil para enviar comprobantes electronicos - Detalle: Rejected by policy.',
        ], false);

        self::assertFalse($result->rejected);
        self::assertSame('manual_only', $result->errorType);
    }

    public function testCodes0109And0154AreTransientWithMax5(): void
    {
        // El tope de 5 se aplica en FiscalEmitProcessor::applyFailure() (Fase 4) — aquí solo
        // se prueba que ambos códigos quedan clasificados como 'transient', el bucket que
        // recibe ese tope.
        $result0109 = $this->invoke([
            'isSuccess' => false, 'estado' => 501, 'code' => '0109',
            'errores' => 'El sistema no puede responder su solicitud. (El servicio de autenticación no está disponible)',
        ], false);
        $result0154 = $this->invoke([
            'isSuccess' => false, 'estado' => 501, 'code' => '0154',
            'errores' => 'El RUC del archivo no corresponde al RUC del usuario',
        ], false);

        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, $result0109->errorType);
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, $result0154->errorType);
    }

    public function testUnidentifiableCodeIsManualOnlyWithoutInventingSunatCode(): void
    {
        // Código con prefijo numérico < 2000 (para no coincidir por casualidad con el rango
        // de negocio de SunatCdrClassifier::isBusinessRejectionCode(), que castea a int).
        $result = $this->invoke([
            'isSuccess' => false,
            'estado' => 501,
            'code' => '0199-CODIGO-NUNCA-VISTO',
            'errores' => 'Algo que nunca hemos visto antes',
        ], false);

        self::assertSame('manual_only', $result->errorType);
        self::assertSame('0199-CODIGO-NUNCA-VISTO', $result->sunatCode, 'debe reflejar el código real recibido, no inventar uno');
    }

    public function testEmbeddedCodeIsUsedNotEstadoForDuplicateDetection(): void
    {
        // Antes del fix, se pasaba `estado` (501) en vez de `code` a SunatDuplicateClassifier
        // — nunca coincidía con '1033'. Prueba de regresión.
        $result = $this->invoke([
            'isSuccess' => false,
            'estado' => 501, // nunca debe ser tratado como código SUNAT
            'code' => '1033',
            'errores' => 'registrado previamente',
        ], false);

        self::assertTrue($result->alreadySubmitted);
    }
}
