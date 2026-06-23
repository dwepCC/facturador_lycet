<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;

/**
 * Enrutamiento GRE 09/31 (REST 2022+):
 * - Pruebas: NubeFact (proxy) vía API REST.
 * - Producción + sunat_direct: SUNAT API producción.
 * - Producción + pse: PSE del tenant (no REST).
 */
final class GreEmitRouting
{
    public static function isGreDocument(FiscalDocument $doc): bool
    {
        $tipo = strtoupper(trim((string) $doc->getDocumentType()));
        if (in_array($tipo, ['09', '31'], true)) {
            return true;
        }
        $raw = trim((string) $doc->getSnapshotJson());
        if ($raw === '') {
            return false;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return false;
        }
        if (isset($data['document']) && is_array($data['document'])) {
            $data = $data['document'];
        }
        $tipoSnap = strtoupper(trim((string) ($data['tipoDoc'] ?? '')));

        return in_array($tipoSnap, ['09', '31'], true);
    }

    public static function resolveSendMode(FiscalDocument $doc, Empresa $empresa): string
    {
        $mode = strtolower(trim((string) ($doc->getSendMode() ?? '')));
        if ($mode !== '') {
            return $mode;
        }

        return strtolower(trim((string) ($empresa->getSendMode() ?? '')));
    }

    public static function isProduction(Empresa $empresa): bool
    {
        return strtolower(trim((string) $empresa->getAmbiente())) === 'produccion';
    }

    public static function isProductionPse(FiscalDocument $doc, Empresa $empresa): bool
    {
        return self::isProduction($empresa) && self::resolveSendMode($doc, $empresa) === 'pse';
    }

    /**
     * GRE vía API REST (NubeFact en pruebas, SUNAT en producción con sunat_direct).
     */
    public static function shouldUseGreRestApi(FiscalDocument $doc, Empresa $empresa): bool
    {
        if (!self::isGreDocument($doc)) {
            return false;
        }
        if (self::isProductionPse($doc, $empresa)) {
            return false;
        }

        return true;
    }
}
