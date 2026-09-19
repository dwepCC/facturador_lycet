<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Service\SeeFactory;
use App\Service\Fiscal\PemCertificateValidator;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\CdrResponse;
use Greenter\Report\XmlUtils;

/**
 * SUNAT directo: Greenter genera XML, firma con certificado local, envía a SUNAT.
 */
class SunatDirectProvider extends AbstractFiscalProvider
{
    private SeeFactory $seeFactory;
    private string $dataPath;

    public function __construct(SeeFactory $seeFactory, string $dataPath)
    {
        $this->seeFactory = $seeFactory;
        $this->dataPath = $dataPath;
    }

    public function getName(): string
    {
        return 'sunat_direct';
    }

    public function supports(FiscalDocument $doc, Empresa $empresa): bool
    {
        $mode = strtolower(trim((string) ($doc->getSendMode() ?? $empresa->getSendMode())));
        if ($mode !== '' && $mode !== 'sunat_direct' && $mode !== 'sunat') {
            return false;
        }
        $tipo = strtoupper(trim((string) $doc->getDocumentType()));
        if (in_array($tipo, ['09', '31'], true)) {
            return false;
        }

        return true;
    }

    public function validateConnection(Empresa $empresa): FiscalConnectionResult
    {
        $base = $this->validateSolAndCertificate($empresa);
        if (!$base->success) {
            return $base;
        }

        return $this->validateProductionSunatApi($empresa, $base);
    }

    private function validateSolAndCertificate(Empresa $empresa): FiscalConnectionResult
    {
        $ruc = $empresa->getRuc();
        if (trim($empresa->getSolUser()) === '' || trim($empresa->getSolPass()) === '') {
            return FiscalConnectionResult::fail('invalid_credentials', 'Usuario o clave SOL no configurados');
        }
        $certFile = $empresa->getCertificate();
        if ($certFile === null || trim($certFile) === '') {
            return FiscalConnectionResult::fail('configuration_missing', 'Certificado digital no configurado');
        }
        $certPath = $this->dataPath . DIRECTORY_SEPARATOR . $certFile;
        if (!is_file($certPath)) {
            return FiscalConnectionResult::fail('configuration_missing', 'Archivo de certificado no encontrado: ' . $certFile);
        }
        $content = file_get_contents($certPath);
        if ($content === false) {
            return FiscalConnectionResult::fail('error', 'No se pudo leer el certificado');
        }
        try {
            PemCertificateValidator::assertSignable($content);
        } catch (\InvalidArgumentException $e) {
            return FiscalConnectionResult::fail('configuration_missing', $e->getMessage());
        }

        return FiscalConnectionResult::ok('Credenciales SOL y certificado PEM completo presentes para RUC ' . $ruc);
    }

