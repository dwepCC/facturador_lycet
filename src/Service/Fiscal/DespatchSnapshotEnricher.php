<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

/**
 * Completa campos GRE 2022 faltantes antes de deserializar a Greenter.
 */
final class DespatchSnapshotEnricher
{
    private const MOD_PUBLICO = '01';
    private const MOD_PRIVADO = '02';
    private const IND_VEH_COND = 'SUNAT_Envio_IndicadorVehiculoConductoresTransp';

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function enrich(array $data): array
    {
        $tipo = strtoupper(trim((string) ($data['tipoDoc'] ?? '')));
        if (!in_array($tipo, ['09', '31'], true)) {
            return $data;
        }

        $data['version'] = '2022';
        if (!isset($data['envio']) || !is_array($data['envio'])) {
            return $data;
        }

        $envio = $data['envio'];
        $fechaEmision = trim((string) ($data['fechaEmision'] ?? ''));

        $fecTraslado = trim((string) ($envio['fecTraslado'] ?? ''));
        if ($fecTraslado === '' && $fechaEmision !== '') {
            $fecTraslado = $fechaEmision;
            $envio['fecTraslado'] = $fechaEmision;
        }

        $fecEntrega = trim((string) ($envio['fecEntregaBienes'] ?? $envio['fecEntregaTransportista'] ?? ''));
        if ($fecEntrega === '' && $fecTraslado !== '') {
            $envio['fecEntregaBienes'] = $fecTraslado;
        } elseif ($fecEntrega !== '') {
            $envio['fecEntregaBienes'] = $fecEntrega;
        }

        if ($tipo === '31') {
            $envio['modTraslado'] = self::MOD_PRIVADO;
        } elseif (trim((string) ($envio['modTraslado'] ?? '')) === '') {
            $envio['modTraslado'] = self::MOD_PRIVADO;
        }

        if ($tipo === '31') {
            $data = self::ensureGre31Remitente($data);
        }

        if ($tipo === '31' && empty($envio['transportista']) && isset($data['company']) && is_array($data['company'])) {
            $company = $data['company'];
            $ruc = trim((string) ($company['ruc'] ?? ''));
            $razon = trim((string) ($company['razonSocial'] ?? ''));
            if ($ruc !== '' && $razon !== '') {
                $envio['transportista'] = [
                    'tipoDoc' => '6',
                    'numDoc' => $ruc,
                    'rznSocial' => $razon,
                    'nroMtc' => trim((string) ($envio['transportistaMtc'] ?? '')),
                ];
            }
        }

        if (isset($envio['choferes']) && is_array($envio['choferes'])) {
            foreach ($envio['choferes'] as $i => $ch) {
                if (!is_array($ch)) {
                    continue;
                }
                if (trim((string) ($ch['tipo'] ?? '')) === '') {
                    $ch['tipo'] = 'Principal';
                }
                if (trim((string) ($ch['nombres'] ?? '')) === '' && trim((string) ($ch['apellidos'] ?? '')) === '') {
                    $ch['nombres'] = 'CONDUCTOR';
                }
                $nroDoc = trim((string) ($ch['nroDoc'] ?? ''));
                $lic = strtoupper(str_replace(['-', ' '], '', trim((string) ($ch['licencia'] ?? ''))));
                // No reutilizar DNI como licencia: SUNAT error 2573.
                if ($lic !== '' && $lic === $nroDoc) {
                    unset($ch['licencia']);
                } elseif ($lic !== '') {
                    $ch['licencia'] = $lic;
                }
                $envio['choferes'][$i] = $ch;
            }
        }

        if (isset($envio['vehiculo']) && is_array($envio['vehiculo'])) {
            $veh = $envio['vehiculo'];
            if (trim((string) ($veh['placa'] ?? '')) !== '') {
                $veh['placa'] = strtoupper(str_replace(['-', ' '], '', trim((string) $veh['placa'])));
            }
            if (trim((string) ($veh['nroAutorizacion'] ?? '')) !== ''
                && trim((string) ($veh['codEmisor'] ?? '')) === ''
            ) {
                $veh['codEmisor'] = '000001';
            }
            $envio['vehiculo'] = $veh;
        }

        if ($tipo === '31') {
            self::assertGre31TransportData($envio);
            $envio = self::enrichGre31Partida($envio, $data['tercero'] ?? null);
        }

        $modFinal = trim((string) ($envio['modTraslado'] ?? ''));
        if ($tipo === '09'
            && $modFinal === self::MOD_PUBLICO
            && !empty($envio['vehiculo'])
            && !empty($envio['choferes'])
        ) {
            $indicadores = $envio['indicadores'] ?? [];
            if (!is_array($indicadores)) {
                $indicadores = [];
            }
            if (!in_array(self::IND_VEH_COND, $indicadores, true)) {
                $indicadores[] = self::IND_VEH_COND;
            }
            $envio['indicadores'] = $indicadores;
        }

        $data['envio'] = $envio;

        return $data;
    }

