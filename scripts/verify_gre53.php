<?php

require __DIR__ . '/../vendor/autoload.php';

use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Shipment;

$shipment = (new Shipment())
    ->setModTraslado('01')
    ->setCodTraslado('01')
    ->setFecTraslado(new DateTime('2026-06-22'))
    ->setFecEntregaBienes(new DateTime('2026-06-22'))
    ->setPesoTotal(10.0)
    ->setUndPesoTotal('KGM')
    ->setLlegada(new Direction('150101', 'Lima'))
    ->setPartida(new Direction('150101', 'Lima'));

$doc = (new Despatch())
    ->setVersion('2022')
    ->setTipoDoc('09')
    ->setSerie('T001')
    ->setCorrelativo('99')
    ->setFechaEmision(new DateTime('2026-06-22'))
    ->setCompany((new Company())->setRuc('20123456789')->setRazonSocial('TEST SAC'))
    ->setDestinatario((new Client())->setTipoDoc('6')->setNumDoc('20612129712')->setRznSocial('DEST SAC'))
    ->setEnvio($shipment)
    ->setDetails([(new DespatchDetail())->setCantidad(1)->setDescripcion('Item')->setCodigo('P1')]);

$xml = (new XmlBuilderResolver(['autoescape' => false]))->find(Despatch::class)->build($doc);
if (!is_string($xml)) {
    fwrite(STDERR, "build failed\n");
    exit(1);
}

echo str_contains($xml, 'LoadingTransportEvent') ? "OK LoadingTransportEvent\n" : "MISSING LoadingTransportEvent\n";
echo preg_match('#<cac:Despatch>.*?OccurrenceDate#s', $xml) ? "BAD OccurrenceDate in Despatch\n" : "OK XML structure\n";
