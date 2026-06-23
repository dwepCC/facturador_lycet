<?php

use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Source;
use Twig\Template;
use Twig\TemplateWrapper;

/* despatch2022.xml.twig */
class __TwigTemplate_8626f2918c441fbdae8fce21131f05c9 extends Template
{
    private Source $source;
    /**
     * @var array<string, Template>
     */
    private array $macros = [];

    public function __construct(Environment $env)
    {
        parent::__construct($env);

        $this->source = $this->getSourceContext();

        $this->parent = false;

        $this->blocks = [
        ];
    }

    protected function doDisplay(array $context, array $blocks = []): iterable
    {
        $macros = $this->macros;
        // line 1
        $_v0 = ('' === $tmp = \Twig\Extension\CoreExtension::captureOutput((function () use (&$context, $macros, $blocks) {
            // line 2
            yield "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<DespatchAdvice xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2\" xmlns:ds=\"http://www.w3.org/2000/09/xmldsig#\" xmlns:cac=\"urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2\" xmlns:cbc=\"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2\" xmlns:ext=\"urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2\">
\t<ext:UBLExtensions>
\t\t<ext:UBLExtension>
\t\t\t<ext:ExtensionContent/>
\t\t</ext:UBLExtension>
\t</ext:UBLExtensions>
\t<cbc:UBLVersionID>2.1</cbc:UBLVersionID>
\t<cbc:CustomizationID>2.0</cbc:CustomizationID>
\t<cbc:ID>";
            // line 11
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "serie", [], "any", false, false, false, 11);
            yield "-";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "correlativo", [], "any", false, false, false, 11);
            yield "</cbc:ID>
\t<cbc:IssueDate>";
            // line 12
            yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "fechaEmision", [], "any", false, false, false, 12), "Y-m-d");
            yield "</cbc:IssueDate>
\t<cbc:IssueTime>";
            // line 13
            yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "fechaEmision", [], "any", false, false, false, 13), "H:i:s");
            yield "</cbc:IssueTime>
\t<cbc:DespatchAdviceTypeCode listAgencyName=\"PE:SUNAT\" listName=\"Tipo de Documento\" listURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01\">";
            // line 14
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoDoc", [], "any", false, false, false, 14);
            yield "</cbc:DespatchAdviceTypeCode>
    ";
            // line 15
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "observacion", [], "any", false, false, false, 15)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 16
                yield "\t<cbc:Note><![CDATA[";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "observacion", [], "any", false, false, false, 16);
                yield "]]></cbc:Note>
    ";
            }
            // line 18
            yield "\t";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "addDocs", [], "any", false, false, false, 18));
            foreach ($context['_seq'] as $context["_key"] => $context["rel"]) {
                // line 19
                yield "\t<cac:AdditionalDocumentReference>
\t\t<cbc:ID>";
                // line 20
                yield CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "nro", [], "any", false, false, false, 20);
                yield "</cbc:ID>
\t\t<cbc:DocumentTypeCode listAgencyName=\"PE:SUNAT\" listName=\"Documento relacionado al transporte\" listURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo61\">";
                // line 21
                yield CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "tipo", [], "any", false, false, false, 21);
                yield "</cbc:DocumentTypeCode>
\t\t<cbc:DocumentType>";
                // line 22
                yield CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "tipoDesc", [], "any", false, false, false, 22);
                yield "</cbc:DocumentType>
\t\t";
                // line 23
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "emisor", [], "any", false, false, false, 23)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 24
                    yield "\t\t<cac:IssuerParty>
\t\t\t<cac:PartyIdentification>
\t\t\t\t<cbc:ID schemeID=\"6\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
                    // line 26
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "emisor", [], "any", false, false, false, 26);
                    yield "</cbc:ID>
\t\t\t</cac:PartyIdentification>
\t\t</cac:IssuerParty>
\t\t";
                }
                // line 30
                yield "\t</cac:AdditionalDocumentReference>
    ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['rel'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 32
            yield "    ";
            $context["emp"] = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "company", [], "any", false, false, false, 32);
            // line 33
            yield "\t<cac:Signature>