    public function emit(
        FiscalDocument $doc,
        Empresa $empresa,
        string $documentClass,
        DocumentInterface $greenterDoc
    ): FiscalEmitResult {
        $ruc = trim((string) $greenterDoc->getCompany()->getRuc());
        $see = $this->seeFactory->build($documentClass, $ruc);
        $result = $see->send($greenterDoc);
        $signedXml = $see->getFactory()->getLastXml();

        $out = new FiscalEmitResult();
        $out->signedXml = $signedXml;
        $out->hash = $this->extractHash($signedXml);
        if (method_exists($result, 'getTicket') && $result->getTicket()) {
            $out->ticket = (string) $result->getTicket();
        }
        if (method_exists($result, 'getCdrZip') && $result->getCdrZip()) {
            $out->cdrZip = $result->getCdrZip();
        }

        $cdr = $this->extractCdrResponse($result);
        if ($cdr !== null) {
            $classified = SunatCdrClassifier::fromCdrResponse($cdr);
            $out->sunatCode = $classified['code'];
            $out->sunatMessage = $classified['message'];
            $out->cdrNotes = $classified['notes'];
            $out->success = $classified['success'];
            $out->rejected = $classified['rejected'];
            $out->observed = $classified['observed'];
            $out->errorType = $classified['errorType'];
        } else {
            $out->sunatCode = $this->extractSunatCodeWithoutCdr($result);
            $out->sunatMessage = $this->extractSunatMessageWithoutCdr($result);
            $out->success = false;
            $out->rejected = false;
            $out->observed = false;
            // SUNAT respondió sin CDR parseable → falla técnica temporal (reintentable).
            $out->errorType = 'transient';
            if ($out->cdrZip !== null && $out->cdrZip !== '') {
                $out->sunatMessage = ($out->sunatMessage ?? '') !== ''
                    ? $out->sunatMessage
                    : 'CDR recibido sin detalle parseable';
            }
            $faultCode = $this->extractSunatFaultCode($result);

            // SUNAT ya aceptó este comprobante antes (típico cuando se cayó sin devolver CDR).
            // No se debe reenviar: se marca para consultar el CDR (consulta de validez).
            if (SunatDuplicateClassifier::isAlreadySubmitted($faultCode, $out->sunatMessage)) {
                $out->alreadySubmitted = true;
            } else {
                // Mismo clasificador que PSE (13.11.3 del plan) — antes solo se detectaba el
                // caso puntual 1032 (rechazo definitivo, ver docblock de
                // FiscalErrorBucketClassifier::BUSINESS_CODES_BELOW_2000) y cualquier otro
                // fault (ej. 0111 "sin perfil", rechazos de negocio ≥2000 sin CDR) quedaba
                // 'transient' por defecto — reintentándose indefinidamente sin poder tener
                // éxito nunca (evidencia real: 91 documentos, 160-203 reintentos, sección
                // 13.11.1 del plan).
                $bucket = FiscalErrorBucketClassifier::classify($faultCode, $out->sunatMessage);
                if ($bucket === FiscalErrorBucketClassifier::BUCKET_BUSINESS) {
                    // Rechazo definitivo sin CDR: el correlativo quedó quemado o el
                    // contenido es inválido — no reintentar.
                    $out->sunatCode = $faultCode ?? $out->sunatCode;
                    $out->rejected = true;
                    $out->errorType = 'business';
                } elseif ($bucket === FiscalErrorBucketClassifier::BUCKET_MANUAL_ONLY) {
                    // No se resuelve reintentando solo (perfil SOL sin habilitar, código no
                    // identificado, etc.) — nunca se auto-programa reintento.
                    $out->sunatCode = $faultCode ?? $out->sunatCode;
                    $out->errorType = 'manual_only';
                }
                // BUCKET_TRANSIENT: no toca nada, $out->errorType ya quedó 'transient' arriba.
            }
        }

        $out->sunatResponse = ['raw' => json_decode(json_encode($result), true)];

        return $out;
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

    private function extractCdrResponse(object $result): ?CdrResponse
    {
        if (method_exists($result, 'getCdrResponse') && $result->getCdrResponse() instanceof CdrResponse) {
            return $result->getCdrResponse();
        }

        return null;
    }

    private function extractSunatCodeWithoutCdr(object $result): ?string
    {
        if (method_exists($result, 'getError') && $result->getError()) {
            return 'error';
        }
        return null;
    }

    private function extractSunatMessageWithoutCdr(object $result): ?string
    {
        if (method_exists($result, 'getError') && $result->getError()) {
            $err = $result->getError();
            if (method_exists($err, 'getMessage')) {
                return (string) $err->getMessage();
            }
        }
        return null;
    }

    /**
     * Código real del fault SOAP de SUNAT (ej. "1033"), distinto del genérico "error".
     */
    private function extractSunatFaultCode(object $result): ?string
    {
        if (method_exists($result, 'getError') && $result->getError()) {
            $err = $result->getError();
            if (method_exists($err, 'getCode')) {
                $code = trim((string) $err->getCode());
                return $code !== '' ? $code : null;
            }
        }
        return null;
    }
}
