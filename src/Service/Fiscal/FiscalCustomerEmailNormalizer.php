<?php

declare(strict_types=1);

namespace App\Service\Fiscal;

use App\Entity\FiscalDocument;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\BaseSale;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;

/**
 * Normalización de correos del cliente para PDF (placeholder) y envío (null = no enviar).
 */
final class FiscalCustomerEmailNormalizer
{
    public const PLACEHOLDER = 'no-email@tukifac.local';
    public const STATUS_NOT_AVAILABLE = 'email_not_available';

    /**
     * Email para mostrar en PDF/plantillas; nunca null.
     */
    public function forDisplay(?string $email): string
    {
        $normalized = $this->normalizeRaw($email);
        if ($normalized === null) {
            return self::PLACEHOLDER;
        }

        return $normalized;
    }

    /**
     * Email listo para envío SMTP; null si no hay destinatario real.
     */
    public function forDelivery(?string $email): ?string
    {
        $normalized = $this->normalizeRaw($email);
        if ($normalized === null || $this->isPlaceholder($normalized)) {
            return null;
        }

        return $normalized;
    }

    public function isDeliverable(?string $email): bool
    {
        $delivery = $this->forDelivery($email);

        return $delivery !== null && filter_var($delivery, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function isPlaceholder(string $email): bool
    {
        return strcasecmp(trim($email), self::PLACEHOLDER) === 0;
    }

    /**
     * Persistencia en BD: null si no hay email real.
     *
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $payload
     */
    public function extractForStorage(array $snapshot, array $payload): ?string
    {
        if (array_key_exists('customer_email', $payload)) {
            $fromPayload = $this->normalizeRaw(
                is_string($payload['customer_email']) ? $payload['customer_email'] : null
            );
            if ($fromPayload !== null) {
                return $fromPayload;
            }
        }

        foreach (['customer', 'client'] as $key) {
            if (!isset($snapshot[$key]) || !is_array($snapshot[$key])) {
                continue;
            }
            $raw = $snapshot[$key]['email'] ?? null;
            $normalized = $this->normalizeRaw(is_string($raw) ? $raw : null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    public function resolveFromDocument(FiscalDocument $doc): ?string
    {
        $stored = $this->forDelivery($doc->getCustomerEmail());
        if ($stored !== null) {
            return $stored;
        }

        $data = json_decode($doc->getSnapshotJson(), true);
        if (!is_array($data)) {
            return null;
        }

        foreach (['customer', 'client'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                continue;
            }
            $raw = $data[$key]['email'] ?? null;
            $normalized = $this->normalizeRaw(is_string($raw) ? $raw : null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * Ajusta emails en el modelo Greenter solo para render PDF (no modifica XML firmado).
     */
    public function sanitizeGreenterDocument(DocumentInterface $document): DocumentInterface
    {
        if ($document instanceof BaseSale) {
            $client = $document->getClient();
            if ($client instanceof Client) {
                $client->setEmail($this->forDisplay($client->getEmail()));
            }
        }

        if ($document instanceof Invoice || $document instanceof Note) {
            $company = $document->getCompany();
            if ($company instanceof Company) {
                $company->setEmail($this->forDisplay($company->getEmail()));
            }
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function sanitizePdfParameters(array $parameters, ?string $customerEmail = null): array
    {
        if (!isset($parameters['system']) || !is_array($parameters['system'])) {
            $parameters['system'] = [];
        }
        $logo = $parameters['system']['logo'] ?? null;
        if ($logo === null || $logo === '') {
            $parameters['system']['logo'] = self::transparentPngBytes();
        }

        if (!isset($parameters['user']) || !is_array($parameters['user'])) {
            $parameters['user'] = [];
        }
        if (isset($parameters['user']['extras']) && is_array($parameters['user']['extras'])) {
            foreach ($parameters['user']['extras'] as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!isset($item['name']) || $item['name'] === null) {
                    $parameters['user']['extras'][$idx]['name'] = '';
                }
                if (!isset($item['value']) || $item['value'] === null) {
                    $parameters['user']['extras'][$idx]['value'] = '';
                }
            }
        }

        $parameters['customer_email'] = $this->forDisplay($customerEmail);

        return $parameters;
    }

    private function normalizeRaw(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }
        $trimmed = trim($email);
        if ($trimmed === '') {
            return null;
        }
        if ($this->isPlaceholder($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    /** PNG 1×1 transparente para filtros Twig que exigen string. */
    public static function transparentPngBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAD0lEQVQ42mNkY+A/AwADAgEAAQABAAAA//8DAAEAAAABAAAAAA==',
            true
        ) ?: '';
    }
}
