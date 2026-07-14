<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use Greenter\Model\Response\CdrResponse;

/**
 * Clasifica respuesta CDR SUNAT según Greenter (CdrResponse::isAccepted).
 * Código 0 = aceptado; >= 4000 = aceptado con observaciones; otro = rechazado.
 */
final class SunatCdrClassifier
{
    /**
     * @return array{success: bool, rejected: bool, observed: bool, errorType: ?string, code: ?string, message: ?string, notes: array<int, string>}
     */
    public static function fromCdrResponse(?CdrResponse $cdr): array
    {
        if ($cdr === null) {
            // SUNAT contactado pero sin CDR parseable → falla técnica temporal, reintentable.
            return [
                'success' => false,
                'rejected' => false,
                'observed' => false,
                'errorType' => 'transient',
                'code' => null,
                'message' => null,
                'notes' => [],
            ];
        }

        $code = $cdr->getCode() !== null ? (string) $cdr->getCode() : null;
        $message = $cdr->getDescription() !== null ? (string) $cdr->getDescription() : null;
        $notes = self::normalizeNotes($cdr->getNotes());

        if (!$cdr->isAccepted()) {
            // Rangos oficiales SUNAT: 0100-1999 = excepciones (sistema, TRANSITORIO);
            // 2000+ no aceptado = rechazo de negocio (TERMINAL). >= 4000 aceptado = observado.
            $business = self::isBusinessRejectionCode($code);
            return [
                'success' => false,
                'rejected' => $business,
                'observed' => false,
                'errorType' => $business ? 'business' : 'transient',
                'code' => $code,
                'message' => $message,
                'notes' => $notes,
            ];
        }

        $observed = self::isObservedCode($code) || $notes !== [];

        return [
            'success' => true,
            'rejected' => false,
            'observed' => $observed,
            'errorType' => null,
            'code' => $code,
            'message' => self::buildMessage($message, $notes),
            'notes' => $notes,
        ];
    }

    public static function isObservedCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }
        $n = (int) $code;

        return $n >= 4000;
    }

    /**
     * Rechazo de negocio (terminal) según SUNAT: un CDR no aceptado con código >= 2000.
     * Los códigos 0100-1999 son excepciones de sistema (temporales) y NO se consideran rechazo.
     */
    public static function isBusinessRejectionCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return ((int) $code) >= 2000;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private static function normalizeNotes($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $note) {
            if (is_string($note) && trim($note) !== '') {
                $out[] = trim($note);
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $notes
     */
    private static function buildMessage(?string $description, array $notes): ?string
    {
        $description = $description !== null ? trim($description) : '';
        if ($notes === []) {
            return $description !== '' ? $description : null;
        }
        $joined = implode(' | ', $notes);
        if ($description === '') {
            return $joined;
        }

        return $description . ' — ' . $joined;
    }
}
