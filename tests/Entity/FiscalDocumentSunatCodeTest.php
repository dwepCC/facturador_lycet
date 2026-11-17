<?php

namespace App\Tests\Entity;

use App\Entity\FiscalDocument;
use App\Entity\FiscalEmitAttempt;
use PHPUnit\Framework\TestCase;

final class FiscalDocumentSunatCodeTest extends TestCase
{
    public function testLongCodeIsTruncatedToColumnLength(): void
    {
        $long = 'PSE_VALIDATION_ERROR_DOCUMENT_ALREADY_EXISTS';
        $doc = (new FiscalDocument())->setSunatCode($long);
        $this->assertSame(20, strlen((string) $doc->getSunatCode()));
        $attempt = (new FiscalEmitAttempt())->setSunatCode($long);
        $this->assertSame(20, strlen((string) $attempt->getSunatCode()));
    }

    public function testShortAndNullCodesAreKept(): void
    {
        $this->assertSame('2335', (new FiscalDocument())->setSunatCode('2335')->getSunatCode());
        $this->assertNull((new FiscalDocument())->setSunatCode(null)->getSunatCode());
    }
}
