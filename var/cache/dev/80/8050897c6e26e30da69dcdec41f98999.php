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

/* despatch.html.twig */
class __TwigTemplate_5251a8b2a75bfc5dabd6d5ddc872273f extends Template
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
        yield "<html>
<head>
    <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">
    <style type=\"text/css\">
        ";
        // line 5
        yield from $this->load("assets/style.css", 5)->unwrap()->yield($context);
        yield "td{padding: 3px;}
    </style>
</head>
<body class=\"white-bg\">
";
        // line 9
        $context["cp"] = CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 9, $this->source); })()), "company", [], "any", false, false, false, 9);
        // line 10
        $context["name"] = $this->env->getRuntime('Greenter\Report\Filter\DocumentFilter')->getValueCatalog(CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 10, $this->source); })()), "tipoDoc", [], "any", false, false, false, 10), "01");
        // line 11
        yield "<table width=\"100%\">
    <tbody><tr>
        <td style=\"padding:30px; !important\">
            <table width=\"100%\" height=\"200px\" border=\"0\" aling=\"center\" cellpadding=\"0\" cellspacing=\"0\">
                <tbody><tr>
                    <td width=\"50%\" height=\"90\" align=\"center\">
                        <span><img src=\"";
        // line 17
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('App\Report\Filter\SafeImageFilter')->toBase64(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["params"]) || array_key_exists("params", $context) ? $context["params"] : (function () { throw new RuntimeError('Variable "params" does not exist.', 17, $this->source); })()), "system", [], "any", false, false, false, 17), "logo", [], "any", false, false, false, 17)), "html", null, true);
        yield "\" height=\"80\" style=\"text-align:center\" border=\"0\"></span>
                    </td>
                    <td width=\"5%\" height=\"40\" align=\"center\"></td>
                    <td width=\"45%\" rowspan=\"2\" valign=\"bottom\" style=\"padding-left:0\">
                        <div class=\"tabla_borde\">
                            <table width=\"100%\" border=\"0\" height=\"200\" cellpadding=\"6\" cellspacing=\"0\">
                                <tbody><tr>
                                    <td align=\"center\">
                                        <span style=\"font-family:Tahoma, Geneva, sans-serif; font-size:29px\" text-align=\"center\">";
        // line 25
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((isset($context["name"]) || array_key_exists("name", $context) ? $context["name"] : (function () { throw new RuntimeError('Variable "name" does not exist.', 25, $this->source); })()), "html", null, true);
        yield "</span>
                                        <br>
                                        <span style=\"font-family:Tahoma, Geneva, sans-serif; font-size:19px\" text-align=\"center\">E L E C T R Ó N I C A</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td align=\"center\">
                                        <span style=\"font-size:15px\" text-align=\"center\">R.U.C.: ";
        // line 32
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["cp"]) || array_key_exists("cp", $context) ? $context["cp"] : (function () { throw new RuntimeError('Variable "cp" does not exist.', 32, $this->source); })()), "ruc", [], "any", false, false, false, 32), "html", null, true);
        yield "</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td align=\"center\">
                                        <span style=\"font-size:24px\">";
        // line 37
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 37, $this->source); })()), "serie", [], "any", false, false, false, 37), "html", null, true);
        yield "-";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 37, $this->source); })()), "correlativo", [], "any", false, false, false, 37), "html", null, true);
        yield "</span>
                                    </td>
                                </tr>
                                </tbody></table>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td valign=\"bottom\" style=\"padding-left:0\">
                        <div class=\"tabla_borde\">
                            <table width=\"96%\" height=\"100%\" border=\"0\" border-radius=\"\" cellpadding=\"9\" cellspacing=\"0\">
                                <tbody><tr>
                                    <td align=\"center\">
                                        <strong><span style=\"font-size:15px\">";
        // line 50
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["cp"]) || array_key_exists("cp", $context) ? $context["cp"] : (function () { throw new RuntimeError('Variable "cp" does not exist.', 50, $this->source); })()), "razonSocial", [], "any", false, false, false, 50), "html", null, true);
        yield "</span></strong>
                                    </td>
                                </tr>
                                <tr>
                                    <td align=\"left\">
                                        <strong>Dirección: </strong>";
        // line 55
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["cp"]) || array_key_exists("cp", $context) ? $context["cp"] : (function () { throw new RuntimeError('Variable "cp" does not exist.', 55, $this->source); })()), "address", [], "any", false, false, false, 55), "direccion", [], "any", false, false, false, 55), "html", null, true);
        yield "
                                    </td>
                                </tr>
                                <tr>
                                    <td align=\"left\">
                                        ";
        // line 60
        yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["params"]) || array_key_exists("params", $context) ? $context["params"] : (function () { throw new RuntimeError('Variable "params" does not exist.', 60, $this->source); })()), "user", [], "any", false, false, false, 60), "header", [], "any", false, false, false, 60);
        yield "
                                    </td>
                                </tr>
                                </tbody></table>
                        </div>
                    </td>
                </tr>
                </tbody></table>
            <br>
            <div class=\"tabla_borde\">
                ";
        // line 70
        $context["cl"] = CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 70, $this->source); })()), "destinatario", [], "any", false, false, false, 70);
        // line 71
        yield "                <table width=\"100%\" border=\"0\" cellpadding=\"5\" cellspacing=\"0\">
                    <tbody>
                    <tr>
                        <td colspan=\"2\">DESTINATARIO</td>
                    </tr>
                    <tr class=\"border_top\">
                        <td width=\"60%\" align=\"left\"><strong>Razón Social:</strong>  ";
        // line 77
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["cl"]) || array_key_exists("cl", $context) ? $context["cl"] : (function () { throw new RuntimeError('Variable "cl" does not exist.', 77, $this->source); })()), "rznSocial", [], "any", false, false, false, 77), "html", null, true);
        yield "</td>
                        <td width=\"40%\" align=\"left\"><strong>";
        // line 78
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('Greenter\Report\Filter\DocumentFilter')->getValueCatalog(CoreExtension::getAttribute($this->env, $this->source, (isset($context["cl"]) || array_key_exists("cl", $context) ? $context["cl"] : (function () { throw new RuntimeError('Variable "cl" does not exist.', 78, $this->source); })()), "tipoDoc", [], "any", false, false, false, 78), "06"), "html", null, true);
        yield ":</strong>  ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["cl"]) || array_key_exists("cl", $context) ? $context["cl"] : (function () { throw new RuntimeError('Variable "cl" does not exist.', 78, $this->source); })()), "numDoc", [], "any", false, false, false, 78), "html", null, true);
        yield "</td>
                    </tr>
                    <tr>
                        <td width=\"40%\" align=\"left\" colspan=\"2\"><strong>Dirección:</strong>  ";
        // line 81
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, (isset($context["cl"]) || array_key_exists("cl", $context) ? $context["cl"] : (function () { throw new RuntimeError('Variable "cl" does not exist.', 81, $this->source); })()), "address", [], "any", false, false, false, 81)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["cl"]) || array_key_exists("cl", $context) ? $context["cl"] : (function () { throw new RuntimeError('Variable "cl" does not exist.', 81, $this->source); })()), "address", [], "any", false, false, false, 81), "direccion", [], "any", false, false, false, 81), "html", null, true);
        }
        yield "</td>
                    </tr>
                    </tbody></table>
            </div><br>
            <div class=\"tabla_borde\">
                ";
        // line 86
        $context["cl"] = CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 86, $this->source); })()), "destinatario", [], "any", false, false, false, 86);
        // line 87
        yield "                <table width=\"100%\" border=\"0\" cellpadding=\"5\" cellspacing=\"0\">
                    <tbody>
                    <tr>
                        <td colspan=\"2\">ENVIO</td>
                    </tr>
                    <tr class=\"border_top\">
                        <td width=\"60%\" align=\"left\">
                            <strong>Fecha Emisión:</strong>  ";
        // line 94
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 94, $this->source); })()), "fechaEmision", [], "any", false, false, false, 94), "d/m/Y"), "html", null, true);
        yield "
                        </td>
                        <td width=\"40%\" align=\"left\"><strong>Fecha Inicio de Traslado:</strong>  ";
        // line 96
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->extensions['Twig\Extension\CoreExtension']->formatDate(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 96, $this->source); })()), "envio", [], "any", false, false, false, 96), "fecTraslado", [], "any", false, false, false, 96), "d/m/Y"), "html", null, true);
        yield " </td>
                    </tr>
                    <tr>
                        <td width=\"60%\" align=\"left\"><strong>Motivo Traslado:</strong>  ";
        // line 99
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('Greenter\Report\Filter\DocumentFilter')->getValueCatalog(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 99, $this->source); })()), "envio", [], "any", false, false, false, 99), "codTraslado", [], "any", false, false, false, 99), "20"), "html", null, true);
        yield " </td>
                        <td width=\"40%\" align=\"left\"><strong>Modalidad de Transporte:</strong>  ";
        // line 100
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('Greenter\Report\Filter\DocumentFilter')->getValueCatalog(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 100, $this->source); })()), "envio", [], "any", false, false, false, 100), "modTraslado", [], "any", false, false, false, 100), "18"), "html", null, true);
        yield " </td>
                    </tr>
                    <tr>
                        <td width=\"60%\" align=\"left\"><strong>Peso Bruto Total (";
        // line 103
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 103, $this->source); })()), "envio", [], "any", false, false, false, 103), "undPesoTotal", [], "any", false, false, false, 103), "html", null, true);
        yield "):</strong>  ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 103, $this->source); })()), "envio", [], "any", false, false, false, 103), "pesoTotal", [], "any", false, false, false, 103), "html", null, true);
        yield " </td>
                        <td width=\"40%\">";
        // line 104
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 104, $this->source); })()), "envio", [], "any", false, false, false, 104), "numBultos", [], "any", false, false, false, 104)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            yield "<strong>Número de Bultos:</strong>  ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 104, $this->source); })()), "envio", [], "any", false, false, false, 104), "numBultos", [], "any", false, false, false, 104), "html", null, true);
        }
        yield "</td>
                    </tr>
                    <tr>
                        <td width=\"60%\" align=\"left\"><strong>P. Partida:</strong>  ";
        // line 107
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 107, $this->source); })()), "envio", [], "any", false, false, false, 107), "partida", [], "any", false, false, false, 107), "ubigueo", [], "any", false, false, false, 107), "html", null, true);
        yield " - ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 107, $this->source); })()), "envio", [], "any", false, false, false, 107), "partida", [], "any", false, false, false, 107), "direccion", [], "any", false, false, false, 107), "html", null, true);
        yield "</td>
                        <td width=\"40%\" align=\"left\"><strong>P. Llegada: </strong>  ";
        // line 108
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 108, $this->source); })()), "envio", [], "any", false, false, false, 108), "llegada", [], "any", false, false, false, 108), "ubigueo", [], "any", false, false, false, 108), "html", null, true);
        yield " - ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 108, $this->source); })()), "envio", [], "any", false, false, false, 108), "llegada", [], "any", false, false, false, 108), "direccion", [], "any", false, false, false, 108), "html", null, true);
        yield "</td>
                    </tr>
                    </tbody></table>
            </div><br>
            ";
        // line 112
        $context["transportista"] = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 112, $this->source); })()), "envio", [], "any", false, false, false, 112), "transportista", [], "any", false, false, false, 112);
        // line 113
        yield "            ";
        $context["vehiculo"] = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 113, $this->source); })()), "envio", [], "any", false, false, false, 113), "vehiculo", [], "any", false, false, false, 113);
        // line 114
        yield "            ";
        $context["choferes"] = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 114, $this->source); })()), "envio", [], "any", false, false, false, 114), "choferes", [], "any", false, false, false, 114);
        // line 115
        yield "            ";
        $context["indicadores"] = CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 115, $this->source); })()), "envio", [], "any", false, false, false, 115), "indicadores", [], "any", false, false, false, 115);
        // line 116
        yield "            <div class=\"tabla_borde\">
                <table width=\"100%\" border=\"0\" cellpadding=\"5\" cellspacing=\"0\">
                    <tbody>
                    <tr>
                        <td colspan=\"2\">TRANSPORTE</td>
                    </tr>
                    ";
        // line 122
        if ((($tmp = (isset($context["transportista"]) || array_key_exists("transportista", $context) ? $context["transportista"] : (function () { throw new RuntimeError('Variable "transportista" does not exist.', 122, $this->source); })())) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 123
            yield "                    <tr class=\"border_top\">
                        <td width=\"60%\" align=\"left\"><strong>Razón Social:</strong>  ";
            // line 124
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["transportista"]) || array_key_exists("transportista", $context) ? $context["transportista"] : (function () { throw new RuntimeError('Variable "transportista" does not exist.', 124, $this->source); })()), "rznSocial", [], "any", false, false, false, 124), "html", null, true);
            yield "</td>
                        <td width=\"40%\" align=\"left\"><strong>";
            // line 125
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('Greenter\Report\Filter\DocumentFilter')->getValueCatalog(CoreExtension::getAttribute($this->env, $this->source, (isset($context["transportista"]) || array_key_exists("transportista", $context) ? $context["transportista"] : (function () { throw new RuntimeError('Variable "transportista" does not exist.', 125, $this->source); })()), "tipoDoc", [], "any", false, false, false, 125), "06"), "html", null, true);
            yield ":</strong>  ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["transportista"]) || array_key_exists("transportista", $context) ? $context["transportista"] : (function () { throw new RuntimeError('Variable "transportista" does not exist.', 125, $this->source); })()), "numDoc", [], "any", false, false, false, 125), "html", null, true);
            yield "</td>
                    </tr>
                    ";
        }
        // line 128
        yield "                    ";
        if (((isset($context["vehiculo"]) || array_key_exists("vehiculo", $context) ? $context["vehiculo"] : (function () { throw new RuntimeError('Variable "vehiculo" does not exist.', 128, $this->source); })()) || (isset($context["choferes"]) || array_key_exists("choferes", $context) ? $context["choferes"] : (function () { throw new RuntimeError('Variable "choferes" does not exist.', 128, $this->source); })()))) {
            // line 129
            yield "                    <tr>
                        <td width=\"60%\" align=\"left\">
                            <strong>Vehículo principal:</strong>  ";
            // line 131
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["vehiculo"]) || array_key_exists("vehiculo", $context) ? $context["vehiculo"] : (function () { throw new RuntimeError('Variable "vehiculo" does not exist.', 131, $this->source); })()), "placa", [], "any", false, false, false, 131), "html", null, true);
            yield "
                            ";
            // line 132
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, (isset($context["vehiculo"]) || array_key_exists("vehiculo", $context) ? $context["vehiculo"] : (function () { throw new RuntimeError('Variable "vehiculo" does not exist.', 132, $this->source); })()), "secundarios", [], "any", false, false, false, 132));
            foreach ($context['_seq'] as $context["_key"] => $context["secundario"]) {
                // line 133
                yield "                            <br>
                            <strong>Vehículo secundario:</strong>  ";
                // line 134
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["secundario"], "placa", [], "any", false, false, false, 134), "html", null, true);
                yield "
                            ";
            }
            $_parent = $context['_parent'];
            unset($context['_seq'], $context['_key'], $context['secundario'], $context['_parent']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 136
            yield "                        </td>
                        <td width=\"40%\" align=\"left\">
                            ";
            // line 138
            $context['_parent'] = $context;
            $context['_seq'] = CoreExtension::ensureTraversable((isset($context["choferes"]) || array_key_exists("choferes", $context) ? $context["choferes"] : (function () { throw new RuntimeError('Variable "choferes" does not exist.', 138, $this->source); })()));
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
            foreach ($context['_seq'] as $context["_key"] => $context["chofer"]) {
                // line 139
                yield "                            <strong>Conductor ";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "tipo", [], "any", false, false, false, 139), "html", null, true);
                yield ":</strong>  ";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('Greenter\Report\Filter\DocumentFilter')->getValueCatalog(CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "tipoDoc", [], "any", false, false, false, 139), "06"), "html", null, true);
                yield " ";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "nroDoc", [], "any", false, false, false, 139), "html", null, true);
                yield " - ";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "nombres", [], "any", false, false, false, 139), "html", null, true);
                yield " ";
                yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["chofer"], "apellidos", [], "any", false, false, false, 139), "html", null, true);
                yield "
                            ";
                // line 140
                if ((($tmp =  !CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "last", [], "any", false, false, false, 140)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
                    // line 141
                    yield "                            <br>
                            ";
                }
                // line 143
                yield "                            ";
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
            unset($context['_seq'], $context['_key'], $context['chofer'], $context['_parent'], $context['loop']);
            $context = array_intersect_key($context, $_parent) + $_parent;
            // line 144
            yield "                        </td>
                    </tr>
                    ";
        }
        // line 147
        yield "                    </tbody></table>
            </div><br>
            <div class=\"tabla_borde\">
                <table width=\"100%\" border=\"0\" cellpadding=\"5\" cellspacing=\"0\">
                    <tbody>
                    <tr>
                        <td align=\"center\" class=\"bold\">Item</td>
                        <td align=\"center\" class=\"bold\">Código</td>
                        <td align=\"center\" class=\"bold\" width=\"300px\">Descripción</td>
                        <td align=\"center\" class=\"bold\">Unidad</td>
                        <td align=\"center\" class=\"bold\">Cantidad</td>
                    </tr>
                        ";
        // line 159
        $context['_parent'] = $context;
        $context['_seq'] = CoreExtension::ensureTraversable(CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 159, $this->source); })()), "details", [], "any", false, false, false, 159));
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
            // line 160
            yield "                        <tr class=\"border_top\">
                            <td align=\"center\">";
            // line 161
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["loop"], "index", [], "any", false, false, false, 161), "html", null, true);
            yield "</td>
                            <td align=\"center\">";
            // line 162
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["det"], "codigo", [], "any", false, false, false, 162), "html", null, true);
            yield "</td>
                            <td align=\"center\">";
            // line 163
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["det"], "descripcion", [], "any", false, false, false, 163), "html", null, true);
            yield "</td>
                            <td align=\"center\">";
            // line 164
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["det"], "unidad", [], "any", false, false, false, 164), "html", null, true);
            yield "</td>
                            <td align=\"center\">";
            // line 165
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, $context["det"], "cantidad", [], "any", false, false, false, 165), "html", null, true);
            yield "</td>
                        </tr>
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
        // line 168
        yield "                    </tbody>
                </table></div>
            <table width=\"100%\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">
                <tbody><tr>
                    <td width=\"50%\" valign=\"top\">
                        <table width=\"100%\" border=\"0\" cellpadding=\"5\" cellspacing=\"0\">
                            <tbody>
                            <tr>
                                <td colspan=\"4\">
                                ";
        // line 177
        if ((($tmp = CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 177, $this->source); })()), "observacion", [], "any", false, false, false, 177)) && $tmp instanceof Markup ? (string) $tmp : $tmp)) {
            // line 178
            yield "                                    <br><br>
                                    <span style=\"font-family:Tahoma, Geneva, sans-serif; font-size:12px\" text-align=\"center\"><strong>Observaciones</strong></span>
                                    <br>
                                    <p>";
            // line 181
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 181, $this->source); })()), "observacion", [], "any", false, false, false, 181), "html", null, true);
            yield "</p>
                                ";
        }
        // line 183
        yield "                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </td>
                    <td width=\"50%\" valign=\"top\"></td>
                </tr>
                </tbody></table>
            ";
        // line 191
        if ((array_key_exists("max_items", $context) && (Twig\Extension\CoreExtension::length($this->env->getCharset(), CoreExtension::getAttribute($this->env, $this->source, (isset($context["doc"]) || array_key_exists("doc", $context) ? $context["doc"] : (function () { throw new RuntimeError('Variable "doc" does not exist.', 191, $this->source); })()), "details", [], "any", false, false, false, 191)) > (isset($context["max_items"]) || array_key_exists("max_items", $context) ? $context["max_items"] : (function () { throw new RuntimeError('Variable "max_items" does not exist.', 191, $this->source); })())))) {
            // line 192
            yield "                <div style=\"page-break-after:always;\"></div>
            ";
        }
        // line 194
        yield "            <div>
            <table>
                <tbody>
                <tr>
                    <td width=\"85%\">
                        <blockquote>
                            ";
        // line 200
        if (CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["params"] ?? null), "user", [], "any", false, true, false, 200), "footer", [], "any", true, true, false, 200)) {
            // line 201
            yield "                                ";
            yield CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["params"]) || array_key_exists("params", $context) ? $context["params"] : (function () { throw new RuntimeError('Variable "params" does not exist.', 201, $this->source); })()), "user", [], "any", false, false, false, 201), "footer", [], "any", false, false, false, 201);
            yield "
                            ";
        }
        // line 203
        yield "                            ";
        if ((CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["params"] ?? null), "system", [], "any", false, true, false, 203), "hash", [], "any", true, true, false, 203) && CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["params"]) || array_key_exists("params", $context) ? $context["params"] : (function () { throw new RuntimeError('Variable "params" does not exist.', 203, $this->source); })()), "system", [], "any", false, false, false, 203), "hash", [], "any", false, false, false, 203))) {
            // line 204
            yield "                                <strong>Resumen:</strong>   ";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["params"]) || array_key_exists("params", $context) ? $context["params"] : (function () { throw new RuntimeError('Variable "params" does not exist.', 204, $this->source); })()), "system", [], "any", false, false, false, 204), "hash", [], "any", false, false, false, 204), "html", null, true);
            yield "<br>
                            ";
        }
        // line 206
        yield "                            <span>Representación Impresa de la ";
        yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape((isset($context["name"]) || array_key_exists("name", $context) ? $context["name"] : (function () { throw new RuntimeError('Variable "name" does not exist.', 206, $this->source); })()), "html", null, true);
        yield " ELECTRÓNICA.</span>
                        </blockquote>
                    </td>
                    <td width=\"15%\" align=\"right\">
                        ";
        // line 210
        if (CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, ($context["params"] ?? null), "system", [], "any", false, true, false, 210), "qr", [], "any", true, true, false, 210)) {
            // line 211
            yield "                            <img src=\"";
            yield $this->env->getRuntime('Twig\Runtime\EscaperRuntime')->escape($this->env->getRuntime('App\Report\Filter\SafeImageFilter')->toBase64($this->env->getRuntime('Greenter\Report\Render\QrRender')->getQrUrl(CoreExtension::getAttribute($this->env, $this->source, CoreExtension::getAttribute($this->env, $this->source, (isset($context["params"]) || array_key_exists("params", $context) ? $context["params"] : (function () { throw new RuntimeError('Variable "params" does not exist.', 211, $this->source); })()), "system", [], "any", false, false, false, 211), "qr", [], "any", false, false, false, 211)), "svg+xml"), "html", null, true);
            yield "\" alt=\"Qr Image\">
                        ";
        }
        // line 213
        yield "                    </td>
                </tr>
                </tbody></table>
            </div>
        </td>
    </tr>
    </tbody></table>
