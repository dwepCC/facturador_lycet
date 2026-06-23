<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\EmpresaNoRegistradaException;
use Greenter\Api;

class SeeApiFactory
{
    private ConfigProviderInterface $config;
    private ConfigProviderInterface $fileProvider;
    private FileDataReader $fileReader;
    private string $cacheDir;

    public function __construct(
        ConfigProviderInterface $config,
        ConfigProviderInterface $fileProvider,
        FileDataReader $fileReader,
        string $cacheDir
    ) {
        $this->config = $config;
        $this->fileProvider = $fileProvider;
        $this->fileReader = $fileReader;
        $this->cacheDir = $cacheDir;
    }

    /**
     * Construye Api GRE con endpoints y credenciales según ambiente de la empresa.
     * Pruebas: NubeFact (AUTH_URL/API_URL + CLIENT_ID/SECRET del .env).
     * Producción: SUNAT API (URLs oficiales + CLIENT_ID/SECRET por empresa).
     *
     * @throws EmpresaNoRegistradaException
     */
    public function build(?string $ruc): Api
    {
        $ruc = $ruc !== null ? trim((string) $ruc) : '';
        if ($ruc === '') {
            throw new EmpresaNoRegistradaException('', 'RUC requerido. La aplicación opera en modo multiempresa con datos en base de datos.');
        }

        $api = $this->createConfiguredApi($ruc);
        if ($api === null) {
            throw new EmpresaNoRegistradaException($ruc);
        }

        return $api;
    }

    private function createConfiguredApi(string $ruc): ?Api
    {
        $ruc = trim($ruc);
        if ($ruc === '') {
            return null;
        }

        $jsonCompanies = $this->fileProvider->get('companies');
        if (empty($jsonCompanies)) {
            return null;
        }

        $companies = json_decode($jsonCompanies, true);
        if (!is_array($companies) || !array_key_exists($ruc, $companies)) {
            return null;
        }

        $companyConfig = $companies[$ruc];
        $ambiente = strtolower(trim((string) ($companyConfig['ambiente'] ?? 'pruebas')));
        $isProd = $ambiente === 'produccion';

        if ($isProd) {
            $clientId = trim((string) ($companyConfig['CLIENT_ID'] ?? ''));
            $clientSecret = trim((string) ($companyConfig['CLIENT_SECRET'] ?? ''));
            if ($clientId === '' || $clientSecret === '') {
                throw new EmpresaNoRegistradaException(
                    $ruc,
                    'La empresa no tiene configuradas las credenciales SUNAT API GRE (Client ID/Secret) para producción.'
                );
            }
        } else {
            $clientId = trim($this->config->get('CLIENT_ID'));
            $clientSecret = trim($this->config->get('CLIENT_SECRET'));
            if ($clientId === '' || $clientSecret === '') {
                return null;
            }
        }

        [$rucPart, $user] = $this->getRucAndUser($companyConfig['SOL_USER']);
        $endpoints = $this->resolveEndpoints($isProd);
        $api = new Api($endpoints);
        $api->setBuilderOptions(['cache' => $this->cacheDir]);
        $api->setClaveSOL($rucPart, $user, $companyConfig['SOL_PASS']);
        $api->setCertificate($this->fileReader->getContents($companyConfig['certificate']));
        $api->setApiCredentials($clientId, $clientSecret);

        return $api;
    }

    /**
     * @return array{auth: string, cpe: string}
     */
    private function resolveEndpoints(bool $isProduction): array
    {
        if ($isProduction) {
            $auth = trim((string) $this->config->get('GRE_AUTH_URL_PRO'));
            $cpe = trim((string) $this->config->get('GRE_API_URL_PRO'));
            if ($auth === '') {
                $auth = 'https://api-seguridad.sunat.gob.pe/v1';
            }
            if ($cpe === '') {
                $cpe = 'https://api-cpe.sunat.gob.pe/v1';
            }

            return [
                'auth' => rtrim($auth, '/'),
                'cpe' => rtrim($cpe, '/'),
            ];
        }

        $auth = trim((string) $this->config->get('AUTH_URL'));
        $cpe = trim((string) $this->config->get('API_URL'));
        if ($auth === '') {
            $auth = 'https://gre-test.nubefact.com/v1';
        }
        if ($cpe === '') {
            $cpe = $auth;
        }

        return [
            'auth' => rtrim($auth, '/'),
            'cpe' => rtrim($cpe, '/'),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function getRucAndUser(string $username): array
    {
        $ruc = substr($username, 0, 11);
        $user = substr($username, 11);

        return [$ruc, $user];
    }
}
