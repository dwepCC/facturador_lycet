<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Service\Fiscal\CdrNormalizer;
use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\DocumentInterface;
use Greenter\Ws\Reader\DomCdrReader;
use Greenter\Ws\Reader\XmlReader;

/**
 * PSE vía proveedor configurable (ValidaPSE, NubeFact, etc.).
 * Credenciales y base URL salen de empresa — nunca de .env tenant.
 */
class ValidaPseProvider extends AbstractFiscalProvider
{
    private PseAuthBuilder $authBuilder;

    public function __construct(PseAuthBuilder $authBuilder)
    {
        $this->authBuilder = $authBuilder;
    }

    public function getName(): string
    {
        return 'validapse';
    }

    public function supports(FiscalDocument $doc, Empresa $empresa): bool
    {
        return $this->resolveSendMode($doc, $empresa) === 'pse';
    }

    public function validateConnection(Empresa $empresa): FiscalConnectionResult
    {
        $baseUrl = $empresa->resolvePseBaseUrl();
        if ($baseUrl === '') {
            $baseUrl = PseProviderRegistry::baseUrl((string) ($empresa->getProvider() ?? 'validapse'));
        }
        if ($baseUrl === '') {
            return FiscalConnectionResult::fail('configuration_missing', 'pse_base_url no configurada');
        }
        if (trim((string) ($empresa->getPseUser() ?? '')) === '') {
            return FiscalConnectionResult::fail('invalid_credentials', 'Usuario PSE no configurado');
        }
        if ($empresa->resolvePseToken() === '') {
            return FiscalConnectionResult::fail('invalid_credentials', 'Token/contraseña PSE no configurado');
        }

        try {
            $headers = $this->buildValidaPseHeaders($empresa);
        } catch (\Throwable $e) {
            return FiscalConnectionResult::fail('invalid_credentials', $e->getMessage());
        }

        $path = $this->resolveHealthPath($empresa);
        $endpoint = $this->buildEndpoint($baseUrl, $path);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            return FiscalConnectionResult::fail('error', 'curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401 || $httpCode === 403) {
            return FiscalConnectionResult::fail('invalid_credentials', 'PSE rechazó credenciales (HTTP ' . $httpCode . ')');
        }
        if ($httpCode >= 200 && $httpCode < 500) {
            $base = FiscalConnectionResult::ok('PSE accesible (HTTP ' . $httpCode . ')');

            return $this->validateProductionSunatApi($empresa, $base);
        }
        return FiscalConnectionResult::fail('error', 'PSE no accesible (HTTP ' . $httpCode . ')');
    }

