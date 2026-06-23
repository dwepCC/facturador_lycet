<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Service\Fiscal\DespatchSnapshotEnricher;
use Greenter\Model\Despatch\Despatch;
use JMS\Serializer\SerializerBuilder;

$json = <<<'JSON'
{
  "version": "2022",
  "tipoDoc": "31",
  "serie": "V001",
  "correlativo": "4",
  "fechaEmision": "2026-06-22T12:00:00-05:00",
  "company": {"ruc": "10726187938", "razonSocial": "TRANS SAC", "address": {"ubigueo": "150132", "direccion": "Av 1"}},
  "destinatario": {"tipoDoc": "6", "numDoc": "20612129712", "rznSocial": "DEST", "address": {"ubigueo": "150101", "direccion": "Calle 2"}},
  "envio": {
    "modTraslado": "02",
    "codTraslado": "01",
    "fecTraslado": "2026-06-22T12:00:00-05:00",
    "fecEntregaBienes": "2026-06-22T12:00:00-05:00",
    "partida": {"ubigueo": "150132", "direccion": "Partida"},
    "llegada": {"ubigueo": "150101", "direccion": "Llegada"},
    "pesoTotal": 5,
    "transportista": {"tipoDoc": "6", "numDoc": "10726187938", "rznSocial": "TRANS SAC"},
    "vehiculo": {"placa": "ABC123"},
    "choferes": [{"tipo": "Principal", "tipoDoc": "1", "nroDoc": "12345678", "nombres": "JUAN", "apellidos": "PEREZ", "licencia": "Q12345678"}]
  },
  "details": [{"codigo": "P1", "descripcion": "Prod", "unidad": "NIU", "cantidad": 1}]
}
JSON;

$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$serializer = SerializerBuilder::create()->build();

foreach (['no tercero', 'with tercero'] as $case) {
    $payload = $data;
    if ($case === 'with tercero') {
        $payload['tercero'] = [
            'tipoDoc' => '6',
            'numDoc' => '20100070970',
            'rznSocial' => 'REMITENTE SAC',
            'address' => ['ubigueo' => '150132', 'codigoPais' => 'PE', 'direccion' => 'Remitente dir'],
        ];
    }
    try {
        $enriched = DespatchSnapshotEnricher::enrich($payload);
        /** @var Despatch $doc */
        $doc = $serializer->deserialize(json_encode($enriched), Despatch::class, 'json');
        $tercero = $doc->getTercero();
        echo "$case: tercero=" . ($tercero?->getNumDoc() ?? 'NULL') . PHP_EOL;
    } catch (Throwable $e) {
        echo "$case: ERROR " . $e->getMessage() . PHP_EOL;
    }
}
