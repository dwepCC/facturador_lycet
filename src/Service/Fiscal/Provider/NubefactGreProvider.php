<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Exception\EmpresaNoRegistradaException;
use App\Service\ConfigProviderInterface;
use App\Service\Fiscal\GreEmitRouting;
use App\Service\Fiscal\PemCertificateValidator;
use App\Service\SeeApiFactory;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\CdrResponse;
use Greenter\Services\InvalidServiceResponseException;

/**
 * Guías de remisión (09/31) vía API REST GRE 2022+ (Greenter Api).
 * Pruebas: proxy NubeFact (AUTH_URL/API_URL + credenciales .env).
 * Producción + sunat_direct: SUNAT API oficial (credenciales GRE por empresa).
 */
class NubefactGreProvider extends AbstractFiscalProvider
{
    private SeeApiFactory $seeApiFactory;
    private ConfigProviderInterface $config;
    private string $dataPath;

    public function __construct(SeeApiFactory $seeApiFactory, ConfigProviderInterface $config, string $dataPath)
    {
        $this->seeApiFactory = $seeApiFactory;
        $this->config = $config;
        $this->dataPath = $dataPath;
    }

    public function getName(): string
    {
        return 'sunat_api_gre';
    }

    public function supports(FiscalDocument $doc, Empresa $empresa): bool
    {
        return GreEmitRouting::shouldUseGreRestApi($doc, $empresa);
    }