\t\t<cbc:ID>SIGN";
            // line 34
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "ruc", [], "any", false, false, false, 34);
            yield "</cbc:ID>
\t\t<cac:SignatoryParty>
\t\t\t<cac:PartyIdentification>
\t\t\t\t<cbc:ID>";
            // line 37
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "ruc", [], "any", false, false, false, 37);
            yield "</cbc:ID>
\t\t\t</cac:PartyIdentification>
\t\t\t<cac:PartyName>
\t\t\t\t<cbc:Name><![CDATA[";
            // line 40
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "razonSocial", [], "any", false, false, false, 40);
            yield "]]></cbc:Name>
\t\t\t</cac:PartyName>
\t\t</cac:SignatoryParty>
\t\t<cac:DigitalSignatureAttachment>
\t\t\t<cac:ExternalReference>
\t\t\t\t<cbc:URI>#GREENTER-SIGN</cbc:URI>
\t\t\t</cac:ExternalReference>
\t\t</cac:DigitalSignatureAttachment>
\t</cac:Signature>
\t<cac:DespatchSupplierParty>
\t\t<cac:Party>
\t\t\t<cac:PartyIdentification>
\t\t\t\t<cbc:ID schemeID=\"6\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
            // line 52
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "ruc", [], "any", false, false, false, 52);
            yield "</cbc:ID>
\t\t\t</cac:PartyIdentification>
\t\t\t<cac:PartyLegalEntity>
\t\t\t\t<cbc:RegistrationName><![CDATA[";
            // line 55
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "razonSocial", [], "any", false, false, false, 55);
            yield "]]></cbc:RegistrationName>
\t\t\t</cac:PartyLegalEntity>
\t\t</cac:Party>
\t</cac:DespatchSupplierParty>
\t<cac:DeliveryCustomerParty>
\t\t<cac:Party>
\t\t\t<cac:PartyIdentification>
\t\t\t\t<cbc:ID schemeID=\"";
            // line 62
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "destinatario", [], "any", false, false, false, 62), "tipoDoc", [], "any", false, false, false, 62);
            yield "\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "destinatario", [], "any", false, false, false, 62), "numDoc", [], "any", false, false, false, 62);
            yield "</cbc:ID>
\t\t\t</cac:PartyIdentification>
\t\t\t<cac:PartyLegalEntity>
\t\t\t\t<cbc:RegistrationName><![CDATA[";
            // line 65
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "destinatario", [], "any", false, false, false, 65), "rznSocial", [], "any", false, false, false, 65);
            yield "]]></cbc:RegistrationName>
\t\t\t</cac:PartyLegalEntity>
\t\t</cac:Party>
\t</cac:DeliveryCustomerParty>
\t";
            // line 69
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "comprador", [], "any", false, false, false, 69)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 70
                yield "\t<cac:BuyerCustomerParty>
\t\t<cac:Party>
\t\t\t<cac:PartyIdentification>
\t\t\t\t<cbc:ID schemeID=\"";
                // line 73
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "comprador", [], "any", false, false, false, 73), "tipoDoc", [], "any", false, false, false, 73);
                yield "\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "comprador", [], "any", false, false, false, 73), "numDoc", [], "any", false, false, false, 73);
                yield "</cbc:ID>
\t\t\t</cac:PartyIdentification>
\t\t\t<cac:PartyLegalEntity>
\t\t\t\t<cbc:RegistrationName><![CDATA[";
                // line 76
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "comprador", [], "any", false, false, false, 76), "rznSocial", [], "any", false, false, false, 76);
                yield "]]></cbc:RegistrationName>
\t\t\t</cac:PartyLegalEntity>
\t\t</cac:Party>
\t</cac:BuyerCustomerParty>
\t";
            }
            // line 81
            yield "\t";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 81)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 82
                yield "\t<cac:SellerSupplierParty>
\t\t<cac:Party>
\t\t\t<cac:PartyIdentification>
\t\t\t\t<cbc:ID schemeID=\"";
                // line 85
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 85), "tipoDoc", [], "any", false, false, false, 85);
                yield "\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 85), "numDoc", [], "any", false, false, false, 85);
                yield "</cbc:ID>