    public function emit(
        FiscalDocument $doc,
        Empresa $empresa,
        string $documentClass,
        DocumentInterface $greenterDoc
    ): FiscalEmitResult {
        $ruc = trim((string) $greenterDoc->getCompany()->getRuc());
        $unsignedXml = $this->buildUnsignedXml($documentClass, $greenterDoc);
        $filenameBase = sprintf(
            '%s-%s-%s-%s',
            $ruc,
            $doc->getDocumentType(),
            $doc->getSeries(),
            $doc->getNumber()
        );

        $baseUrl = $empresa->resolvePseBaseUrl();
        if ($baseUrl === '') {
            $baseUrl = PseProviderRegistry::baseUrl((string) ($empresa->getProvider() ?? 'validapse'));
        }
        if ($baseUrl === '') {
            throw new \RuntimeException('pse_base_url no configurada para empresa ' . $ruc);
        }

        $headers = $this->buildValidaPseHeaders($empresa);
        $path = $this->resolveEmitPath($doc, $empresa);
        $endpoint = $this->buildEndpoint($baseUrl, $path);

        $payload = json_encode([
            'nombre_archivo' => $filenameBase,
            'contenido_archivo' => base64_encode($unsignedXml),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $bodyStr = $body === false ? '' : (string) $body;
        /** @var array<string, mixed> $pseResp */
        $pseResp = json_decode($bodyStr, true) ?: [];

        if ($httpCode < 200 || $httpCode >= 300) {
            if ($pseResp !== []) {
                return $this->buildEmitResult($unsignedXml, $pseResp, false, $filenameBase);
            }
            if ($httpCode === 401 && str_contains($bodyStr, 'Token requerido')) {
                throw new \RuntimeException(
                    'ValidaPSE rechazó la autenticación (401): Token requerido. '
                    . 'Verifique usuario y contraseña (token de acceso) en panel central y re-sincronice.'
                );
            }
            throw new \RuntimeException('PSE HTTP ' . $httpCode . ': ' . $bodyStr);
        }

        $isSuccess = (bool) ($pseResp['isSuccess'] ?? false);

        return $this->buildEmitResult($unsignedXml, $pseResp, $isSuccess, $filenameBase);
    }

    /**
     * @param array<string, mixed> $pseResp
     */
    private function buildEmitResult(string $unsignedXml, array $pseResp, bool $isSuccess, string $filenameBase): FiscalEmitResult
    {
        $out = new FiscalEmitResult();
        $out->unsignedXml = $unsignedXml;
        $out->pseResponse = $pseResp;
        $out->pseMessage = PseResponseFormatter::message($pseResp);
        $out->success = $isSuccess;
        $out->hash = (string) ($pseResp['codigo_hash'] ?? '');

        if (!empty($pseResp['xml'])) {
            $signed = base64_decode((string) $pseResp['xml'], true);
            if ($signed !== false && $signed !== '') {
                $out->signedXml = $signed;
            }
        }
        if ($out->signedXml === null || $out->signedXml === '') {
            foreach (['xml_firmado', 'contenido_xml_firmado', 'signed_xml'] as $altKey) {
                if (empty($pseResp[$altKey])) {
                    continue;
                }
                $signed = base64_decode((string) $pseResp[$altKey], true);
                if ($signed !== false && $signed !== '') {
                    $out->signedXml = $signed;
                    break;
                }
            }
        }
        // El CDR (ApplicationResponse) puede venir en 'cdr' (base64), XML o ZIP SUNAT ya
        // envuelto. Se decodifica el XML crudo aparte del ZIP de almacenamiento porque hace
        // falta PARSEARLO (DomCdrReader → CdrResponse) para saber su código real — nunca se
        // asume "aceptado" solo porque el campo esté presente (reglas 2/3 de la sección 14).
        $cdrRawXml = null;
        if (!empty($pseResp['cdr']) && is_string($pseResp['cdr'])) {
            $decoded = base64_decode((string) $pseResp['cdr'], true);
            if ($decoded !== false && $decoded !== '' && CdrNormalizer::isXml($decoded)) {
                $cdrRawXml = $decoded;
                $out->cdrZip = CdrNormalizer::toSunatZip($decoded, $filenameBase);
            }
        }

        $embeddedCode = isset($pseResp['code']) ? (string) $pseResp['code'] : null;
        $estado = isset($pseResp['estado']) ? (string) $pseResp['estado'] : null;

        if ($cdrRawXml !== null) {
            // CDR real: se parsea y se clasifica exactamente igual que el canal directo
            // (mismo SunatCdrClassifier, que a su vez usa CdrResponse::isAccepted() de
            // Greenter) — el código sale del propio CDR, nunca se fabrica. Se evalúa antes
            // que `isSuccess` porque SUNAT puede devolver, dentro de un CDR real, un
            // resultado de rechazo aunque el PSE reporte éxito en su propia operación.
            $cdrResponse = (new DomCdrReader(new XmlReader()))->getCdrResponse($cdrRawXml);
            $classified = SunatCdrClassifier::fromCdrResponse($cdrResponse);
            $out->sunatCode = $classified['code'];
            $out->sunatMessage = $classified['message'] !== null && $classified['message'] !== ''
                ? $classified['message']
                : $out->pseMessage;
            $out->cdrNotes = $classified['notes'];
            $out->success = $classified['success'];
            $out->rejected = $classified['rejected'];
            $out->observed = $classified['observed'];
            $out->errorType = $classified['errorType'];
        } elseif ($isSuccess) {
            // PSE confirma éxito de SU PROPIA operación (isSuccess:true), pero esta
            // respuesta no trae un CDR real embebido. Esto NO es evidencia de que el
            // comprobante ya haya sido informado en un intento ANTERIOR (eso solo lo
            // determina SunatDuplicateClassifier vía 1033/mensaje) ni tampoco evidencia de
            // aceptación por SUNAT — es este mismo envío, exitoso del lado del PSE, con el
            // CDR real todavía pendiente. No se reenvía (evitar duplicar el envío) y no se
            // acepta sin CDR real: queda "enviado, pendiente de CDR" hasta consulta manual.
            $out->sunatCode = $estado; // nunca se fabrica '0'
            $out->sunatMessage = $out->pseMessage ?: 'Enviado a PSE, CDR pendiente de consulta';
            $out->sentPendingCdr = true;
            // `success` significa "aceptado, verificado por un CDR real clasificado" — el
            // isSuccess de PSE por sí solo NO califica (regla 4), así que se deja en false
            // aunque $isSuccess sea true, para que nada aguas abajo pueda interpretarlo como
            // aceptación (FiscalEmitResult::isAccepted() = success && !rejected && !observed).
            $out->success = false;
        } elseif (SunatDuplicateClassifier::isAlreadySubmitted($embeddedCode, $out->pseMessage)) {
            // El comprobante ya fue informado a SUNAT en un intento PREVIO (código 1033 o
            // texto equivalente) — evidencia concreta, no una suposición. No reenviar,
            // consultar el CDR en el PSE (GET /api/cpe/consultar/{archivo}).
            $out->sunatCode = $embeddedCode;
            $out->sunatMessage = $out->pseMessage ?: 'El comprobante fue informado anteriormente';
            $out->rejected = false;
            $out->alreadySubmitted = true;
            $out->errorType = 'transient';
        } else {
            $bucket = FiscalErrorBucketClassifier::classify($embeddedCode, $out->pseMessage);
            $out->sunatCode = $embeddedCode ?? ($estado ?? 'error');
            $out->sunatMessage = $out->pseMessage ?: 'Rechazado por PSE';
            if ($bucket === FiscalErrorBucketClassifier::BUCKET_BUSINESS) {
                $out->rejected = true;
                $out->errorType = 'business';
            } elseif ($bucket === FiscalErrorBucketClassifier::BUCKET_TRANSIENT) {
                $out->rejected = false;
                $out->errorType = 'transient';
            } else {
                $out->rejected = false;
                $out->errorType = 'manual_only';
            }
        }

        return $out;
    }

    private function resolveSendMode(FiscalDocument $doc, Empresa $empresa): string
    {
        $mode = strtolower(trim((string) ($doc->getSendMode() ?? '')));
        if ($mode !== '') {
            return $mode;
        }
        return strtolower(trim($empresa->getSendMode()));
    }

    private function resolveEmitPath(FiscalDocument $doc, Empresa $empresa): string
    {
        $meta = trim((string) ($empresa->getPseMetadataJson() ?? ''));
        if ($meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded) && !empty($decoded['emit_path']) && is_string($decoded['emit_path'])) {
                return $decoded['emit_path'];
            }
        }
        $prod = strtolower(trim((string) $doc->getSunatMode())) === 'production'
            || strtolower(trim($empresa->getAmbiente())) === 'produccion';
        return $prod ? '/api/cpe/generarenviar' : '/api/cpe/generarenviar-demo';
    }