    public function validateConnection(Empresa $empresa): FiscalConnectionResult
    {
        $isProd = strtolower(trim($empresa->getAmbiente())) === 'produccion';
        if ($isProd) {
            $clientId = trim((string) ($empresa->getGreClientId() ?? ''));
            $clientSecret = trim((string) ($empresa->getGreClientSecret() ?? ''));
            if ($clientId === '' || $clientSecret === '') {
                return FiscalConnectionResult::fail(
                    'configuration_missing',
                    'Configure Client ID y Client Secret SUNAT API para producción.'
                );
            }
        } else {
            $clientId = trim($this->config->get('CLIENT_ID'));
            $clientSecret = trim($this->config->get('CLIENT_SECRET'));
            if ($clientId === '' || $clientSecret === '') {
                return FiscalConnectionResult::fail(
                    'configuration_missing',
                    'CLIENT_ID/CLIENT_SECRET SUNAT API no configurados en el facturador (.env)'
                );
            }
        }
        if (!$isProd) {
            $authUrl = trim($this->config->get('AUTH_URL'));
            if ($authUrl === '') {
                return FiscalConnectionResult::fail('configuration_missing', 'AUTH_URL (NubeFact pruebas) no configurado en facturador');
            }
        }
        if (trim($empresa->getSolUser()) === '' || trim($empresa->getSolPass()) === '') {
            return FiscalConnectionResult::fail('invalid_credentials', 'Usuario o clave SOL no configurados');
        }
        $certFile = $empresa->getCertificate();
        if ($certFile === null || trim($certFile) === '') {
            return FiscalConnectionResult::fail('configuration_missing', 'Certificado digital no configurado');
        }
        $certPath = $this->dataPath . DIRECTORY_SEPARATOR . $certFile;
        if (!is_file($certPath)) {
            return FiscalConnectionResult::fail('configuration_missing', 'Archivo de certificado no encontrado');
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

        try {
            $this->seeApiFactory->build($empresa->getRuc());
        } catch (EmpresaNoRegistradaException $e) {
            return FiscalConnectionResult::fail('invalid_credentials', $e->getMessage());
        } catch (\Throwable $e) {
            return FiscalConnectionResult::fail('error', $this->formatGreAuthError($e, $empresa));
        }

        $target = $isProd ? 'SUNAT API producción (GRE REST)' : 'NubeFact GRE pruebas';

        return FiscalConnectionResult::ok('Credenciales ' . $target . ', SOL y certificado presentes');
    }

    public function emit(
        FiscalDocument $doc,
        Empresa $empresa,
        string $documentClass,
        DocumentInterface $greenterDoc
    ): FiscalEmitResult {
        if ($documentClass !== Despatch::class) {
            throw new \InvalidArgumentException('NubefactGreProvider solo emite guías de remisión');
        }
        $ruc = trim((string) $greenterDoc->getCompany()->getRuc());
        try {
            $see = $this->seeApiFactory->build($ruc);
            $result = $see->send($greenterDoc);
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->formatGreAuthError($e, $empresa), 0, $e);
        }
        $signedXml = $see->getLastXml();

        $out = new FiscalEmitResult();
        $out->signedXml = $signedXml;

        if (method_exists($result, 'getTicket') && $result->getTicket()) {
            $out->ticket = (string) $result->getTicket();
        }
        if (method_exists($result, 'getCdrZip') && $result->getCdrZip()) {
            $out->cdrZip = $result->getCdrZip();
        }

        $cdr = $this->extractCdrResponse($result);
        if ($cdr === null && $out->ticket !== null && $out->ticket !== '') {
            [$cdr, $result] = $this->resolveTicketCdr($see, $out->ticket, $result);
            if ($cdr !== null && method_exists($result, 'getCdrZip') && $result->getCdrZip()) {
                $out->cdrZip = $result->getCdrZip();
            }
        }

        if ($cdr !== null) {
            $classified = SunatCdrClassifier::fromCdrResponse($cdr);
            $out->sunatCode = $classified['code'];
            $out->sunatMessage = $classified['message'];
            $out->cdrNotes = $classified['notes'];
            $out->success = $classified['success'];
            $out->rejected = $classified['rejected'];
            $out->observed = $classified['observed'];
        } else {
            $out->sunatCode = $this->extractSunatCodeWithoutCdr($result);
            $out->sunatMessage = $this->extractSunatMessageWithoutCdr($result);
            $out->success = false;
            $out->rejected = false;
            $out->observed = false;
            if ($out->ticket !== null && $out->ticket !== '' && ($out->sunatMessage ?? '') === '') {
                $out->success = true;
            }
        }

        $out->sunatResponse = ['raw' => json_decode(json_encode($result), true)];

        return $out;
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
     * Igual que GET /despatch/status: consulta ticket hasta obtener CDR (máx. ~15 s).
     *
     * @return array{0: ?CdrResponse, 1: object}
     */
    private function resolveTicketCdr(object $see, string $ticket, object $sendResult): array
    {
        $delays = [1, 2, 3, 4, 5];
        foreach ($delays as $seconds) {
            sleep($seconds);
            try {
                $statusResult = $see->getStatus($ticket);
            } catch (\Throwable) {
                continue;
            }
            if (!method_exists($statusResult, 'isSuccess') || !$statusResult->isSuccess()) {
                continue;
            }
            $cdr = $this->extractCdrResponse($statusResult);
            if ($cdr !== null) {
                return [$cdr, $statusResult];
            }
        }

        return [null, $sendResult];
    }

    private function formatGreAuthError(\Throwable $e, Empresa $empresa): string
    {
        $msg = trim($e->getMessage());
        $isProd = strtolower(trim($empresa->getAmbiente())) === 'produccion';
        if ($msg === 'Cliente No autorizado' || $e instanceof InvalidServiceResponseException) {
            if ($isProd) {
                return 'Token GRE rechazado (OAuth). Verifique Client ID/Secret SUNAT API y usuario/clave SOL de la empresa en producción.';
            }

            return 'Token GRE rechazado (OAuth NubeFact pruebas). Verifique CLIENT_ID/CLIENT_SECRET en .env del facturador '
                . 'y que el usuario SOL de la empresa (RUC + usuario secundario) y su clave sean válidos en ambiente beta. '
                . 'Para pruebas GRE suele funcionar el usuario secundario MODDATOS con clave MODDATOS.';
        }

        return $msg !== '' ? $msg : 'Error de autenticación GRE';
    }
}