\t\t\t</cac:PartyIdentification>
\t\t\t<cac:PartyLegalEntity>
\t\t\t\t<cbc:RegistrationName><![CDATA[";
                // line 88
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 88), "rznSocial", [], "any", false, false, false, 88);
                yield "]]></cbc:RegistrationName>
\t\t\t</cac:PartyLegalEntity>
\t\t</cac:Party>
\t</cac:SellerSupplierParty>
\t";
            }
            // line 93
            yield "    ";
            $context["envio"] = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "envio", [], "any", false, false, false, 93);
            // line 94
            yield "\t<cac:Shipment>
\t\t<cbc:ID>SUNAT_Envio</cbc:ID>
\t\t<cbc:HandlingCode listAgencyName=\"PE:SUNAT\" listName=\"Motivo de traslado\" listURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo20\">";
            // line 96
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "codTraslado", [], "any", false, false, false, 96);
            yield "</cbc:HandlingCode>
        ";
            // line 97
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "desTraslado", [], "any", false, false, false, 97)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 98
                yield "\t\t<cbc:HandlingInstructions>";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "desTraslado", [], "any", false, false, false, 98);
                yield "</cbc:HandlingInstructions>
\t\t";
            }
            // line 100
            yield "\t\t";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "sustentoPeso", [], "any", false, false, false, 100)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 101
                yield "\t\t<cbc:Information>";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "sustentoPeso", [], "any", false, false, false, 101);
                yield "</cbc:Information>
\t\t";
            }
            // line 103
            yield "\t\t<cbc:GrossWeightMeasure unitCode=\"";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "undPesoTotal", [], "any", false, false, false, 103);
            yield "\">";
            yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "pesoTotal", [], "any", false, false, false, 103), 3);
            yield "</cbc:GrossWeightMeasure>
\t\t";
            // line 104
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "pesoItems", [], "any", false, false, false, 104)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 105
                yield "\t\t<cbc:NetWeightMeasure unitCode=\"KGM\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "pesoItems", [], "any", false, false, false, 105), 3);
                yield "</cbc:NetWeightMeasure>
\t\t";
            }
            // line 107
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "numBultos", [], "any", false, false, false, 107)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 108
                yield "\t\t<cbc:TotalTransportHandlingUnitQuantity>";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "numBultos", [], "any", false, false, false, 108);
                yield "</cbc:TotalTransportHandlingUnitQuantity>
\t\t";
            }
            // line 110
            yield "\t\t";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "indicadores", [], "any", false, false, false, 110));
            foreach ($context['_seq'] as $context["_key"] => $context["indicador"]) {
                // line 111
                yield "\t\t<cbc:SpecialInstructions>";
                yield $context["indicador"];
                yield "</cbc:SpecialInstructions>
\t\t";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['indicador'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 113
            yield "\t\t<cac:ShipmentStage>
\t\t\t<cbc:TransportModeCode listName=\"Modalidad de traslado\" listAgencyName=\"PE:SUNAT\" listURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo18\">";
            // line 114
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "modTraslado", [], "any", false, false, false, 114);
            yield "</cbc:TransportModeCode>
\t\t\t";
            // line 115
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "fecTraslado", [], "any", false, false, false, 115)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 116
                yield "\t\t\t<cac:TransitPeriod>
\t\t\t\t<cbc:StartDate>";
                // line 117
                yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "fecTraslado", [], "any", false, false, false, 117), "Y-m-d");
                yield "</cbc:StartDate>
\t\t\t</cac:TransitPeriod>
\t\t\t";
            }
            // line 120
            yield "            ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "transportista", [], "any", false, false, false, 120)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 121
                yield "\t\t\t<cac:CarrierParty>
\t\t\t\t<cac:PartyIdentification>
\t\t\t\t\t<cbc:ID schemeID=\"";
                // line 123
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "transportista", [], "any", false, false, false, 123), "tipoDoc", [], "any", false, false, false, 123);
                yield "\">";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "transportista", [], "any", false, false, false, 123), "numDoc", [], "any", false, false, false, 123);
                yield "</cbc:ID>
