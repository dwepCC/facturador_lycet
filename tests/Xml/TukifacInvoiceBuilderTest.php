<?php

declare(strict_types=1);

namespace App\Tests\Xml;

use App\Xml\Transporte1004;
use App\Xml\TukifacInvoiceBuilder;
use DOMDocument;
use DOMXPath;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\DetailAttribute;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use PHPUnit\Framework\TestCase;

/**
 * Factura con detracción de transporte de carga (1004): el XML debe llevar, por línea,
 * cac:Delivery con destino, origen, detalle del viaje y los 3 valores referenciales (SUNAT, Anexo 1 de la
 * R.S. 123-2022, campos 113-118; errores 3116-3126), y no debe dejar los atributos reservados como
 * AdditionalItemProperty.
 */
class TukifacInvoiceBuilderTest extends TestCase
{
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private function invoice(bool $transporte): Invoice
    {
        $detail = (new SaleDetail())
            ->setCodProducto('SERV1')
            ->setUnidad('ZZ')
            ->setCantidad(1)
            ->setDescripcion('SERVICIO DE TRANSPORTE DE CARGA')
            ->setMtoBaseIgv(5169.49)
            ->setPorcentajeIgv(18.0)
            ->setIgv(930.51)
            ->setTipAfeIgv('10')
            ->setTotalImpuestos(930.51)
            ->setMtoValorVenta(5169.49)
            ->setMtoValorUnitario(5169.49)
            ->setMtoPrecioUnitario(6100.0);
        if ($transporte) {
            $attrs = [
                [Transporte1004::ORIG_UBIGEO, '081201'],
                [Transporte1004::ORIG_DIR, 'AV. PRINCIPAL 123 - CHAMACA'],
                [Transporte1004::DEST_UBIGEO, '150101'],
                [Transporte1004::DEST_DIR, 'JR. LIMA 456 - LIMA'],
                [Transporte1004::VIAJE, 'Transporte de concentrado Chamaca - Lima'],
                [Transporte1004::VR_SERVICIO, '6100.00'],
                [Transporte1004::VR_CARGA_EFECTIVA, '5800.00'],
                [Transporte1004::VR_CARGA_UTIL, '6000.00'],
            ];
            $list = [];
            foreach ($attrs as [$code, $value]) {
                $list[] = (new DetailAttribute())->setCode($code)->setName($code)->setValue($value);
            }
            $list[] = (new DetailAttribute())->setCode('3006')->setName('MTC')->setValue('MTC-1');
            $detail->setAtributos($list);
        }

        $invoice = (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion($transporte ? '1004' : '0101')
            ->setTipoDoc('01')
            ->setSerie('F002')
            ->setCorrelativo('16')
            ->setFechaEmision(new \DateTime('2026-10-17 10:00:00-05:00'))
            ->setTipoMoneda('PEN')
            ->setCompany((new Company())->setRuc('20611759518')->setRazonSocial('EMPRESA')->setAddress(
                (new Address())->setUbigueo('150101')->setDepartamento('LIMA')->setProvincia('LIMA')->setDistrito('LIMA')->setDireccion('DIR')
            ))
            ->setClient((new Client())->setTipoDoc('6')->setNumDoc('20100070970')->setRznSocial('CLIENTE'))
            ->setMtoOperGravadas(5169.49)
            ->setMtoIGV(930.51)
            ->setTotalImpuestos(930.51)
            ->setValorVenta(5169.49)
            ->setSubTotal(6100.0)
            ->setMtoImpVenta(6100.0)
            ->setDetails([$detail])
            ->setLegends([(new Legend())->setCode('1000')->setValue('SON SEIS MIL CIEN CON 00/100 SOLES')]);
        if ($transporte) {
            $invoice->setDetraccion(
                (new Detraction())->setPercent(4.0)->setMount(244.0)->setCtaBanco('00-000-000000')->setCodMedioPago('001')->setCodBienDetraccion('027')
            );
        }

        return $invoice;
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('cac', self::NS_CAC);
        $xp->registerNamespace('cbc', self::NS_CBC);

        return $xp;
    }

    public function testFactura1004LlevaDeliveryConOrigenDestinoViajeYValoresReferenciales(): void
    {
        $xml = (new TukifacInvoiceBuilder(['autoescape' => false]))->build($this->invoice(true));
        $xp = $this->xpath($xml);
        $base = '/*/cac:InvoiceLine/cac:Delivery';

        $this->assertSame('081201', $xp->evaluate("string($base/cac:Despatch/cac:DespatchAddress/cbc:ID)"));
        $this->assertSame('PE:INEI', $xp->evaluate("string($base/cac:Despatch/cac:DespatchAddress/cbc:ID/@schemeAgencyName)"));
        $this->assertSame('Ubigeos', $xp->evaluate("string($base/cac:Despatch/cac:DespatchAddress/cbc:ID/@schemeName)"));
        $this->assertSame('AV. PRINCIPAL 123 - CHAMACA', $xp->evaluate("string($base/cac:Despatch/cac:DespatchAddress/cac:AddressLine/cbc:Line)"));
        $this->assertSame('Transporte de concentrado Chamaca - Lima', $xp->evaluate("string($base/cac:Despatch/cbc:Instructions)"));
        $this->assertSame('150101', $xp->evaluate("string($base/cac:DeliveryLocation/cac:Address/cbc:ID)"));
        $this->assertSame('JR. LIMA 456 - LIMA', $xp->evaluate("string($base/cac:DeliveryLocation/cac:Address/cac:AddressLine/cbc:Line)"));

        $terms = [];
        foreach ($xp->query("$base/cac:DeliveryTerms") as $t) {
            $terms[$xp->evaluate('string(cbc:ID)', $t)] = $xp->evaluate('string(cbc:Amount)', $t) . ' ' . $xp->evaluate('string(cbc:Amount/@currencyID)', $t);
        }
        $this->assertSame(['01' => '6100.00 PEN', '02' => '5800.00 PEN', '03' => '6000.00 PEN'], $terms);

        // Delivery va antes de TaxTotal (orden UBL) y los atributos reservados no quedan como item property.
        $children = [];
        foreach ($xp->query('/*/cac:InvoiceLine[1]/*') as $n) {
            $children[] = $n->localName;
        }
        $this->assertLessThan(array_search('TaxTotal', $children, true), array_search('Delivery', $children, true));
        $this->assertGreaterThan(array_search('PricingReference', $children, true), array_search('Delivery', $children, true));
        $this->assertStringNotContainsString('T1004_', $xml);
        // Los atributos normales se conservan.
        $this->assertSame('3006', $xp->evaluate('string(/*/cac:InvoiceLine/cac:Item/cac:AdditionalItemProperty/cbc:NameCode)'));
    }

    public function testFacturaSinAtributosReservadosSaleIgualQueGreenter(): void
    {
        $inv = $this->invoice(false);
        $custom = (new TukifacInvoiceBuilder(['autoescape' => false]))->build($inv);
        $base = (new \Greenter\Xml\Builder\InvoiceBuilder(['autoescape' => false]))->build($inv);
        $this->assertSame($base, $custom);
    }
}
