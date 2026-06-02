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

/* notacr2.1.xml.twig */
class __TwigTemplate_8fef75e3720b9fa444d1a6703937da8f extends Template
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
<CreditNote xmlns=\"urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2\" xmlns:cac=\"urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2\" xmlns:cbc=\"urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2\" xmlns:ds=\"http://www.w3.org/2000/09/xmldsig#\" xmlns:ext=\"urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2\">
    <ext:UBLExtensions>
        <ext:UBLExtension>
            <ext:ExtensionContent/>
        </ext:UBLExtension>
    </ext:UBLExtensions>
    <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
    <cbc:CustomizationID>2.0</cbc:CustomizationID>
    <cbc:ID>";
            // line 11
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "serie", [], "any", false, false, false, 11);
            yield "-";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "correlativo", [], "any", false, false, false, 11);
            yield "</cbc:ID>
    <cbc:IssueDate>";
            // line 12
            yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "fechaEmision", [], "any", false, false, false, 12), "Y-m-d");
            yield "</cbc:IssueDate>
    <cbc:IssueTime>";
            // line 13
            yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "fechaEmision", [], "any", false, false, false, 13), "H:i:s");
            yield "</cbc:IssueTime>
    ";
            // line 14
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "legends", [], "any", false, false, false, 14));
            foreach ($context['_seq'] as $context["_key"] => $context["leg"]) {
                // line 15
                yield "    <cbc:Note languageLocaleID=\"";
                yield CoreExtension::getAttribute($this->env, $this->source, $context["leg"], "code", [], "any", false, false, false, 15);
                yield "\"><![CDATA[";
                yield CoreExtension::getAttribute($this->env, $this->source, $context["leg"], "value", [], "any", false, false, false, 15);
                yield "]]></cbc:Note>
    ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['leg'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 17
            yield "    <cbc:DocumentCurrencyCode>";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 17);
            yield "</cbc:DocumentCurrencyCode>
    <cac:DiscrepancyResponse>
        <cbc:ReferenceID>";
            // line 19
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "numDocfectado", [], "any", false, false, false, 19);
            yield "</cbc:ReferenceID>
        <cbc:ResponseCode>";
            // line 20
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "codMotivo", [], "any", false, false, false, 20);
            yield "</cbc:ResponseCode>
        <cbc:Description>";
            // line 21
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "desMotivo", [], "any", false, false, false, 21);
            yield "</cbc:Description>
    </cac:DiscrepancyResponse>
    ";
            // line 23
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "compra", [], "any", false, false, false, 23)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 24
                yield "    <cac:OrderReference>
        <cbc:ID>";
                // line 25
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "compra", [], "any", false, false, false, 25);
                yield "</cbc:ID>
    </cac:OrderReference>
    ";
            }
            // line 28
            yield "    <cac:BillingReference>
        <cac:InvoiceDocumentReference>
            <cbc:ID>";
            // line 30
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "numDocfectado", [], "any", false, false, false, 30);
            yield "</cbc:ID>
            <cbc:DocumentTypeCode>";
            // line 31
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipDocAfectado", [], "any", false, false, false, 31);
            yield "</cbc:DocumentTypeCode>
        </cac:InvoiceDocumentReference>
    </cac:BillingReference>
    ";
            // line 34
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "guias", [], "any", false, false, false, 34)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 35
                yield "    ";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "guias", [], "any", false, false, false, 35));
                foreach ($context['_seq'] as $context["_key"] => $context["guia"]) {
                    // line 36
                    yield "    <cac:DespatchDocumentReference>
        <cbc:ID>";
                    // line 37
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["guia"], "nroDoc", [], "any", false, false, false, 37);
                    yield "</cbc:ID>
        <cbc:DocumentTypeCode>";
                    // line 38
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["guia"], "tipoDoc", [], "any", false, false, false, 38);
                    yield "</cbc:DocumentTypeCode>
    </cac:DespatchDocumentReference>
    ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['guia'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 41
                yield "    ";
            }
            // line 42
            yield "    ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "relDocs", [], "any", false, false, false, 42)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 43
                yield "    ";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "relDocs", [], "any", false, false, false, 43));
                foreach ($context['_seq'] as $context["_key"] => $context["rel"]) {
                    // line 44
                    yield "    <cac:AdditionalDocumentReference>
        <cbc:ID>";
                    // line 45
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "nroDoc", [], "any", false, false, false, 45);
                    yield "</cbc:ID>
        <cbc:DocumentTypeCode>";
                    // line 46
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["rel"], "tipoDoc", [], "any", false, false, false, 46);
                    yield "</cbc:DocumentTypeCode>
    </cac:AdditionalDocumentReference>
    ";
                }
                $_parent = $context['_parent'];
                unset($context['_seq'], $context['_key'], $context['rel'], $context['_parent']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 49
                yield "    ";
            }
            // line 50
            yield "    ";
            $context["emp"] = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "company", [], "any", false, false, false, 50);
            // line 51
            yield "    <cac:Signature>
        <cbc:ID>SIGN";
            // line 52
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "ruc", [], "any", false, false, false, 52);
            yield "</cbc:ID>
        <cac:SignatoryParty>
            <cac:PartyIdentification>
                <cbc:ID>";
            // line 55
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "ruc", [], "any", false, false, false, 55);
            yield "</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyName>
                <cbc:Name><![CDATA[";
            // line 58
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "razonSocial", [], "any", false, false, false, 58);
            yield "]]></cbc:Name>
            </cac:PartyName>
        </cac:SignatoryParty>
        <cac:DigitalSignatureAttachment>
            <cac:ExternalReference>
                <cbc:URI>#GREENTER-SIGN</cbc:URI>
            </cac:ExternalReference>
        </cac:DigitalSignatureAttachment>
    </cac:Signature>
    <cac:AccountingSupplierParty>
        <cac:Party>
            <cac:PartyIdentification>
                <cbc:ID schemeID=\"6\">";
            // line 70
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "ruc", [], "any", false, false, false, 70);
            yield "</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyName>
                <cbc:Name><![CDATA[";
            // line 73
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "nombreComercial", [], "any", false, false, false, 73);
            yield "]]></cbc:Name>
            </cac:PartyName>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName><![CDATA[";
            // line 76
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "razonSocial", [], "any", false, false, false, 76);
            yield "]]></cbc:RegistrationName>
                ";
            // line 77
            $context["addr"] = CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "address", [], "any", false, false, false, 77);
            // line 78
            yield "                <cac:RegistrationAddress>
                    <cbc:ID>";
            // line 79
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "ubigueo", [], "any", false, false, false, 79);
            yield "</cbc:ID>
                    <cbc:AddressTypeCode>";
            // line 80
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "codLocal", [], "any", false, false, false, 80);
            yield "</cbc:AddressTypeCode>
                    ";
            // line 81
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "urbanizacion", [], "any", false, false, false, 81)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 82
                yield "                    <cbc:CitySubdivisionName>";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "urbanizacion", [], "any", false, false, false, 82);
                yield "</cbc:CitySubdivisionName>
                    ";
            }
            // line 84
            yield "                    <cbc:CityName>";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "provincia", [], "any", false, false, false, 84);
            yield "</cbc:CityName>
                    <cbc:CountrySubentity>";
            // line 85
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "departamento", [], "any", false, false, false, 85);
            yield "</cbc:CountrySubentity>
                    <cbc:District>";
            // line 86
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "distrito", [], "any", false, false, false, 86);
            yield "</cbc:District>
                    <cac:AddressLine>
                        <cbc:Line><![CDATA[";
            // line 88
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "direccion", [], "any", false, false, false, 88);
            yield "]]></cbc:Line>
                    </cac:AddressLine>
                    <cac:Country>
                        <cbc:IdentificationCode>";
            // line 91
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "codigoPais", [], "any", false, false, false, 91);
            yield "</cbc:IdentificationCode>
                    </cac:Country>
                </cac:RegistrationAddress>
            </cac:PartyLegalEntity>
            ";
            // line 95
            if ((CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "email", [], "any", false, false, false, 95) || CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "telephone", [], "any", false, false, false, 95))) {
                // line 96
                yield "                <cac:Contact>
                    ";
                // line 97
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "telephone", [], "any", false, false, false, 97)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 98
                    yield "                        <cbc:Telephone>";
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "telephone", [], "any", false, false, false, 98);
                    yield "</cbc:Telephone>
                    ";
                }
                // line 100
                yield "                    ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "email", [], "any", false, false, false, 100)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 101
                    yield "                        <cbc:ElectronicMail>";
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["emp"] ?? null), "email", [], "any", false, false, false, 101);
                    yield "</cbc:ElectronicMail>
                    ";
                }
                // line 103
                yield "                </cac:Contact>
            ";
            }
            // line 105
            yield "        </cac:Party>
    </cac:AccountingSupplierParty>
    ";
            // line 107
            $context["client"] = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "client", [], "any", false, false, false, 107);
            // line 108
            yield "    <cac:AccountingCustomerParty>
        <cac:Party>
            <cac:PartyIdentification>
                <cbc:ID schemeID=\"";
            // line 111
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "tipoDoc", [], "any", false, false, false, 111);
            yield "\">";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "numDoc", [], "any", false, false, false, 111);
            yield "</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName><![CDATA[";
            // line 114
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "rznSocial", [], "any", false, false, false, 114);
            yield "]]></cbc:RegistrationName>
                ";
            // line 115
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "address", [], "any", false, false, false, 115)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 116
                yield "                    ";
                $context["addr"] = CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "address", [], "any", false, false, false, 116);
                // line 117
                yield "                    <cac:RegistrationAddress>
                        ";
                // line 118
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "ubigueo", [], "any", false, false, false, 118)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 119
                    yield "                            <cbc:ID>";
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "ubigueo", [], "any", false, false, false, 119);
                    yield "</cbc:ID>
                        ";
                }
                // line 121
                yield "                        <cac:AddressLine>
                            <cbc:Line><![CDATA[";
                // line 122
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "direccion", [], "any", false, false, false, 122);
                yield "]]></cbc:Line>
                        </cac:AddressLine>
                        <cac:Country>
                            <cbc:IdentificationCode>";
                // line 125
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["addr"] ?? null), "codigoPais", [], "any", false, false, false, 125);
                yield "</cbc:IdentificationCode>
                        </cac:Country>
                    </cac:RegistrationAddress>
                ";
            }
            // line 129
            yield "            </cac:PartyLegalEntity>
            ";
            // line 130
            if ((CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "email", [], "any", false, false, false, 130) || CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "telephone", [], "any", false, false, false, 130))) {
                // line 131
                yield "                <cac:Contact>
                    ";
                // line 132
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "telephone", [], "any", false, false, false, 132)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 133
                    yield "                        <cbc:Telephone>";
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "telephone", [], "any", false, false, false, 133);
                    yield "</cbc:Telephone>
                    ";
                }
                // line 135
                yield "                    ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "email", [], "any", false, false, false, 135)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 136
                    yield "                        <cbc:ElectronicMail>";
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["client"] ?? null), "email", [], "any", false, false, false, 136);
                    yield "</cbc:ElectronicMail>
                    ";
                }
                // line 138
                yield "                </cac:Contact>
            ";
            }
            // line 140
            yield "        </cac:Party>
    </cac:AccountingCustomerParty>
    ";
            // line 142
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "formaPago", [], "any", false, false, false, 142)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 143
                yield "    <cac:PaymentTerms>
        <cbc:ID>FormaPago</cbc:ID>
        <cbc:PaymentMeansID>";
                // line 145
                yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "formaPago", [], "any", false, false, false, 145), "tipo", [], "any", false, false, false, 145);
                yield "</cbc:PaymentMeansID>
        ";
                // line 146
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "formaPago", [], "any", false, false, false, 146), "monto", [], "any", false, false, false, 146)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 147
                    yield "        <cbc:Amount currencyID=\"";
                    yield ((CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "formaPago", [], "any", false, true, false, 147), "moneda", [], "any", true, true, false, 147)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "formaPago", [], "any", false, false, false, 147), "moneda", [], "any", false, false, false, 147), CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 147))) : (CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 147)));
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "formaPago", [], "any", false, false, false, 147), "monto", [], "any", false, false, false, 147));
                    yield "</cbc:Amount>
        ";
                }
                // line 149
                yield "    </cac:PaymentTerms>
    ";
            }
            // line 151
            yield "    ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "cuotas", [], "any", false, false, false, 151)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 152
                yield "    ";
                $context['_parent'] = $context;
                $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "cuotas", [], "any", false, false, false, 152));
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
                foreach ($context['_seq'] as $context["_key"] => $context["cuota"]) {
                    // line 153
                    yield "    <cac:PaymentTerms>
        <cbc:ID>FormaPago</cbc:ID>
        <cbc:PaymentMeansID>Cuota";
                    // line 155
                    yield Twig\Extension\CoreExtension::sprintf("%03d", CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 155));
                    yield "</cbc:PaymentMeansID>
        <cbc:Amount currencyID=\"";
                    // line 156
                    yield ((CoreExtension::getAttribute($this->env, $this->source, $context["cuota"], "moneda", [], "any", true, true, false, 156)) ? (Twig\Extension\CoreExtension::default(CoreExtension::getAttribute($this->env, $this->source, $context["cuota"], "moneda", [], "any", false, false, false, 156), CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 156))) : (CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 156)));
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["cuota"], "monto", [], "any", false, false, false, 156));
                    yield "</cbc:Amount>
        <cbc:PaymentDueDate>";
                    // line 157
                    yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, $context["cuota"], "fechaPago", [], "any", false, false, false, 157), "Y-m-d");
                    yield "</cbc:PaymentDueDate>
    </cac:PaymentTerms>
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
                unset($context['_seq'], $context['_key'], $context['cuota'], $context['_parent'], $context['loop']);
                $context = array_intersect_key($context, $_parent) + $_parent;
                // line 160
                yield "    ";
            }
            // line 161
            yield "    <cac:TaxTotal>
        <cbc:TaxAmount currencyID=\"";
            // line 162
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 162);
            yield "\">";
            yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "totalImpuestos", [], "any", false, false, false, 162));
            yield "</cbc:TaxAmount>
        ";
            // line 163
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoISC", [], "any", false, false, false, 163)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 164
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 165
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 165);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoBaseIsc", [], "any", false, false, false, 165));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 166
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 166);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoISC", [], "any", false, false, false, 166));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>2000</cbc:ID>
                        <cbc:Name>ISC</cbc:Name>
                        <cbc:TaxTypeCode>EXC</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 176
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperGravadas", [], "any", false, false, false, 176)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 177
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 178
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 178);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperGravadas", [], "any", false, false, false, 178));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 179
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 179);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoIGV", [], "any", false, false, false, 179));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>1000</cbc:ID>
                        <cbc:Name>IGV</cbc:Name>
                        <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 189
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperInafectas", [], "any", false, false, false, 189)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 190
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 191
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 191);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperInafectas", [], "any", false, false, false, 191));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 192
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 192);
                yield "\">0</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>9998</cbc:ID>
                        <cbc:Name>INA</cbc:Name>
                        <cbc:TaxTypeCode>FRE</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 202
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperExoneradas", [], "any", false, false, false, 202)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 203
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 204
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 204);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperExoneradas", [], "any", false, false, false, 204));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 205
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 205);
                yield "\">0</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>9997</cbc:ID>
                        <cbc:Name>EXO</cbc:Name>
                        <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 215
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperGratuitas", [], "any", false, false, false, 215)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 216
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 217
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 217);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperGratuitas", [], "any", false, false, false, 217));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 218
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 218);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoIGVGratuitas", [], "any", false, false, false, 218));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>9996</cbc:ID>
                        <cbc:Name>GRA</cbc:Name>
                        <cbc:TaxTypeCode>FRE</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 228
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperExportacion", [], "any", false, false, false, 228)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 229
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 230
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 230);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOperExportacion", [], "any", false, false, false, 230));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 231
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 231);
                yield "\">0</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>9995</cbc:ID>
                        <cbc:Name>EXP</cbc:Name>
                        <cbc:TaxTypeCode>FRE</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 241
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoIvap", [], "any", false, false, false, 241)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 242
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 243
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 243);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoBaseIvap", [], "any", false, false, false, 243));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 244
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 244);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoIvap", [], "any", false, false, false, 244));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>1016</cbc:ID>
                        <cbc:Name>IVAP</cbc:Name>
                        <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 254
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOtrosTributos", [], "any", false, false, false, 254)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 255
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 256
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 256);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoBaseOth", [], "any", false, false, false, 256));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 257
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 257);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoOtrosTributos", [], "any", false, false, false, 257));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>9999</cbc:ID>
                        <cbc:Name>OTROS</cbc:Name>
                        <cbc:TaxTypeCode>OTH</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 267
            yield "        ";
            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "icbper", [], "any", false, false, false, 267)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 268
                yield "            <cac:TaxSubtotal>
                <cbc:TaxAmount currencyID=\"";
                // line 269
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 269);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "icbper", [], "any", false, false, false, 269));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cac:TaxScheme>
                        <cbc:ID>7152</cbc:ID>
                        <cbc:Name>ICBPER</cbc:Name>
                        <cbc:TaxTypeCode>OTH</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
        ";
            }
            // line 279
            yield "    </cac:TaxTotal>
    <cac:LegalMonetaryTotal>
        ";
            // line 281
            if ((($tmp =  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "sumOtrosCargos", [], "any", false, false, false, 281))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 282
                yield "        <cbc:ChargeTotalAmount currencyID=\"";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 282);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "sumOtrosCargos", [], "any", false, false, false, 282));
                yield "</cbc:ChargeTotalAmount>
        ";
            }
            // line 284
            yield "        ";
            if ((($tmp =  !(null === CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "redondeo", [], "any", false, false, false, 284))) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                // line 285
                yield "        <cbc:PayableRoundingAmount currencyID=\"";
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 285);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "redondeo", [], "any", false, false, false, 285));
                yield "</cbc:PayableRoundingAmount>
        ";
            }
            // line 287
            yield "        <cbc:PayableAmount currencyID=\"";
            yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 287);
            yield "\">";
            yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "mtoImpVenta", [], "any", false, false, false, 287));
            yield "</cbc:PayableAmount>
    </cac:LegalMonetaryTotal>
    ";
            // line 289
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "details", [], "any", false, false, false, 289));
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
            foreach ($context['_seq'] as $context["_key"] => $context["detail"]) {
                // line 290
                yield "    <cac:CreditNoteLine>
        <cbc:ID>";
                // line 291
                yield CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 291);
                yield "</cbc:ID>
        <cbc:CreditedQuantity unitCode=\"";
                // line 292
                yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "unidad", [], "any", false, false, false, 292);
                yield "\">";
                yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "cantidad", [], "any", false, false, false, 292);
                yield "</cbc:CreditedQuantity>
        <cbc:LineExtensionAmount currencyID=\"";
                // line 293
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 293);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoValorVenta", [], "any", false, false, false, 293));
                yield "</cbc:LineExtensionAmount>
        <cac:PricingReference>
            ";
                // line 295
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoValorGratuito", [], "any", false, false, false, 295)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 296
                    yield "            <cac:AlternativeConditionPrice>
                <cbc:PriceAmount currencyID=\"";
                    // line 297
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 297);
                    yield "\">";
                    yield $this->env->getFilter('n_format_limit')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoValorGratuito", [], "any", false, false, false, 297), 10);
                    yield "</cbc:PriceAmount>
                <cbc:PriceTypeCode>02</cbc:PriceTypeCode>
            </cac:AlternativeConditionPrice>
            ";
                } else {
                    // line 301
                    yield "            <cac:AlternativeConditionPrice>
                <cbc:PriceAmount currencyID=\"";
                    // line 302
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 302);
                    yield "\">";
                    yield $this->env->getFilter('n_format_limit')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoPrecioUnitario", [], "any", false, false, false, 302), 10);
                    yield "</cbc:PriceAmount>
                <cbc:PriceTypeCode>01</cbc:PriceTypeCode>
            </cac:AlternativeConditionPrice>
            ";
                }
                // line 306
                yield "        </cac:PricingReference>
        <cac:TaxTotal>
            <cbc:TaxAmount currencyID=\"";
                // line 308
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 308);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "totalImpuestos", [], "any", false, false, false, 308));
                yield "</cbc:TaxAmount>
            ";
                // line 309
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "isc", [], "any", false, false, false, 309)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 310
                    yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                    // line 311
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 311);
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoBaseIsc", [], "any", false, false, false, 311));
                    yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                    // line 312
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 312);
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "isc", [], "any", false, false, false, 312));
                    yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cbc:Percent>";
                    // line 314
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "porcentajeIsc", [], "any", false, false, false, 314);
                    yield "</cbc:Percent>
                    <cbc:TierRange>";
                    // line 315
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "tipSisIsc", [], "any", false, false, false, 315);
                    yield "</cbc:TierRange>
                    <cac:TaxScheme>
                        <cbc:ID>2000</cbc:ID>
                        <cbc:Name>ISC</cbc:Name>
                        <cbc:TaxTypeCode>EXC</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
            ";
                }
                // line 324
                yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                // line 325
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 325);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoBaseIgv", [], "any", false, false, false, 325));
                yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                // line 326
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 326);
                yield "\">";
                yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "igv", [], "any", false, false, false, 326));
                yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cbc:Percent>";
                // line 328
                yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "porcentajeIgv", [], "any", false, false, false, 328);
                yield "</cbc:Percent>
                    <cbc:TaxExemptionReasonCode>";
                // line 329
                yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "tipAfeIgv", [], "any", false, false, false, 329);
                yield "</cbc:TaxExemptionReasonCode>
                    ";
                // line 330
                $context["afect"] = Greenter\Xml\Filter\TributoFunction::getByAfectacion(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "tipAfeIgv", [], "any", false, false, false, 330));
                // line 331
                yield "                    <cac:TaxScheme>
                        <cbc:ID>";
                // line 332
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["afect"] ?? null), "id", [], "any", false, false, false, 332);
                yield "</cbc:ID>
                        <cbc:Name>";
                // line 333
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["afect"] ?? null), "name", [], "any", false, false, false, 333);
                yield "</cbc:Name>
                        <cbc:TaxTypeCode>";
                // line 334
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["afect"] ?? null), "code", [], "any", false, false, false, 334);
                yield "</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
            ";
                // line 338
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "otroTributo", [], "any", false, false, false, 338)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 339
                    yield "            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID=\"";
                    // line 340
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 340);
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoBaseOth", [], "any", false, false, false, 340));
                    yield "</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID=\"";
                    // line 341
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 341);
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "otroTributo", [], "any", false, false, false, 341));
                    yield "</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cbc:Percent>";
                    // line 343
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "porcentajeOth", [], "any", false, false, false, 343);
                    yield "</cbc:Percent>
                    <cac:TaxScheme>
                        <cbc:ID>9999</cbc:ID>
                        <cbc:Name>OTROS</cbc:Name>
                        <cbc:TaxTypeCode>OTH</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
            ";
                }
                // line 352
                yield "            ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "icbper", [], "any", false, false, false, 352)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 353
                    yield "            <cac:TaxSubtotal>
                <cbc:TaxAmount currencyID=\"";
                    // line 354
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 354);
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "icbper", [], "any", false, false, false, 354));
                    yield "</cbc:TaxAmount>
                <cbc:BaseUnitMeasure unitCode=\"";
                    // line 355
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "unidad", [], "any", false, false, false, 355);
                    yield "\">";
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "cantidad", [], "any", false, false, false, 355);
                    yield "</cbc:BaseUnitMeasure>
                <cac:TaxCategory>
                    <cbc:PerUnitAmount currencyID=\"";
                    // line 357
                    yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 357);
                    yield "\">";
                    yield $this->env->getFilter('n_format')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "factorIcbper", [], "any", false, false, false, 357));
                    yield "</cbc:PerUnitAmount>
                    <cac:TaxScheme>
                        <cbc:ID>7152</cbc:ID>
                        <cbc:Name>ICBPER</cbc:Name>
                        <cbc:TaxTypeCode>OTH</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>
            ";
                }
                // line 366
                yield "        </cac:TaxTotal>
        <cac:Item>
            <cbc:Description><![CDATA[";
                // line 368
                yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "descripcion", [], "any", false, false, false, 368);
                yield "]]></cbc:Description>
            ";
                // line 369
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "codProducto", [], "any", false, false, false, 369)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 370
                    yield "                <cac:SellersItemIdentification>
                    <cbc:ID>";
                    // line 371
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "codProducto", [], "any", false, false, false, 371);
                    yield "</cbc:ID>
                </cac:SellersItemIdentification>
            ";
                }
                // line 374
                yield "            ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "codProdGS1", [], "any", false, false, false, 374)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 375
                    yield "                <cac:StandardItemIdentification>
                    <cbc:ID>";
                    // line 376
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "codProdGS1", [], "any", false, false, false, 376);
                    yield "</cbc:ID>
                </cac:StandardItemIdentification>
            ";
                }
                // line 379
                yield "            ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "codProdSunat", [], "any", false, false, false, 379)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 380
                    yield "                <cac:CommodityClassification>
                    <cbc:ItemClassificationCode>";
                    // line 381
                    yield CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "codProdSunat", [], "any", false, false, false, 381);
                    yield "</cbc:ItemClassificationCode>
                </cac:CommodityClassification>
            ";
                }
                // line 384
                yield "            ";
                if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "atributos", [], "any", false, false, false, 384)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 385
                    yield "                ";
                    $context['_parent'] = $context;
                    $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "atributos", [], "any", false, false, false, 385));
                    foreach ($context['_seq'] as $context["_key"] => $context["atr"]) {
                        // line 386
                        yield "                    <cac:AdditionalItemProperty >
                        <cbc:Name>";
                        // line 387
                        yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "name", [], "any", false, false, false, 387);
                        yield "</cbc:Name>
                        <cbc:NameCode>";
                        // line 388
                        yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "code", [], "any", false, false, false, 388);
                        yield "</cbc:NameCode>
                        ";
                        // line 389
                        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "value", [], "any", false, false, false, 389)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                            // line 390
                            yield "                            <cbc:Value>";
                            yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "value", [], "any", false, false, false, 390);
                            yield "</cbc:Value>
                        ";
                        }
                        // line 392
                        yield "                        ";
                        if (((CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "fecInicio", [], "any", false, false, false, 392) || CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "fecFin", [], "any", false, false, false, 392)) || CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "duracion", [], "any", false, false, false, 392))) {
                            // line 393
                            yield "                            <cac:UsabilityPeriod>
                                ";
                            // line 394
                            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "fecInicio", [], "any", false, false, false, 394)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                                // line 395
                                yield "                                    <cbc:StartDate>";
                                yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "fecInicio", [], "any", false, false, false, 395), "Y-m-d");
                                yield "</cbc:StartDate>
                                ";
                            }
                            // line 397
                            yield "                                ";
                            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "fecFin", [], "any", false, false, false, 397)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                                // line 398
                                yield "                                    <cbc:EndDate>";
                                yield $this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "fecFin", [], "any", false, false, false, 398), "Y-m-d");
                                yield "</cbc:EndDate>
                                ";
                            }
                            // line 400
                            yield "                                ";
                            if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "duracion", [], "any", false, false, false, 400)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                                // line 401
                                yield "                                    <cbc:DurationMeasure unitCode=\"DAY\">";
                                yield CoreExtension::getAttribute($this->env, $this->source, $context["atr"], "duracion", [], "any", false, false, false, 401);
                                yield "</cbc:DurationMeasure>
                                ";
                            }
                            // line 403
                            yield "                            </cac:UsabilityPeriod>
                        ";
                        }
                        // line 405
                        yield "                    </cac:AdditionalItemProperty>
                ";
                    }
                    $_parent = $context['_parent'];
                    unset($context['_seq'], $context['_key'], $context['atr'], $context['_parent']);
                    $context = array_intersect_key($context, $_parent) + $_parent;
                    // line 407
                    yield "            ";
                }
                // line 408
                yield "        </cac:Item>
        <cac:Price>
            <cbc:PriceAmount currencyID=\"";
                // line 410
                yield CoreExtension::getAttribute($this->env, $this->source, ($context["doc"] ?? null), "tipoMoneda", [], "any", false, false, false, 410);
                yield "\">";
                yield $this->env->getFilter('n_format_limit')->getCallable()(CoreExtension::getAttribute($this->env, $this->source, $context["detail"], "mtoValorUnitario", [], "any", false, false, false, 410), 10);
                yield "</cbc:PriceAmount>
        </cac:Price>
    </cac:CreditNoteLine>
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
            unset($context['_seq'], $context['_key'], $context['detail'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 414
            yield "</CreditNote>
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
        return "notacr2.1.xml.twig";
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
        return array (  1102 => 1,  1097 => 414,  1077 => 410,  1073 => 408,  1070 => 407,  1063 => 405,  1059 => 403,  1053 => 401,  1050 => 400,  1044 => 398,  1041 => 397,  1035 => 395,  1033 => 394,  1030 => 393,  1027 => 392,  1021 => 390,  1019 => 389,  1015 => 388,  1011 => 387,  1008 => 386,  1003 => 385,  1000 => 384,  994 => 381,  991 => 380,  988 => 379,  982 => 376,  979 => 375,  976 => 374,  970 => 371,  967 => 370,  965 => 369,  961 => 368,  957 => 366,  943 => 357,  936 => 355,  930 => 354,  927 => 353,  924 => 352,  912 => 343,  905 => 341,  899 => 340,  896 => 339,  894 => 338,  887 => 334,  883 => 333,  879 => 332,  876 => 331,  874 => 330,  870 => 329,  866 => 328,  859 => 326,  853 => 325,  850 => 324,  838 => 315,  834 => 314,  827 => 312,  821 => 311,  818 => 310,  816 => 309,  810 => 308,  806 => 306,  797 => 302,  794 => 301,  785 => 297,  782 => 296,  780 => 295,  773 => 293,  767 => 292,  763 => 291,  760 => 290,  743 => 289,  735 => 287,  727 => 285,  724 => 284,  716 => 282,  714 => 281,  710 => 279,  695 => 269,  692 => 268,  689 => 267,  674 => 257,  668 => 256,  665 => 255,  662 => 254,  647 => 244,  641 => 243,  638 => 242,  635 => 241,  622 => 231,  616 => 230,  613 => 229,  610 => 228,  595 => 218,  589 => 217,  586 => 216,  583 => 215,  570 => 205,  564 => 204,  561 => 203,  558 => 202,  545 => 192,  539 => 191,  536 => 190,  533 => 189,  518 => 179,  512 => 178,  509 => 177,  506 => 176,  491 => 166,  485 => 165,  482 => 164,  480 => 163,  474 => 162,  471 => 161,  468 => 160,  451 => 157,  445 => 156,  441 => 155,  437 => 153,  419 => 152,  416 => 151,  412 => 149,  404 => 147,  402 => 146,  398 => 145,  394 => 143,  392 => 142,  388 => 140,  384 => 138,  378 => 136,  375 => 135,  369 => 133,  367 => 132,  364 => 131,  362 => 130,  359 => 129,  352 => 125,  346 => 122,  343 => 121,  337 => 119,  335 => 118,  332 => 117,  329 => 116,  327 => 115,  323 => 114,  315 => 111,  310 => 108,  308 => 107,  304 => 105,  300 => 103,  294 => 101,  291 => 100,  285 => 98,  283 => 97,  280 => 96,  278 => 95,  271 => 91,  265 => 88,  260 => 86,  256 => 85,  251 => 84,  245 => 82,  243 => 81,  239 => 80,  235 => 79,  232 => 78,  230 => 77,  226 => 76,  220 => 73,  214 => 70,  199 => 58,  193 => 55,  187 => 52,  184 => 51,  181 => 50,  178 => 49,  169 => 46,  165 => 45,  162 => 44,  157 => 43,  154 => 42,  151 => 41,  142 => 38,  138 => 37,  135 => 36,  130 => 35,  128 => 34,  122 => 31,  118 => 30,  114 => 28,  108 => 25,  105 => 24,  103 => 23,  98 => 21,  94 => 20,  90 => 19,  84 => 17,  73 => 15,  69 => 14,  65 => 13,  61 => 12,  55 => 11,  44 => 2,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "notacr2.1.xml.twig", "D:\\tukifac_premium_prod\\facturador_lycet\\vendor\\greenter\\xml\\src\\Xml\\Templates\\notacr2.1.xml.twig");
    }
}