\t\t\t\t</cac:PartyIdentification>
\t\t\t\t<cac:PartyLegalEntity>
\t\t\t\t\t<cbc:RegistrationName><![CDATA[";
                // line 126
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "transportista", [], "any", false, false, false, 126), "rznSocial", [], "any", false, false, false, 126);
                yield "]]></cbc:RegistrationName>
\t\t\t\t\t<cbc:CompanyID>";
                // line 127
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "transportista", [], "any", false, false, false, 127), "nroMtc", [], "any", false, false, false, 127);
                yield "</cbc:CompanyID>
\t\t\t\t</cac:PartyLegalEntity>
\t\t\t</cac:CarrierParty>
            ";
            }
            // line 131
            yield "\t\t\t";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "fecEntregaBienes", [], "any", false, false, false, 131)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 132
                yield "            <cac:LoadingTransportEvent>
                <cbc:OccurrenceDate>";
                // line 133
                yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "fecEntregaBienes", [], "any", false, false, false, 133), "Y-m-d");
                yield "</cbc:OccurrenceDate>
            </cac:LoadingTransportEvent>
\t\t\t";
            }
            // line 136
            yield "\t\t\t";
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "choferes", [], "any", false, false, false, 136));
            foreach ($context['_seq'] as $context["_key"] => $context["chofer"]) {
                // line 137
                yield "\t\t\t<cac:DriverPerson>
\t\t\t\t<cbc:ID schemeID=\"";
                // line 138
                yield CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "tipoDoc", [], "any", false, false, false, 138);
                yield "\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
                yield CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "nroDoc", [], "any", false, false, false, 138);
                yield "</cbc:ID>
\t\t\t\t<cbc:FirstName>";
                // line 139
                yield CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "nombres", [], "any", false, false, false, 139);
                yield "</cbc:FirstName>
\t\t\t\t<cbc:FamilyName>";
                // line 140
                yield CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "apellidos", [], "any", false, false, false, 140);
                yield "</cbc:FamilyName>
\t\t\t\t<cbc:JobTitle>";
                // line 141
                yield CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "tipo", [], "any", false, false, false, 141);
                yield "</cbc:JobTitle>
\t\t\t\t<cac:IdentityDocumentReference>
\t\t\t\t\t<cbc:ID>";
                // line 143
                yield CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "licencia", [], "any", false, false, false, 143);
                yield "</cbc:ID>
\t\t\t\t</cac:IdentityDocumentReference>
\t\t\t</cac:DriverPerson>
\t\t\t";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['chofer'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 147
            yield "\t\t</cac:ShipmentStage>
\t\t<cac:Delivery>
\t\t\t<cac:DeliveryAddress>
\t\t\t\t<cbc:ID schemeAgencyName=\"PE:INEI\" schemeName=\"Ubigeos\">";
            // line 150
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "llegada", [], "any", false, false, false, 150), "ubigueo", [], "any", false, false, false, 150);
            yield "</cbc:ID>
\t\t\t\t";
            // line 151
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "llegada", [], "any", false, false, false, 151), "codLocal", [], "any", false, false, false, 151)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 152
                yield "\t\t\t\t<cbc:AddressTypeCode listID=\"";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "llegada", [], "any", false, false, false, 152), "ruc", [], "any", false, false, false, 152);
                yield "\">";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "llegada", [], "any", false, false, false, 152), "codLocal", [], "any", false, false, false, 152);
                yield "</cbc:AddressTypeCode>
\t\t\t\t";
            }
            // line 154
            yield "\t\t\t\t<cac:AddressLine>
\t\t\t\t\t<cbc:Line>";
            // line 155
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "llegada", [], "any", false, false, false, 155), "direccion", [], "any", false, false, false, 155);
            yield "</cbc:Line>
\t\t\t\t</cac:AddressLine>
\t\t\t</cac:DeliveryAddress>
\t\t\t<cac:Despatch>
\t\t\t\t<cac:DespatchAddress>
\t\t\t\t\t<cbc:ID schemeAgencyName=\"PE:INEI\" schemeName=\"Ubigeos\">";
            // line 160
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "partida", [], "any", false, false, false, 160), "ubigueo", [], "any", false, false, false, 160);
            yield "</cbc:ID>
