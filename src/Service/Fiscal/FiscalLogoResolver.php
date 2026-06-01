<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Repository\EmpresaRepository;
use App\Service\FileDataReader;

/**
 * Resuelve el logo de empresa para PDF. Si no hay archivo, devuelve PNG transparente.
 */
final class FiscalLogoResolver
{
    private EmpresaRepository $empresaRepository;
    private FileDataReader $fileReader;
    private string $dataPath;

    public function __construct(
        EmpresaRepository $empresaRepository,
        FileDataReader $fileReader,
        string $dataPath
    ) {
        $this->empresaRepository = $empresaRepository;
        $this->fileReader = $fileReader;
        $this->dataPath = rtrim($dataPath, '/\\');
    }

    /**
     * @return array{bytes: string, has_logo: bool}
     */
    public function resolveForRuc(string $ruc): array
    {
        $ruc = trim($ruc);
        if ($ruc === '') {
            return $this->withoutLogo();
        }

        $filename = $this->findLogoFilename($ruc);
        if ($filename === null) {
            return $this->withoutLogo();
        }

        $bytes = $this->fileReader->getContents($filename);
        if (!is_string($bytes) || trim($bytes) === '') {
            return $this->withoutLogo();
        }

        return ['bytes' => $bytes, 'has_logo' => true];
    }

    private function findLogoFilename(string $ruc): ?string
    {
        $entity = $this->empresaRepository->findByRuc($ruc);
        if ($entity !== null) {
            $logoFile = trim((string) ($entity->getLogo() ?? ''));
            if ($logoFile !== '') {
                return $logoFile;
            }
        }

        $defaultFile = $ruc . '-logo.png';
        $defaultPath = $this->dataPath . DIRECTORY_SEPARATOR . $defaultFile;
        if (is_file($defaultPath) && is_readable($defaultPath)) {
            return $defaultFile;
        }

        return null;
    }

    /**
     * @return array{bytes: string, has_logo: bool}
     */
    private function withoutLogo(): array
    {
        return [
            'bytes' => FiscalCustomerEmailNormalizer::transparentPngBytes(),
            'has_logo' => false,
        ];
    }
}
