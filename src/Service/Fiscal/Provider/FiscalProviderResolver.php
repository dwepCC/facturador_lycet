<?php

declare(strict_types=1);

namespace App\Service\Fiscal\Provider;

use App\Entity\Empresa;
use App\Entity\FiscalDocument;
use App\Service\Fiscal\GreEmitRouting;
use Greenter\Model\DocumentInterface;

/**
 * Resuelve proveedor fiscal según send_mode / provider del documento o empresa.
 */
class FiscalProviderResolver
{
    /** @var FiscalProviderInterface[] */
    private iterable $providers;

    /**
     * @param FiscalProviderInterface[] $providers
     */
    public function __construct(iterable $providers)
    {
        $this->providers = $providers;
    }

    /**
     * @param class-string $documentClass
     */
    public function emit(
        FiscalDocument $doc,
        Empresa $empresa,
        string $documentClass,
        DocumentInterface $greenterDoc
    ): FiscalEmitResult {
        $this->applyEmpresaMode($doc, $empresa);

        if (GreEmitRouting::isProductionPse($doc, $empresa) && GreEmitRouting::isGreDocument($doc)) {
            return $this->emitViaProvider(ValidaPseProvider::class, $doc, $empresa, $documentClass, $greenterDoc);
        }
        if (GreEmitRouting::shouldUseGreRestApi($doc, $empresa)) {
            return $this->emitViaGreProvider($doc, $empresa, $documentClass, $greenterDoc);
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($doc, $empresa)) {
                return $provider->emit($doc, $empresa, $documentClass, $greenterDoc);
            }
        }
        throw new \RuntimeException('No hay proveedor fiscal para send_mode=' . ($doc->getSendMode() ?? 'null'));
    }

    public function resolveName(FiscalDocument $doc, Empresa $empresa): string
    {
        $this->applyEmpresaMode($doc, $empresa);

        if (GreEmitRouting::isProductionPse($doc, $empresa) && GreEmitRouting::isGreDocument($doc)) {
            return 'validapse';
        }
        if (GreEmitRouting::shouldUseGreRestApi($doc, $empresa)) {
            foreach ($this->providers as $provider) {
                if ($provider instanceof NubefactGreProvider && $provider->supports($doc, $empresa)) {
                    return $provider->getName();
                }
            }

            return 'sunat_api_gre';
        }

        foreach ($this->providers as $provider) {
            if ($provider->supports($doc, $empresa)) {
                return $provider->getName();
            }
        }

        return 'unknown';
    }

    /**
     * @param class-string $documentClass
     */
    private function emitViaGreProvider(
        FiscalDocument $doc,
        Empresa $empresa,
        string $documentClass,
        DocumentInterface $greenterDoc
    ): FiscalEmitResult {
        foreach ($this->providers as $provider) {
            if ($provider instanceof NubefactGreProvider && $provider->supports($doc, $empresa)) {
                return $provider->emit($doc, $empresa, $documentClass, $greenterDoc);
            }
        }
        throw new \RuntimeException(
            'Guía GRE (09/31) requiere API REST. En pruebas configure AUTH_URL, API_URL, CLIENT_ID y CLIENT_SECRET (NubeFact). '
            . 'En producción configure Client ID/Secret SUNAT GRE por empresa.'
        );
    }

    /**
     * @param class-string $providerClass
     * @param class-string $documentClass
     */
    private function emitViaProvider(
        string $providerClass,
        FiscalDocument $doc,
        Empresa $empresa,
        string $documentClass,
        DocumentInterface $greenterDoc
    ): FiscalEmitResult {
        foreach ($this->providers as $provider) {
            if ($provider instanceof $providerClass) {
                return $provider->emit($doc, $empresa, $documentClass, $greenterDoc);
            }
        }
        throw new \RuntimeException('Proveedor ' . $providerClass . ' no registrado');
    }

    public function validateConnection(Empresa $empresa): FiscalConnectionResult
    {
        $doc = new FiscalDocument();
        $doc->setSendMode($empresa->getSendMode());
        $doc->setProvider($empresa->getProvider());
        foreach ($this->providers as $provider) {
            if ($provider->supports($doc, $empresa)) {
                return $provider->validateConnection($empresa);
            }
        }
        return FiscalConnectionResult::fail('configuration_missing', 'No hay proveedor para send_mode=' . $empresa->getSendMode());
    }

    private function applyEmpresaMode(FiscalDocument $doc, Empresa $empresa): void
    {
        $doc->setSendMode($empresa->getSendMode());
        $doc->setProvider($empresa->getProvider());
        $doc->setSunatMode($this->ambienteToSunatMode($empresa->getAmbiente()));
    }

    private function ambienteToSunatMode(string $ambiente): string
    {
        return strtolower(trim($ambiente)) === 'produccion' ? 'production' : 'beta';
    }
}