\t\t\t\t\t";
            // line 161
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "partida", [], "any", false, false, false, 161), "codLocal", [], "any", false, false, false, 161)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 162
                yield "\t\t\t\t\t<cbc:AddressTypeCode listID=\"";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "partida", [], "any", false, false, false, 162), "ruc", [], "any", false, false, false, 162);
                yield "\">";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "partida", [], "any", false, false, false, 162), "codLocal", [], "any", false, false, false, 162);
                yield "</cbc:AddressTypeCode>
\t\t\t\t\t";
            }
            // line 164
            yield "\t\t\t\t\t<cac:AddressLine>
\t\t\t\t\t\t<cbc:Line>";
            // line 165
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "partida", [], "any", false, false, false, 165), "direccion", [], "any", false, false, false, 165);
            yield "</cbc:Line>
\t\t\t\t\t</cac:AddressLine>
\t\t\t\t</cac:DespatchAddress>
\t\t\t\t";
            // line 168
            if (((CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoDoc", [], "any", false, false, false, 168) == "31") && CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 168))) {
                // line 169
                yield "\t\t\t\t<cac:DespatchParty>
\t\t\t\t\t<cac:PartyIdentification>
\t\t\t\t\t\t<cbc:ID schemeID=\"";
                // line 171
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 171), "tipoDoc", [], "any", false, false, false, 171);
                yield "\" schemeName=\"Documento de Identidad\" schemeAgencyName=\"PE:SUNAT\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06\">";
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 171), "numDoc", [], "any", false, false, false, 171);
                yield "</cbc:ID>
\t\t\t\t\t</cac:PartyIdentification>
\t\t\t\t\t<cac:PartyLegalEntity>
\t\t\t\t\t\t<cbc:RegistrationName><![CDATA[";
                // line 174
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tercero", [], "any", false, false, false, 174), "rznSocial", [], "any", false, false, false, 174);
                yield "]]></cbc:RegistrationName>
\t\t\t\t\t</cac:PartyLegalEntity>
\t\t\t\t</cac:DespatchParty>
\t\t\t\t";
            }
            // line 178
            yield "\t\t\t</cac:Despatch>
\t\t</cac:Delivery>
\t\t";
            // line 180
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "contenedores", [], "any", false, false, false, 180));
            $context['loop'] = [
              'parent' => $context['_parent'],
              'index0' => 0,
              'index'  => 1,
              'first'  => true,
            ];
            if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
                $length = count($context['_seq']);
                $context['loop']['revindex0'] = $length - 1;
                $context['loop']['revindex'] = $length;
                $context['loop']['length'] = $length;
                $context['loop']['last'] = 1 === $length;
            }
            foreach ($context['_seq'] as $context["_key"] => $context["precinto"]) {
                // line 181
                yield "\t\t<cac:TransportHandlingUnit>
\t\t\t<cac:Package>
\t\t\t\t<cbc:ID>";
                // line 183
                yield CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 183);
                yield "</cbc:ID>
\t\t\t\t<cbc:TraceID>";
                // line 184
                yield $context["precinto"];
                yield "</cbc:TraceID>
\t\t\t</cac:Package>
\t\t</cac:TransportHandlingUnit>
\t\t";
                ++$context['loop']['index0'];
                ++$context['loop']['index'];
                $context['loop']['first'] = false;
                if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                    --$context['loop']['revindex0'];
                    --$context['loop']['revindex'];
                    $context['loop']['last'] = 0 === $context['loop']['revindex0'];
                }
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['precinto'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 188
            yield "\t\t";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 188)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 189
                yield "\t\t<cac:TransportHandlingUnit>
\t\t\t<cac:TransportEquipment>
\t\t\t\t<cbc:ID>";
                // line 191
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 191), "placa", [], "any", false, false, false, 191);
                yield "</cbc:ID>
\t\t\t\t";
                // line 192
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 192), "nroCirculacion", [], "any", false, false, false, 192)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 193
                    yield "\t\t\t\t<cac:ApplicableTransportMeans>