    /**
     * GRE transportista (31): remitente en tercero → SellerSupplierParty y DespatchParty (SUNAT 3383 si falta).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function ensureGre31Remitente(array $data): array
    {
        if (empty($data['tercero']) && !empty($data['remitente']) && is_array($data['remitente'])) {
            $data['tercero'] = self::clientFromRemitente($data['remitente']);
        }

        if (!isset($data['tercero']) || !is_array($data['tercero'])) {
            throw new \InvalidArgumentException(
                'GRE transportista (31): falta el remitente (tercero). Complete RUC/DNI, razón social, dirección y ubigeo del remitente.'
            );
        }

        $data['tercero'] = self::normalizeClient($data['tercero']);

        return $data;
    }

    /**
     * @param array<string, mixed> $rem
     * @return array<string, mixed>
     */
    private static function clientFromRemitente(array $rem): array
    {
        $numDoc = str_replace('-', '', trim((string) ($rem['num_doc'] ?? $rem['numDoc'] ?? '')));
        $tipoDoc = trim((string) ($rem['tipo_doc'] ?? $rem['tipoDoc'] ?? ''));
        $rzn = trim((string) ($rem['rzn_social'] ?? $rem['rznSocial'] ?? ''));
        $address = $rem['address'] ?? null;

        if (!is_array($address)) {
            $ubigeo = trim((string) ($rem['ubigeo'] ?? ''));
            $direccion = is_string($address) ? trim($address) : trim((string) ($rem['direccion'] ?? ''));
            if ($ubigeo !== '' || $direccion !== '') {
                $address = [
                    'ubigueo' => $ubigeo,
                    'codigoPais' => 'PE',
                    'direccion' => $direccion,
                ];
            } else {
                $address = null;
            }
        }

        return self::normalizeClient([
            'tipoDoc' => $tipoDoc,
            'numDoc' => $numDoc,
            'rznSocial' => $rzn,
            'address' => $address,
        ]);
    }

    /**
     * @param array<string, mixed> $client
     * @return array<string, mixed>
     */
    private static function normalizeClient(array $client): array
    {
        $numDoc = str_replace('-', '', trim((string) ($client['numDoc'] ?? '')));
        $tipoDoc = trim((string) ($client['tipoDoc'] ?? ''));
        if ($tipoDoc === '') {
            $tipoDoc = match (strlen($numDoc)) {
                11 => '6',
                8 => '1',
                default => '6',
            };
        }
        $rzn = trim((string) ($client['rznSocial'] ?? ''));
        if ($numDoc === '' || $rzn === '') {
            throw new \InvalidArgumentException(
                'GRE transportista (31): documento y razón social del remitente son obligatorios (SUNAT 3383).'
            );
        }

        $client['tipoDoc'] = $tipoDoc;
        $client['numDoc'] = $numDoc;
        $client['rznSocial'] = $rzn;

        return $client;
    }

    /**
     * GRE-T: RUC del remitente en partida (AddressTypeCode@listID) para establecimiento 0000.
     *
     * @param array<string, mixed> $envio
     * @param array<string, mixed>|null $tercero
     * @return array<string, mixed>
     */
    private static function enrichGre31Partida(array $envio, ?array $tercero): array
    {
        if (!isset($envio['partida']) || !is_array($envio['partida']) || !is_array($tercero)) {
            return $envio;
        }
        $partida = $envio['partida'];
        if (($tercero['tipoDoc'] ?? '') === '6'
            && trim((string) ($partida['ruc'] ?? '')) === ''
        ) {
            $partida['ruc'] = $tercero['numDoc'];
        }
        if (trim((string) ($partida['codLocal'] ?? '')) === ''
            && trim((string) ($partida['ruc'] ?? '')) !== ''
        ) {
            $partida['codLocal'] = '0000';
        }
        $envio['partida'] = $partida;

        return $envio;
    }

    /**
     * @param array<string, mixed> $envio
     */
    private static function assertGre31TransportData(array $envio): void
    {
        $placa = strtoupper(str_replace(['-', ' '], '', trim((string) ($envio['vehiculo']['placa'] ?? ''))));
        if ($placa === '') {
            throw new \InvalidArgumentException('GRE transportista (31): la placa del vehículo es obligatoria (SUNAT 2566).');
        }
        $choferes = $envio['choferes'] ?? [];
        if (!is_array($choferes) || count($choferes) === 0) {
            throw new \InvalidArgumentException('GRE transportista (31): datos del conductor son obligatorios (SUNAT 3357).');
        }
        $transp = $envio['transportista'] ?? null;
        if (!is_array($transp) || trim((string) ($transp['numDoc'] ?? '')) === '') {
            throw new \InvalidArgumentException('GRE transportista (31): datos del transportista emisor son obligatorios.');
        }
    }
}
