<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;

/**
 * Resultado normalizado de emisión fiscal (SUNAT directo o PSE).
 */
class FiscalEmitResult
{
    public bool $success = false;
    public ?string $sunatCode = null;
    public ?string $sunatMessage = null;
    public ?string $pseMessage = null;
    public ?string $hash = null;
    public ?string $ticket = null;
    public ?string $unsignedXml = null;
    public ?string $signedXml = null;
    public ?string $cdrZip = null;
    public ?string $pdf = null;
    /** @var array<string, mixed> */
    public array $pseResponse = [];
    /** @var array<string, mixed> */
    public array $sunatResponse = [];
    /** @var array<int, string> Notas del CDR (cbc:Note). */
    public array $cdrNotes = [];
    public bool $rejected = false;
    /** Documento válido ante SUNAT pero con observaciones (código >= 4000 o notas CDR). */
    public bool $observed = false;
    /**
     * SUNAT/PSE indica que el comprobante YA fue informado/registrado anteriormente, en un
     * intento ANTERIOR (código 1033 y variantes, vía SunatDuplicateClassifier). No se debe
     * reenviar: hay que consultar el CDR. No confundir con $sentPendingCdr (este mismo envío).
     */
    public bool $alreadySubmitted = false;
    /**
     * PSE confirmó éxito de SU PROPIA operación (isSuccess:true) en ESTE envío, pero la
     * respuesta no trae un CDR real embebido. No es evidencia de un envío anterior
     * ($alreadySubmitted) ni de aceptación por SUNAT — es este mismo intento, aceptado por
     * el PSE, con el CDR real todavía pendiente. No se reenvía, no se acepta sin CDR real:
     * queda STATUS_SENT hasta consulta manual (ver FiscalEmitProcessor::handleSentPendingCdr()).
     */
    public bool $sentPendingCdr = false;
    /**
     * Tipo de fallo cuando no hay veredicto de aceptación:
     *  - 'business'     → rechazo definitivo de SUNAT/PSE (código 2000+, o casos puntuales <2000
     *                      como 1032). Terminal, no se reintenta.
     *  - 'transient'     → falla técnica/temporal (SUNAT sin CDR, excepción de sistema 0100-1999,
     *                      red, PSE 0109/0100/0154/Server Error). Reintentable hasta 5 intentos
     *                      (FiscalErrorBucketClassifier), luego pasa a manual.
     *  - 'manual_only'   → SUNAT/PSE respondió pero el motivo no se resuelve reintentando solo
     *                      (perfil SOL sin habilitar código 0111, credenciales, nombre de archivo,
     *                      fuera de fecha, o no identificado). Reenviable solo por acción humana.
     *  - null            → aceptado / observado (sin fallo).
     */
    public ?string $errorType = null;

    public function isAccepted(): bool
    {
        return $this->success && !$this->rejected && !$this->observed;
    }

    public function isObserved(): bool
    {
        return $this->observed;
    }

    public function hasSunatOutcome(): bool
    {
        return $this->isAccepted() || $this->isObserved() || $this->rejected;
    }
}
