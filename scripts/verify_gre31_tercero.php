<?php

require __DIR__ . '/../vendor/autoload.php';

use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\Despatch\Vehicle;

function buildGre31(bool $withTercero): string
{
    $shipment = (new Shipment())
        ->setModTraslado('02')
        ->setCodTraslado('01')
        ->setFecTraslado(new DateTime('2026-06-22'))
        ->setFecEntregaBienes(new DateTime('2026-06-22'))
        ->setPesoTotal(10.0)
        ->setUndPesoTotal('KGM')
        ->setLlegada(new Direction('150101', 'Lima dest'))
        ->setPartida(new Direction('150132', 'Lima part'))
        ->setTransportista((new Transportist())->setTipoDoc('6')->setNumDoc('10726187938')->setRznSocial('TRANS SAC'))
        ->setVehiculo((new Vehicle())->setPlaca('ABC123'))
        ->setChoferes([(new Driver())->setTipo('Principal')->setTipoDoc('1')->setNroDoc('12345678')->setNombres('JUAN')->setApellidos('PEREZ')->setLicencia('Q12345678')]);

    $doc = (new Despatch())
        ->setVersion('2022')
        ->setTipoDoc('31')
        ->setSerie('V001')
        ->setCorrelativo('3')
        ->setFechaEmision(new DateTime('2026-06-22'))
        ->setCompany((new Company())->setRuc('10726187938')->setRazonSocial('TRANS SAC'))
        ->setDestinatario((new Client())->setTipoDoc('6')->setNumDoc('20612129712')->setRznSocial('DEST SAC'))
        ->setEnvio($shipment)
        ->setDetails([(new DespatchDetail())->setCantidad(1)->setDescripcion('Item')->setCodigo('P1')]);

    if ($withTercero) {
        $doc->setTercero((new Client())->setTipoDoc('6')->setNumDoc('20100070970')->setRznSocial('REMITENTE SAC'));
    }

    $xml = (new XmlBuilderResolver(['autoescape' => false]))->find(Despatch::class)->build($doc);
    if (!is_string($xml)) {
        throw new RuntimeException('XML build failed');
    }

    return $xml;
}

foreach ([false, true] as $withTercero) {
    $label = $withTercero ? 'WITH tercero' : 'WITHOUT tercero';
    $xml = buildGre31($withTercero);
    $hasSeller = str_contains($xml, 'SellerSupplierParty');
    $hasDespatchParty = str_contains($xml, 'DespatchParty');
    echo "$label: SellerSupplierParty=" . ($hasSeller ? 'yes' : 'no')
        . ' DespatchParty=' . ($hasDespatchParty ? 'yes' : 'no') . PHP_EOL;
    if ($hasDespatchParty && preg_match('/DespatchParty.*?<cbc:ID[^>]*>([^<]+)/s', $xml, $m)) {
        echo "  remitente DespatchParty numDoc={$m[1]}" . PHP_EOL;
    }
    if (!$withTercero && $hasDespatchParty) {
        echo "  ERROR: DespatchParty should not appear without tercero" . PHP_EOL;
        exit(1);
    }
    if ($withTercero && !$hasDespatchParty) {
        echo "  ERROR: DespatchParty missing for GRE 31 (SUNAT 3383)" . PHP_EOL;
        exit(1);
    }
}
echo "OK: GRE 31 remitente en DespatchParty" . PHP_EOL;
