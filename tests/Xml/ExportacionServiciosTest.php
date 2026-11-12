<?php

declare(strict_types=1);

namespace App\Tests\Xml;

use App\Xml\ExportacionServicios;
use App\Xml\TukifacInvoiceBuilder;
use DOMDocument;
use DOMXPath;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Sale\DetailAttribute;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Xml\Builder\InvoiceBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Exportación de servicios (0201/0208): el país donde se usa el servicio viaja como atributo reservado y se convierte en
 * /Invoice/cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country (guía XML de factura, campo 51).
 * Hospedaje (0202): los atributos 4000-4009 del catálogo 55 salen tal cual como AdditionalItemProperty.
 */
class ExportacionServiciosTest extends TestCase
{
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private function invoice(string $op, array $attrs): Invoice
    {
        $detail = (new SaleDetail())
            ->setCodProducto('SERV1')->setUnidad('ZZ')->setCantidad(1)->setDescripcion('SERVICIO EXPORTADO')
            ->setMtoBaseIgv(100.0)->setPorcentajeIgv(0.0)->setIgv(0.0)->setTipAfeIgv('40')->setTotalImpuestos(0.0)
            ->setMtoValorVenta(100.0)->setMtoValorUnitario(100.0)->setMtoPrecioUnitario(100.0)
            ->setAtributos($attrs);

        return (new Invoice())
            ->setUblVersion('2.1')->setTipoOperacion($op)->setTipoDoc('01')->setSerie('F001')->setCorrelativo('1')
            ->setFechaEmision(new \DateTime('2026-11-12 10:00:00-05:00'))->setTipoMoneda('USD')
            ->setCompany((new Company())->setRuc('20611759518')->setRazonSocial('EMPRESA')->setAddress(
                (new Address())->setUbigueo('150101')->setDepartamento('LIMA')->setProvincia('LIMA')->setDistrito('LIMA')->setDireccion('DIR')
            ))
            ->setClient((new Client())->setTipoDoc('-')->setNumDoc('-')->setRznSocial('ACME INC'))
            ->setMtoOperExportacion(100.0)->setValorVenta(100.0)->setSubTotal(100.0)->setMtoImpVenta(100.0)
            ->setDetails([$detail])
            ->setLegends([(new Legend())->setCode('1000')->setValue('SON CIEN CON 00/100 DOLARES AMERICANOS')]);
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

    public function testPaisDeUsoSeConvierteEnDeliveryDeLaFactura(): void
    {
        $attr = (new DetailAttribute())->setCode(ExportacionServicios::PAIS_USO)->setName('País del uso del servicio')->setValue('US');
        $xml = (new TukifacInvoiceBuilder(['autoescape' => false]))->build($this->invoice('0201', [$attr]));
        $xp = $this->xpath($xml);

        $this->assertSame('US', $xp->evaluate('string(/*/cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode)'));
        $this->assertSame('ISO 3166-1', $xp->evaluate('string(/*/cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country/cbc:IdentificationCode/@listID)'));
        $this->assertStringNotContainsString('EXP_PAIS_USO', $xml);
        $this->assertSame(1, (int) $xp->evaluate('count(/*/cac:Delivery)'));

        // Orden UBL: Delivery antes de TaxTotal/LegalMonetaryTotal/InvoiceLine y después del adquirente.
        $children = [];
        foreach ($xp->query('/*/*') as $n) {
            $children[] = $n->localName;
        }
        $d = array_search('Delivery', $children, true);
        $this->assertGreaterThan(array_search('AccountingCustomerParty', $children, true), $d);
        $this->assertLessThan(array_search('TaxTotal', $children, true), $d);
        $this->assertLessThan(array_search('LegalMonetaryTotal', $children, true), $d);
        $this->assertLessThan(array_search('InvoiceLine', $children, true), $d);
        // El adquirente se conserva con guion.
        $this->assertSame('-', $xp->evaluate('string(/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID)'));
        $this->assertSame('-', $xp->evaluate('string(/*/cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID/@schemeID)'));
    }

    public function testSinAtributoReservadoNiExportacionElXmlSaleIgualQueGreenter(): void
    {
        $inv = $this->invoice('0101', []);
        $this->assertSame(
            (new InvoiceBuilder(['autoescape' => false]))->build($inv),
            (new TukifacInvoiceBuilder(['autoescape' => false]))->build($inv)
        );
    }

    public function testHospedajeLlevaLosAtributosDel4000Al4009(): void
    {
        $mk = fn (string $c, string $n, ?string $v = null) => (new DetailAttribute())->setCode($c)->setName($n)->setValue($v);
        $attrs = [
            $mk('4000', 'País de emisión del pasaporte', 'US'),
            $mk('4001', 'País de residencia', 'CA'),
            (new DetailAttribute())->setCode('4002')->setName('Fecha de ingreso al país')->setFecInicio(new \DateTime('2026-11-01 12:00:00-05:00')),
            (new DetailAttribute())->setCode('4003')->setName('Fecha de Ingreso al Establecimiento')->setFecInicio(new \DateTime('2026-11-02 12:00:00-05:00')),
            (new DetailAttribute())->setCode('4004')->setName('Fecha de Salida del Establecimiento')->setFecInicio(new \DateTime('2026-11-05 12:00:00-05:00')),
            (new DetailAttribute())->setCode('4005')->setName('Número de Días de Permanencia')->setDuracion(3),
            $mk('4007', 'Nombres y apellidos del huesped', 'John Smith'),
            $mk('4009', 'Número de documento del huesped', 'X1234567'),
        ];
        $xml = (new TukifacInvoiceBuilder(['autoescape' => false]))->build($this->invoice('0202', $attrs));
        $xp = $this->xpath($xml);
        $p = '/*/cac:InvoiceLine/cac:Item/cac:AdditionalItemProperty';

        $this->assertSame(8, (int) $xp->evaluate("count({$p})"));
        $this->assertSame('US', $xp->evaluate("string({$p}[cbc:NameCode='4000']/cbc:Value)"));
        $this->assertSame('2026-11-01', $xp->evaluate("string({$p}[cbc:NameCode='4002']/cac:UsabilityPeriod/cbc:StartDate)"));
        $this->assertSame('2026-11-05', $xp->evaluate("string({$p}[cbc:NameCode='4004']/cac:UsabilityPeriod/cbc:StartDate)"));
        $this->assertSame('3', $xp->evaluate("string({$p}[cbc:NameCode='4005']/cac:UsabilityPeriod/cbc:DurationMeasure)"));
        $this->assertSame('John Smith', $xp->evaluate("string({$p}[cbc:NameCode='4007']/cbc:Value)"));
        // Hospedaje no usa el nodo de país de uso.
        $this->assertSame(0, (int) $xp->evaluate('count(/*/cac:Delivery)'));
    }

    /** SUNAT beta (12-nov-2026): una exportación con subtotal de IGV (tributo 1000) se rechaza con 3107; debe llevar solo 9995/EXP. */
    public function testExportacionNoLlevaElSubtotalDeIgv1000(): void
    {
        $inv = $this->invoice('0201', [])->setMtoOperGravadas(0.0)->setMtoIGV(0.0);
        $this->assertTrue(TukifacInvoiceBuilder::needsPostProcess($inv));
        $xml = (new TukifacInvoiceBuilder(['autoescape' => false]))->build($inv);
        $xp = $this->xpath($xml);

        $this->assertSame(0, (int) $xp->evaluate('count(/*/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cac:TaxScheme/cbc:ID="1000"])'));
        $this->assertSame(1, (int) $xp->evaluate('count(/*/cac:TaxTotal/cac:TaxSubtotal[cac:TaxCategory/cac:TaxScheme/cbc:ID="9995"])'));
        $this->assertSame('0.00', $xp->evaluate('string(/*/cac:TaxTotal/cbc:TaxAmount)'));
    }

    /** Fuera de las exportaciones el XML no cambia: sin atributos reservados sale idéntico al de Greenter, con su IGV 1000. */
    public function testVentaInternaNoSeToca(): void
    {
        $inv = $this->invoice('0101', [])->setMtoOperGravadas(0.0)->setMtoIGV(0.0);
        $this->assertFalse(TukifacInvoiceBuilder::needsPostProcess($inv));
        $this->assertSame(
            (new InvoiceBuilder(['autoescape' => false]))->build($inv),
            (new TukifacInvoiceBuilder(['autoescape' => false]))->build($inv)
        );
    }
}