\t\t\t\t\t<cbc:RegistrationNationalityID>";
                    // line 194
                    yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 194), "nroCirculacion", [], "any", false, false, false, 194);
                    yield "</cbc:RegistrationNationalityID>
\t\t\t\t</cac:ApplicableTransportMeans>
\t\t\t\t";
                }
                // line 197
                yield "\t\t\t\t";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 197), "secundarios", [], "any", false, false, false, 197));
                foreach ($context['_seq'] as $context["_key"] => $context["sec"]) {
                    // line 198
                    yield "\t\t\t\t<cac:AttachedTransportEquipment>
\t\t\t\t\t<cbc:ID>";
                    // line 199
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["sec"], "placa", [], "any", false, false, false, 199);
                    yield "</cbc:ID>
\t\t\t\t\t";
                    // line 200
                    if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["sec"], "nroCirculacion", [], "any", false, false, false, 200)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                        // line 201
                        yield "\t\t\t\t\t<cac:ApplicableTransportMeans>
\t\t\t\t\t\t<cbc:RegistrationNationalityID>";
                        // line 202
                        yield CoreExtension::getAttribute($this->env, $this->source, $context["sec"], "nroCirculacion", [], "any", false, false, false, 202);
                        yield "</cbc:RegistrationNationalityID>
\t\t\t\t\t</cac:ApplicableTransportMeans>
\t\t\t\t\t";
                    }
                    // line 205
                    yield "\t\t\t\t\t";
                    if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["sec"], "nroAutorizacion", [], "any", false, false, false, 205)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                        // line 206
                        yield "\t\t\t\t\t<cac:ShipmentDocumentReference>
\t\t\t\t\t\t<cbc:ID schemeID=\"";
                        // line 207
                        yield CoreExtension::getAttribute($this->env, $this->source, $context["sec"], "codEmisor", [], "any", false, false, false, 207);
                        yield "\" schemeName=\"Entidad Autorizadora\" schemeAgencyName=\"PE:SUNAT\">";
                        yield CoreExtension::getAttribute($this->env, $this->source, $context["sec"], "nroAutorizacion", [], "any", false, false, false, 207);
                        yield "</cbc:ID>
\t\t\t\t\t</cac:ShipmentDocumentReference>
\t\t\t\t\t";
                    }
                    // line 210
                    yield "\t\t\t\t</cac:AttachedTransportEquipment>
\t\t\t\t";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['sec'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 212
                yield "\t\t\t\t";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 212), "nroAutorizacion", [], "any", false, false, false, 212)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 213
                    yield "\t\t\t\t<cac:ShipmentDocumentReference>
\t\t\t\t\t<cbc:ID schemeID=\"";
                    // line 214
                    yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 214), "codEmisor", [], "any", false, false, false, 214);
                    yield "\" schemeName=\"Entidad Autorizadora\" schemeAgencyName=\"PE:SUNAT\">";
                    yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "vehiculo", [], "any", false, false, false, 214), "nroAutorizacion", [], "any", false, false, false, 214);
                    yield "</cbc:ID>
\t\t\t\t</cac:ShipmentDocumentReference>
\t\t\t\t";
                }
                // line 217
                yield "\t\t\t</cac:TransportEquipment>
\t\t</cac:TransportHandlingUnit>
\t\t";
            }
            // line 220
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "puerto", [], "any", false, false, false, 220)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 221
                yield "\t\t<cac:FirstArrivalPortLocation>
\t\t\t<cbc:ID schemeAgencyName=\"PE:SUNAT\" schemeName=\"Puertos\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo63\">";
                // line 222
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "puerto", [], "any", false, false, false, 222), "codigo", [], "any", false, false, false, 222);
                yield "</cbc:ID>
\t\t\t<cbc:LocationTypeCode>1</cbc:LocationTypeCode>
\t\t\t<cbc:Name>";
                // line 224
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "puerto", [], "any", false, false, false, 224), "nombre", [], "any", false, false, false, 224);
                yield "</cbc:Name>
