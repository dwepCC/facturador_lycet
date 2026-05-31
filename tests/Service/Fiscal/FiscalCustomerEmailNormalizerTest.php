<?php

declare(strict_types=1);

namespace App\Tests\Service\Fiscal;

use App\Entity\FiscalDocument;
use App\Service\Fiscal\FiscalCustomerEmailNormalizer;
use PHPUnit\Framework\TestCase;

class FiscalCustomerEmailNormalizerTest extends TestCase
{
    private FiscalCustomerEmailNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new FiscalCustomerEmailNormalizer();
    }

    public function testNullEmailUsesPlaceholderForDisplayAndNotDeliverable(): void
    {
        self::assertSame(FiscalCustomerEmailNormalizer::PLACEHOLDER, $this->normalizer->forDisplay(null));
        self::assertNull($this->normalizer->forDelivery(null));
        self::assertFalse($this->normalizer->isDeliverable(null));
    }

    public function testEmptyEmailTreatedAsUnavailable(): void
    {
        self::assertSame(FiscalCustomerEmailNormalizer::PLACEHOLDER, $this->normalizer->forDisplay('   '));
        self::assertNull($this->normalizer->forDelivery(''));
        self::assertFalse($this->normalizer->isDeliverable("\t"));
    }

    public function testValidEmailIsDeliverable(): void
    {
        $email = 'cliente@empresa.test';
        self::assertSame($email, $this->normalizer->forDisplay($email));
        self::assertSame($email, $this->normalizer->forDelivery($email));
        self::assertTrue($this->normalizer->isDeliverable($email));
    }

    public function testPlaceholderIsNeverDeliverable(): void
    {
        self::assertNull($this->normalizer->forDelivery(FiscalCustomerEmailNormalizer::PLACEHOLDER));
        self::assertFalse($this->normalizer->isDeliverable(FiscalCustomerEmailNormalizer::PLACEHOLDER));
    }

    public function testExtractForStorageFromSnapshotAndPayload(): void
    {
        $snapshot = ['client' => ['email' => '  ventas@local.test ']];
        self::assertSame('ventas@local.test', $this->normalizer->extractForStorage($snapshot, []));

        $withNull = ['client' => ['email' => null]];
        self::assertNull($this->normalizer->extractForStorage($withNull, ['customer_email' => null]));
        self::assertSame(
            'a@b.co',
            $this->normalizer->extractForStorage($withNull, ['customer_email' => 'a@b.co'])
        );
    }

    public function testResolveFromDocumentUsesStoredEmail(): void
    {
        $doc = new FiscalDocument();
        $doc->setCustomerEmail('guardado@local.test');
        $doc->setSnapshotJson(json_encode(['client' => ['email' => 'otro@local.test']], JSON_THROW_ON_ERROR));

        self::assertSame('guardado@local.test', $this->normalizer->resolveFromDocument($doc));
    }
}
