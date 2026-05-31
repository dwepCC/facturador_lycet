<?php

declare(strict_types=1);

namespace App\Report\Filter;

use App\Service\Fiscal\FiscalCustomerEmailNormalizer;
use Greenter\Report\Filter\ImageFilter;

/**
 * Evita TypeError en substr() cuando el logo es null.
 */
final class SafeImageFilter
{
    public function toBase64($image, $mime = ''): ?string
    {
        if ($image === null || $image === '') {
            $image = FiscalCustomerEmailNormalizer::transparentPngBytes();
        }

        return (new ImageFilter())->toBase64($image, $mime);
    }
}