\t\t</cac:FirstArrivalPortLocation>
        ";
            } elseif ((($tmp = CoreExtension::getAttribute($this->env, $this->source,             // line 226
($context["envio"] ?? null), "aeropuerto", [], "any", false, false, false, 226)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 227
                yield "\t\t<cac:FirstArrivalPortLocation>
\t\t\t<cbc:ID schemeAgencyName=\"PE:SUNAT\" schemeName=\"Aeropuertos\" schemeURI=\"urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo64\">";
                // line 228
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "aeropuerto", [], "any", false, false, false, 228), "codigo", [], "any", false, false, false, 228);
                yield "</cbc:ID>
\t\t\t<cbc:LocationTypeCode>2</cbc:LocationTypeCode>
\t\t\t<cbc:Name>";
                // line 230
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["envio"] ?? null), "aeropuerto", [], "any", false, false, false, 230), "nombre", [], "any", false, false, false, 230);
                yield "</cbc:Name>
\t\t</cac:FirstArrivalPortLocation>
\t\t";
            }
            // line 233
            yield "\t</cac:Shipment>
    ";
            // line 234
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "details", [], "any", false, false, false, 234));
            $context['loop'] = [
              'parent' => $context['_parent'],
              'index0' => 0,
              'index'  => 1,
              'first'  => true,
            ];
            if (is_array($context['_seq']) || (is_object($context['_seq']) && $context['_seq'] instanceof \Countable)) {
                $length = count($context['_seq']);
                $context['loop']['revindex0'] = $length - 1;
                $context['loop']['revindex'] = $length;
                $context['loop']['length'] = $length;
                $context['loop']['last'] = 1 === $length;
            }
            foreach ($context['_seq'] as $context["_key"] => $context["det"]) {
                // line 235
                yield "\t<cac:DespatchLine>
\t\t<cbc:ID>";
                // line 236
                yield CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 236);
                yield "</cbc:ID>
\t\t<cbc:DeliveredQuantity unitCode=\"";
                // line 237
                yield CoreExtension::getAttribute($this->env, $this->source, $context["det"], "unidad", [], "any", false, false, false, 237);
                yield "\">";
                yield $this->env->getFilter('n_format_limit')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["det"], "cantidad", [], "any", false, false, false, 237), 10);
                yield "</cbc:DeliveredQuantity>
\t\t<cac:OrderLineReference>
\t\t\t<cbc:LineID>";
                // line 239
                yield CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 239);
                yield "</cbc:LineID>
\t\t</cac:OrderLineReference>
\t\t<cac:Item>
\t\t\t<cbc:Description><![CDATA[";
                // line 242
                yield CoreExtension::getAttribute($this->env, $this->source, $context["det"], "descripcion", [], "any", false, false, false, 242);
                yield "]]></cbc:Description>
\t\t\t<cac:SellersItemIdentification>
\t\t\t\t<cbc:ID>";
                // line 244
                yield CoreExtension::getAttribute($this->env, $this->source, $context["det"], "codigo", [], "any", false, false, false, 244);
                yield "</cbc:ID>
\t\t\t</cac:SellersItemIdentification>
\t\t\t";
                // line 246
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["det"], "codProdSunat", [], "any", false, false, false, 246)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 247
                    yield "\t\t\t<cac:CommodityClassification>
\t\t\t\t<cbc:ItemClassificationCode listID=\"UNSPSC\" listAgencyName=\"GS1 US\" listName=\"Item Classification\">";
                    // line 248
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["det"], "codProdSunat", [], "any", false, false, false, 248);
                    yield "</cbc:ItemClassificationCode>
\t\t\t</cac:CommodityClassification>
\t\t\t";
                }
                // line 251
                yield "\t\t\t";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["det"], "atributos", [], "any", false, false, false, 251));
                foreach ($context['_seq'] as $context["_key"] => $context["atr"]) {
                    // line 252
                    yield "\t\t\t<cac:AdditionalItemProperty >
\t\t\t\t<cbc:Name>";
                    // line 253
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "name", [], "any", false, false, false, 253);
                    yield "</cbc:Name>
