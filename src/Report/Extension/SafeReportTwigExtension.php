<?php

declare(strict_types=1);

namespace App\Report\Extension;

use Greenter\Report\Extension\ReportTwigExtension;
use Twig\TwigFilter;

/**
 * Filtros Greenter con image_b64 tolerante a logo vacío.
 */
class SafeReportTwigExtension extends ReportTwigExtension
{
    public function getFilters()
    {
        return [
            new TwigFilter('catalog', ['Greenter\Report\Filter\DocumentFilter', 'getValueCatalog']),
            new TwigFilter('image_b64', ['App\Report\Filter\SafeImageFilter', 'toBase64']),
            new TwigFilter('n_format', ['Greenter\Report\Filter\FormatFilter', 'number']),
        ];
    }
}
