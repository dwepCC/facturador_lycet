<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\DocumentInterface;
use Greenter\Report\XmlUtils;

/**
 * PSE propio (pse.tukifac.com). A diferencia de ValidaPseProvider, este habla un contrato
 * propio (no el de ValidaPSE): {"nombre_documento","contenido_xml"} + header
 * Idempotency-Key, respuesta {"status", "sunat":{...}, "archivos_disponibles":{...}} sin
 * XML/CDR inline — se descargan aparte cuando el estado es terminal.
 *
 * Ver docs/integraciones/tukifac-integracion-pse.md (repo pse-app) para el contrato completo
 * y la tabla de mapeo de errores a los 3 buckets de FiscalErrorBucketClassifier.
 */
class PseAppProvider extends AbstractFiscalProvider
{
    private const CONNECT_TIMEOUT = 8;
    private const TOTAL_TIMEOUT = 15;

    public function getName(): string
    {
        return 'pseapp';
    }

    public function supports(FiscalDocument $doc, Empresa $empresa): bool
    {
        return $this->resolveSendMode($doc, $empresa) === 'pse'
            && PseProviderRegistry::normalizeProvider((string) ($empresa->getProvider() ?? '')) === 'pseapp';
    }

    public function validateConnection(Empresa $empresa): FiscalConnectionResult
    {
        $baseUrl = $this->resolveBaseUrl($empresa);
        if ($baseUrl === '') {
            return FiscalConnectionResult::fail('configuration_missing', 'pse_base_url no configurada');
        }
        $token = $empresa->resolvePseToken();
        if ($token === '') {
            return FiscalConnectionResult::fail('invalid_credentials', 'Token PSE no configurado');
        }

        // No hay endpoint de health dedicado en el contrato — se reutiliza la consulta de
        // documento (GET /documentos/{id}) con un id inexistente: 401/403 = credencial
        // inválida, 404 = credencial válida (autenticó) pero el recurso no existe, que es
        // exactamente lo que se espera de un chequeo de conectividad.
        $endpoint = $this->buildEndpoint($baseUrl, '/api/v1/documentos/00000000000000000000000000');
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return FiscalConnectionResult::fail('error', 'curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders($token),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
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
        $nombreDocumento = sprintf(
            '%s-%s-%s-%s',
            $ruc,
            $doc->getDocumentType(),
            $doc->getSeries(),
            $doc->getNumber()
        );

        $baseUrl = $this->resolveBaseUrl($empresa);
        if ($baseUrl === '') {
            throw new \RuntimeException('pse_base_url no configurada para empresa ' . $ruc);
        }
        $token = $empresa->resolvePseToken();
        if ($token === '') {
            throw new \RuntimeException('Token PSE no configurado para empresa ' . $ruc);
        }

        // Idempotency-Key estable por documento (no un uuid nuevo por intento) — un reintento
        // del mismo FiscalDocument (falla transitoria, 202) no vuelve a firmar ni a cobrar del
        // lado del PSE, solo recibe el resultado ya en curso u obtenido. Ver docs/integraciones/
        // tukifac-integracion-pse.md §3.2 (repo pse-app).
        $idempotencyKey = $doc->getDocumentUuid();

        $endpoint = $this->buildEndpoint($baseUrl, '/api/v1/documentos/firmar-enviar');
        $payload = json_encode([
            'nombre_documento' => $nombreDocumento,
            'contenido_xml' => base64_encode($unsignedXml),
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array_merge($this->buildHeaders($token), ['Idempotency-Key: ' . $idempotencyKey]),
            CURLOPT_RETURNTRANSFER => true,
            // Mismo criterio que ValidaPseProvider tras su incidente real de producción: el
            // worker fiscal es una sola instancia secuencial, un timeout largo bloquearía toda
            // la cola (de cualquier tenant) si el PSE se pone lento. Fallar rápido acá y dejar
            // que el reintento automático (Idempotency-Key estable) se encargue es mejor que
            // mantener la conexión abierta.
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException('PSE sin respuesta (curl errno ' . $curlErrno . '): ' . $curlError);
        }

        /** @var array<string, mixed> $resp */
        $resp = json_decode((string) $body, true) ?: [];
        $out = $this->classifyResponse($httpCode, $resp);
        $out->unsignedXml = $unsignedXml;

        if ($out->hasSunatOutcome()) {
            // Estado terminal (aceptado/observado/rechazado) — el contrato de este PSE no
            // manda XML/CDR inline (a diferencia de ValidaPSE), hay que pedirlos aparte.
            $documentId = (string) ($resp['document_id'] ?? '');
            if ($documentId !== '') {
                $signedXml = $this->fetchFile($baseUrl, $token, $documentId, '/xml-firmado');
                if ($signedXml !== null) {
                    $out->signedXml = $signedXml;
                    $out->hash = $this->extractHash($signedXml);
                }
                if ($out->rejected === false) {
                    $out->cdrZip = $this->fetchFile($baseUrl, $token, $documentId, '/cdr');
                }
            }
        }

        return $out;
    }

    /**
     * Clasificación pura (sin red) — mapea la respuesta ya decodificada al modelo de 3 buckets
     * de FiscalErrorBucketClassifier. Separado de emit() para poder probarse por Reflection
     * igual que ValidaPseProvider::buildEmitResult() (ver ValidaPseProviderBuildEmitResultTest).
     *
     * @param array<string, mixed> $resp
     */
    private function classifyResponse(int $httpCode, array $resp): FiscalEmitResult
    {
        $out = new FiscalEmitResult();
        $out->pseResponse = $resp;

        if ($httpCode >= 400) {
            $codigo = (string) ($resp['error']['codigo'] ?? '');
            $mensaje = (string) ($resp['error']['mensaje'] ?? '');
            $out->pseMessage = $mensaje;
            $out->sunatMessage = $mensaje !== '' ? $mensaje : ('PSE HTTP ' . $httpCode);
            $out->sunatCode = $codigo !== '' ? $codigo : (string) $httpCode;

            $bucket = $this->classifyErrorCode($codigo);
            if ($bucket === FiscalErrorBucketClassifier::BUCKET_BUSINESS) {
                $out->rejected = true;
            }
            $out->errorType = $bucket;

            return $out;
        }

        $status = (string) ($resp['status'] ?? '');
        $sunat = is_array($resp['sunat'] ?? null) ? $resp['sunat'] : [];
        $out->sunatCode = isset($sunat['codigo']) ? (string) $sunat['codigo'] : null;
        $out->sunatMessage = isset($sunat['descripcion']) ? (string) $sunat['descripcion'] : null;

        switch ($status) {
            case 'ACCEPTED':
                $out->success = true;

                return $out;
            case 'ACCEPTED_WITH_OBSERVATION':
                $out->success = true;
                $out->observed = true;

                return $out;
            case 'REJECTED':
                $out->rejected = true;
                $out->errorType = FiscalErrorBucketClassifier::BUCKET_BUSINESS;
                if (($out->sunatMessage ?? '') === '') {
                    $out->sunatMessage = 'Rechazado por SUNAT';
                }

                return $out;
            default:
                // 202, o cualquier status no terminal (SENDING/SENT_TICKET_PENDING/UNKNOWN):
                // sigue procesándose del lado del PSE. El reintento con Idempotency-Key
                // estable resuelve esto sin bloquear el worker.
                $out->errorType = FiscalErrorBucketClassifier::BUCKET_TRANSIENT;
                if (($out->sunatMessage ?? '') === '') {
                    $out->sunatMessage = (string) ($resp['mensaje'] ?? 'En procesamiento');
                }

                return $out;
        }
    }

    /** Ver docs/integraciones/tukifac-integracion-pse.md §3.3 (repo pse-app) para la tabla completa. */
    private function classifyErrorCode(string $codigo): string
    {
        return match ($codigo) {
            'IDEMPOTENCY_IN_PROGRESS' => FiscalErrorBucketClassifier::BUCKET_TRANSIENT,
            'INVALID_XML', 'UNSUPPORTED_DOCUMENT_TYPE', 'RUC_MISMATCH', 'VALIDATION_ERROR',
            'EMISOR_NOT_AFFILIATED', 'EMISOR_INACTIVE', 'INSUFFICIENT_CREDIT',
            'DUPLICATE_DOCUMENT' => FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY,
            default => FiscalErrorBucketClassifier::BUCKET_TRANSIENT, // SIGNING_FAILED, 401, o cualquier código nuevo no visto todavía
        };
    }

    private function resolveSendMode(FiscalDocument $doc, Empresa $empresa): string
    {
        $mode = strtolower(trim((string) ($doc->getSendMode() ?? '')));
        if ($mode !== '') {
            return $mode;
        }

        return strtolower(trim($empresa->getSendMode()));
    }

    private function resolveBaseUrl(Empresa $empresa): string
    {
        $baseUrl = $empresa->resolvePseBaseUrl();
        if ($baseUrl !== '') {
            return $baseUrl;
        }

        return PseProviderRegistry::baseUrl((string) ($empresa->getProvider() ?? 'pseapp'));
    }

    /** @return string[] */
    private function buildHeaders(string $token): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ];
    }

    private function buildEndpoint(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . $path;
    }

    private function fetchFile(string $baseUrl, string $token, string $documentId, string $suffix): ?string
    {
        $endpoint = $this->buildEndpoint($baseUrl, '/api/v1/documentos/' . $documentId . $suffix);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->buildHeaders($token),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $httpCode !== 200 || !is_string($body) || $body === '') {
            return null;
        }

        return $body;
    }

    private function extractHash(string $xml): string
    {
        if ($xml === '') {
            return '';
        }
        try {
            return (new XmlUtils())->getHashSign($xml);
        } catch (\Throwable $e) {
            return '';
        }
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