</body></html>
";
        yield from [];
    }

    /**
     * @codeCoverageIgnore
     */
    public function getTemplateName(): string
    {
        return "despatch.html.twig";
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
        return array (  505 => 213,  499 => 211,  497 => 210,  489 => 206,  483 => 204,  480 => 203,  474 => 201,  472 => 200,  464 => 194,  460 => 192,  458 => 191,  448 => 183,  443 => 181,  438 => 178,  436 => 177,  425 => 168,  408 => 165,  404 => 164,  400 => 163,  396 => 162,  392 => 161,  389 => 160,  372 => 159,  358 => 147,  353 => 144,  339 => 143,  335 => 141,  333 => 140,  320 => 139,  303 => 138,  299 => 136,  291 => 134,  288 => 133,  284 => 132,  280 => 131,  276 => 129,  273 => 128,  265 => 125,  261 => 124,  258 => 123,  256 => 122,  248 => 116,  245 => 115,  242 => 114,  239 => 113,  237 => 112,  228 => 108,  222 => 107,  213 => 104,  207 => 103,  201 => 100,  197 => 99,  191 => 96,  186 => 94,  177 => 87,  175 => 86,  165 => 81,  157 => 78,  153 => 77,  145 => 71,  143 => 70,  130 => 60,  122 => 55,  114 => 50,  96 => 37,  88 => 32,  78 => 25,  67 => 17,  59 => 11,  57 => 10,  55 => 9,  48 => 5,  42 => 1,);
    }

    public function getSourceContext(): Source
    {
        return new Source("", "despatch.html.twig", "E:\\tukifac\\tukifac_premium\\facturador_lycet\\vendor\\greenter\\report\\src\\Report\\Templates\\despatch.html.twig");
    }
}