    private function resolveHealthPath(Empresa $empresa): string
    {
        $meta = trim((string) ($empresa->getPseMetadataJson() ?? ''));
        if ($meta !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded) && !empty($decoded['health_path']) && is_string($decoded['health_path'])) {
                return $decoded['health_path'];
            }
        }
        return '/api/cpe/generarenviar-demo';
    }

    /**
     * ValidaPSE CPE solo acepta Authorization: Bearer (token_acceso), no Basic Auth.
     *
     * @return string[]
     */
    private function buildValidaPseHeaders(Empresa $empresa): array
    {
        $type = strtolower(trim((string) ($empresa->getConnectionType() ?? '')));
        if ($type === 'custom') {
            return $this->authBuilder->buildHeaders($empresa);
        }

        $token = $empresa->resolvePseToken();
        if ($token === '') {
            throw new \RuntimeException(
                'ValidaPSE requiere token de acceso. '
                . 'Ingrese la contraseña (token_acceso) de ValidaPSE en panel central y guarde la configuración fiscal.'
            );
        }

        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
    }

    private function buildEndpoint(string $baseURL, string $path): string
    {
        $base = rtrim($baseURL, '/');
        if (substr($base, -4) === '/api') {
            $base = substr($base, 0, -4);
        }
        return $base . $path;
    }

    private function buildUnsignedXml(string $documentClass, DocumentInterface $greenterDoc): string
    {
        $builder = (new XmlBuilderResolver(['autoescape' => false]))->find($documentClass);
        $xml = $builder->build($greenterDoc);
        if (!is_string($xml) || trim($xml) === '') {
            throw new \RuntimeException('No se pudo generar XML UBL del comprobante');
        }

        return $xml;
    }
}
