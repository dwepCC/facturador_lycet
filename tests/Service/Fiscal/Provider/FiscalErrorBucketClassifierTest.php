<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal\Provider;

use App\Service\Fiscal\Provider\FiscalErrorBucketClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Casos basados en la evidencia real de producción documentada en
 * docs/PLAN-MEJORAS-VALIDACION-Y-REINTENTOS.md secciones 12.1 (PSE) y 13.11.1 (canal directo).
 */
class FiscalErrorBucketClassifierTest extends TestCase
{
    public function testCode1032IsBusinessTerminal(): void
    {
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_BUSINESS,
            FiscalErrorBucketClassifier::classify(
                '1032',
                '1032 - El comprobante ya esta informado y se encuentra con estado anulado o rechazado'
            )
        );
    }

    public function testBusinessMessageNeedleWithoutCleanCode(): void
    {
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_BUSINESS,
            FiscalErrorBucketClassifier::classify(null, 'Detalle: comprobante registrado con estado ANULADO')
        );
    }

    /**
     * Casos reales del canal directo (sin código estructurado, solo mensaje) revisados con el
     * usuario antes de aplicar la reclasificación histórica — sin estos needles caían en
     * 'transient' por defecto aunque fueran rechazos de negocio genuinos.
     *
     * @dataProvider realDirectChannelBusinessMessages
     */
    public function testRealDirectChannelBusinessMessagesAreBusinessNotTransient(string $message): void
    {
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, FiscalErrorBucketClassifier::classify(null, $message));
    }

    public function realDirectChannelBusinessMessages(): array
    {
        return [
            'XML no contiene el tag (usuario)' => ['El XML no contiene el tag o no existe información del usuario - Detalle: xxx'],
            'XML no contiene tag (cantidad, doriconta F001-76)' => ['El XML no contiene tag de la cantidad del concepto por linea. - Detalle: xxx.xxx.xxx value=\'ticket: 1789836587644 error: Error en la linea: 1 ConceptoItem 3006: 3135 (nodo: "/" valor: "")\''],
            'debe consignarse (industrialrafaz F001-126)' => ['Si el tipo de transaccion es al Credito debe consignarse el Monto neto pendiente de pago - Detalle: xxx.xxx.xxx value=\'ticket: 1789837119371 error: INFO: 3251 (nodo: "/" valor: "")\''],
            'fecha no puede ser anterior' => ['Fecha del pago único o de las cuotas no puede ser anterior a la fecha de emisión'],
            'difiere de los importes' => ['El valor de venta por ítem difiere de los importes consignados en el comprobante'],
        ];
    }

    public function testCode0111IsManualOnlyBothChannels(): void
    {
        // PSE (JSON): "No tiene el perfil para enviar comprobantes electronicos - Rejected by policy."
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY,
            FiscalErrorBucketClassifier::classify('0111', 'No tiene el perfil para enviar comprobantes electronicos - Detalle: Rejected by policy.')
        );

        // Canal directo (fault SOAP): mismo mensaje de SUNAT, sin `code` limpio a veces.
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY,
            FiscalErrorBucketClassifier::classify(null, 'No tiene el perfil para enviar comprobantes electronicos')
        );
    }

    public function testCode0154IsTransientDespiteBeingAConfigIssue(): void
    {
        // La corrección real (afiliación PSE/credenciales) es manual, pero el reenvío del
        // documento una vez corregida esa configuración debe poder ser automático (12.3.1).
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_TRANSIENT,
            FiscalErrorBucketClassifier::classify('0154', 'El RUC del archivo no corresponde al RUC del usuario o el proveedor no esta autorizado a enviar comprobantes del contribuyente')
        );
    }

    public function testCodes0109And0100AreTransient(): void
    {
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_TRANSIENT,
            FiscalErrorBucketClassifier::classify('0109', 'El sistema no puede responder su solicitud. (El servicio de autenticación no está disponible)')
        );
        self::assertSame(
            FiscalErrorBucketClassifier::BUCKET_TRANSIENT,
            FiscalErrorBucketClassifier::classify('0100', 'El sistema no puede responder su solicitud. Intente nuevamente o comuníquese con su Administrador')
        );
    }

    public function testBusinessRangeAboveOrEqual2000(): void
    {
        // 3105: "El XML debe contener al menos un tributo por línea de afectación por IGV".
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, FiscalErrorBucketClassifier::classify('3105', null));
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, FiscalErrorBucketClassifier::classify('2001', null));
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, FiscalErrorBucketClassifier::classify('3999', null));
    }

    public function testSystemExceptionRangeIsNotBusiness(): void
    {
        // 0100-1999 (excepto los needles/códigos de negocio puntuales ya cubiertos arriba)
        // no deben caer en BUCKET_BUSINESS por el rango — un código no reconocido en ese
        // rango cae en manual_only (no inventar clasificación), no en business ni transient.
        self::assertNotSame(FiscalErrorBucketClassifier::BUCKET_BUSINESS, FiscalErrorBucketClassifier::classify('0160', 'Sunat no disponible, intente nuevamente'));
    }

    public function testUnidentifiedCodesFallBackToManualOnlyWithoutInventingBusinessCode(): void
    {
        // 0151 (bug de nombre de ZIP), 1079 (fuera de fecha), HTTP (Bad Request genérico) —
        // ninguno se inventa como transitorio ni como rechazo de negocio.
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY, FiscalErrorBucketClassifier::classify('0151', 'El nombre del archivo ZIP es incorrecto'));
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY, FiscalErrorBucketClassifier::classify('1079', 'Solo puede enviar el comprobante en un resumen diario'));
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY, FiscalErrorBucketClassifier::classify('HTTP', 'Bad Request'));
    }

    public function testEmptyCodeWithoutBusinessOrManualNeedleIsTransient(): void
    {
        // "Server Error" de PSE: sin `code` en absoluto — infraestructura del proveedor.
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, FiscalErrorBucketClassifier::classify(null, 'Server Error'));
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, FiscalErrorBucketClassifier::classify('', null));
    }

    public function testNullMessageDoesNotCauseErrors(): void
    {
        self::assertSame(FiscalErrorBucketClassifier::BUCKET_TRANSIENT, FiscalErrorBucketClassifier::classify(null, null));
    }
}