\t\t\t\t<cbc:NameCode>";
                    // line 254
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "code", [], "any", false, false, false, 254);
                    yield "</cbc:NameCode>
\t\t\t\t";
                    // line 255
                    if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "value", [], "any", false, false, false, 255)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                        // line 256
                        yield "\t\t\t\t\t<cbc:Value>";
                        yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "value", [], "any", false, false, false, 256);
                        yield "</cbc:Value>
\t\t\t\t";
                    }
                    // line 258
                    yield "\t\t\t</cac:AdditionalItemProperty>
\t\t\t";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['atr'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 260
                yield "\t\t</cac:Item>
\t</cac:DespatchLine>
    ";
                ++$context['loop']['index0'];
                ++$context['loop']['index'];
                $context['loop']['first'] = false;
                if (isset($context['loop']['revindex0'], $context['loop']['revindex'])) {
                    --$context['loop']['revindex0'];
                    --$context['loop']['revindex'];
                    $context['loop']['last'] = 0 === $context['loop']['revindex0'];
                }
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['det'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 263
            yield "</DespatchAdvice>
";
            yield from [];
        })())) ? '' : new Markup($tmp, $this->env->getCharset());
        // line 1
        yield Twig\Extension\CoreExtension::spaceless($this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($_v0, "html", null, true));
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "despatch2022.xml.twig";
    }

    /**
     * @codeCoverageIgnore
     */
    public function isTraitable(): bool
    {
        return false;
    }

    /**
     * @codeCoverageIgnore
     */
    public function getDebugInfo(): array
    {
        return array (  742 => 1,  737 => 263,  721 => 260,  714 => 258,  708 => 256,  706 => 255,  702 => 254,  698 => 253,  695 => 252,  690 => 251,  684 => 248,  681 => 247,  679 => 246,  674 => 244,  669 => 242,  663 => 239,  656 => 237,  652 => 236,  649 => 235,  632 => 234,  629 => 233,  623 => 230,  618 => 228,  615 => 227,  613 => 226,  608 => 224,  603 => 222,  600 => 221,  597 => 220,  592 => 217,  584 => 214,  581 => 213,  578 => 212,  571 => 210,  563 => 207,  560 => 206,  557 => 205,  551 => 202,  548 => 201,  546 => 200,  542 => 199,  539 => 198,  534 => 197,  528 => 194,  525 => 193,  523 => 192,  519 => 191,  515 => 189,  512 => 188,  494 => 184,  490 => 183,  486 => 181,  469 => 180,  465 => 178,  458 => 174,  450 => 171,  446 => 169,  444 => 168,  438 => 165,  435 => 164,  427 => 162,  425 => 161,  421 => 160,  413 => 155,  410 => 154,  402 => 152,  400 => 151,  396 => 150,  391 => 147,  381 => 143,  376 => 141,  372 => 140,  368 => 139,  362 => 138,  359 => 137,  354 => 136,  348 => 133,  345 => 132,  342 => 131,  335 => 127,  331 => 126,  323 => 123,  319 => 121,  316 => 120,  310 => 117,  307 => 116,  305 => 115,  301 => 114,  298 => 113,  289 => 111,  284 => 110,  278 => 108,  275 => 107,  269 => 105,  267 => 104,  260 => 103,  254 => 101,  251 => 100,  245 => 98,  243 => 97,  239 => 96,  235 => 94,  232 => 93,  224 => 88,  216 => 85,  211 => 82,  208 => 81,  200 => 76,  192 => 73,  187 => 70,  185 => 69,  178 => 65,  170 => 62,  160 => 55,  154 => 52,  139 => 40,  133 => 37,  127 => 34,  124 => 33,  121 => 32,  114 => 30,  107 => 26,  103 => 24,  101 => 23,  97 => 22,  93 => 21,  89 => 20,  86 => 19,  81 => 18,  75 => 16,  73 => 15,  69 => 14,  65 => 13,  61 => 12,  55 => 11,  44 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "despatch2022.xml.twig", "E:\\tukifac\\tukifac_premium\\facturador_lycet\\vendor\\greenter\\xml\\src\\Xml\\Templates\\despatch2022.xml.twig");
    }
}
