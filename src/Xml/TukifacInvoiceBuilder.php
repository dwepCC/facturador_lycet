<?php

declare(strict_types=1);

namespace App\Xml;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Invoice;
use Greenter\Xml\Builder\InvoiceBuilder;

/**
 * InvoiceBuilder de Greenter + los nodos de la detracción de transporte de carga (1004) que su plantilla
 * no genera. Se hace como post-proceso del XML (sin firmar) para no tocar vendor/greenter: lee los
 * atributos reservados de Transporte1004 de cada línea, agrega cac:InvoiceLine/cac:Delivery y retira esos
 * atributos de los cac:AdditionalItemProperty. Si la factura no trae atributos reservados el XML sale
 * idéntico al de Greenter.
 */
class TukifacInvoiceBuilder extends InvoiceBuilder
{
    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public function build(DocumentInterface $document): ?string
    {
        $xml = parent::build($document);
        if ($xml === null || !$document instanceof Invoice || !self::hasReservedAttributes($document)) {
            return $xml;
        }

        return self::injectDelivery($xml, (string) $document->getTipoMoneda());
    }

    public static function hasReservedAttributes(Invoice $invoice): bool
    {
        foreach ($invoice->getDetails() ?? [] as $detail) {
            foreach ($detail->getAtributos() ?? [] as $atr) {
                if (in_array($atr->getCode(), Transporte1004::ALL, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function injectDelivery(string $xml, string $currency): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;
        if (!$dom->loadXML($xml)) {
            throw new \RuntimeException('XML de factura inválido al agregar nodos de transporte de carga');
        }
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('cac', self::NS_CAC);
        $xp->registerNamespace('cbc', self::NS_CBC);

        foreach (iterator_to_array($xp->query('/*/cac:InvoiceLine')) as $line) {
            /** @var DOMElement $line */
            $values = [];
            foreach (iterator_to_array($xp->query('cac:Item/cac:AdditionalItemProperty', $line)) as $prop) {
                /** @var DOMElement $prop */
                $code = trim((string) $xp->evaluate('string(cbc:NameCode)', $prop));
                if (!in_array($code, Transporte1004::ALL, true)) {
                    continue;
                }
                $values[$code] = trim((string) $xp->evaluate('string(cbc:Value)', $prop));
                $prop->parentNode->removeChild($prop);
            }
            if ($values === []) {
                continue;
            }
            $delivery = self::buildDelivery($dom, $values, $currency);
            // Orden UBL de cac:InvoiceLine: ... PricingReference, Delivery, AllowanceCharge, TaxTotal ...
            $anchor = $xp->query('cac:AllowanceCharge[1] | cac:TaxTotal[1]', $line)->item(0);
            $line->insertBefore($delivery, $anchor);
        }

        $out = $dom->saveXML();

        return $out === false ? $xml : $out;
    }

    /**
     * @param array<string,string> $v
     */
    private static function buildDelivery(DOMDocument $dom, array $v, string $currency): DOMElement
    {
        $cac = fn (string $n): DOMElement => $dom->createElementNS(self::NS_CAC, 'cac:' . $n);
        $cbc = function (string $n, string $text) use ($dom): DOMElement {
            $e = $dom->createElementNS(self::NS_CBC, 'cbc:' . $n);
            $e->appendChild($dom->createTextNode($text));

            return $e;
        };
        $address = function (string $wrapper, string $ubigeo, string $dir) use ($cac, $cbc): DOMElement {
            $id = $cbc('ID', $ubigeo);
            $id->setAttribute('schemeAgencyName', 'PE:INEI');
            $id->setAttribute('schemeName', 'Ubigeos');
            $addr = $cac($wrapper);
            $addr->appendChild($id);
            $line = $cac('AddressLine');
            $line->appendChild($cbc('Line', $dir));
            $addr->appendChild($line);

            return $addr;
        };

        $delivery = $cac('Delivery');

        // Punto de destino -> cac:DeliveryLocation/cac:Address
        $location = $cac('DeliveryLocation');
        $location->appendChild($address('Address', $v[Transporte1004::DEST_UBIGEO] ?? '', $v[Transporte1004::DEST_DIR] ?? ''));
        $delivery->appendChild($location);

        // Punto de origen -> cac:Despatch/cac:DespatchAddress; detalle del viaje -> cac:Despatch/cbc:Instructions
        $despatch = $cac('Despatch');
        $despatch->appendChild($cbc('Instructions', $v[Transporte1004::VIAJE] ?? ''));
        $despatch->appendChild($address('DespatchAddress', $v[Transporte1004::ORIG_UBIGEO] ?? '', $v[Transporte1004::ORIG_DIR] ?? ''));
        $delivery->appendChild($despatch);

        // Valores referenciales (Catálogo 02): 01 servicio de transporte, 02 carga efectiva, 03 carga útil nominal.
        $terms = [
            '01' => Transporte1004::VR_SERVICIO,
            '02' => Transporte1004::VR_CARGA_EFECTIVA,
            '03' => Transporte1004::VR_CARGA_UTIL,
        ];
        foreach ($terms as $type => $code) {
            $amount = $cbc('Amount', $v[$code] ?? '');
            $amount->setAttribute('currencyID', $currency !== '' ? $currency : 'PEN');
            $term = $cac('DeliveryTerms');
            $term->appendChild($cbc('ID', (string) $type));
            $term->appendChild($amount);
            $delivery->appendChild($term);
        }

        return $delivery;
    }
}
