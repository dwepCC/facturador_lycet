<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Service\Fiscal\Provider\FiscalErrorBucketClassifier;
use App\Service\Fiscal\Provider\PseAppProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Prueba PseAppProvider::classifyResponse() (privado, sin red) vía Reflection — mismo
 * patrón que ValidaPseProviderBuildEmitResultTest. Casos según docs/integraciones/
 * tukifac-integracion-pse.md §3.3 (repo pse-app): el contrato de este PSE ya entrega un
 * `status` clasificado (nunca ISO isSuccess crudo), así que no hace falta reparsear CDR acá.
 */
class PseAppProviderClassifyResponseTest extends TestCase
{
    private function invoke(int $httpCode, array $resp): object
    {
        $provider = new PseAppProvider();
        $method = new ReflectionMethod(PseAppProvider::class, 'classifyResponse');
        $method->setAccessible(true);

        return $method->invoke($provider, $httpCode, $resp);
    }

    public function testAcceptedIsSuccessWithoutObservation(): void
    {
        $result = $this->invoke(200, [
            'status' => 'ACCEPTED',
            'sunat' => ['codigo' => '0', 'descripcion' => 'La Factura F001-1 ha sido aceptada'],
        ]);

        self::assertTrue($result->success);
        self::assertFalse($result->rejected);
        self::assertFalse($result->observed);
        self::assertSame('0', $result->sunatCode);
        self::assertNull($result->errorType);
    }

    public function testAcceptedWithObservationIsSuccessAndObserved(): void
    {
        $result = $this->invoke(200, [
            'status' => 'ACCEPTED_WITH_OBSERVATION',
            'sunat' => ['codigo' => '4000', 'descripcion' => 'Aceptado con observaciones'],
        ]);

        self::assertTrue($result->success);
        self::assertTrue($result->observed);
        self::assertFalse($result->rejected);
    }

    public function testRejectedIsBusinessBucketNotSuccess(): void
    {
        $result = $this->invoke(200, [
            'status' => 'REJECTED',
            'sunat' => ['codigo' => '2335', 'descripcion' => 'El documento fue rechazado'],
        ]);

        self::assertFalse($result->success);
        self::assertTrue($result->rejected);
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, $result->errorType);
    }

    public function testNonTerminalStatusIsTransient(): void
    {
        foreach (['SENDING', 'SENT_TICKET_PENDING', 'UNKNOWN'] as $status) {
            $result = $this->invoke(202, ['status' => $status, 'mensaje' => 'El documento está en procesamiento.']);

            self::assertFalse($result->success);
            self::assertFalse($result->rejected);
            self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, $result->errorType);
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function errorCodeBucketProvider(): array
    {
        return [
            'INVALID_XML → manual_only' => ['INVALID_XML', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'UNSUPPORTED_DOCUMENT_TYPE → manual_only' => ['UNSUPPORTED_DOCUMENT_TYPE', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'RUC_MISMATCH → manual_only' => ['RUC_MISMATCH', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'VALIDATION_ERROR → manual_only' => ['VALIDATION_ERROR', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'EMISOR_NOT_AFFILIATED → manual_only' => ['EMISOR_NOT_AFFILIATED', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'EMISOR_INACTIVE → manual_only' => ['EMISOR_INACTIVE', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'INSUFFICIENT_CREDIT → manual_only' => ['INSUFFICIENT_CREDIT', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'DUPLICATE_DOCUMENT → manual_only' => ['DUPLICATE_DOCUMENT', FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY],
            'IDEMPOTENCY_IN_PROGRESS → transient' => ['IDEMPOTENCY_IN_PROGRESS', FiscalErrorBucketClassifier::BUCKET_TRANSIENT],
            'código desconocido → transient (conservador, reintenta antes de dar por manual)' => ['ALGO_NUEVO_NO_VISTO', FiscalErrorBucketClassifier::BUCKET_TRANSIENT],
        ];
    }

    /**
     * @dataProvider errorCodeBucketProvider
     */
    public function testHttpErrorMapsToExpectedBucket(string $codigo, string $expectedBucket): void
    {
        $result = $this->invoke(422, [
            'error' => ['codigo' => $codigo, 'mensaje' => 'mensaje de prueba'],
            'correlation_id' => '01J...',
        ]);

        self::assertFalse($result->success);
        self::assertSame($expectedBucket, $result->errorType);
        self::assertSame($expectedBucket === FiscalErrorBucketClassifier::BUCKET_BUSINESS, $result->rejected);
    }

    public function testPseResponseIsAlwaysCapturedForAudit(): void
    {
        $resp = ['status' => 'ACCEPTED', 'sunat' => ['codigo' => '0', 'descripcion' => 'ok']];
        $result = $this->invoke(200, $resp);

        self::assertSame($resp, $result->pseResponse);
    }
}
