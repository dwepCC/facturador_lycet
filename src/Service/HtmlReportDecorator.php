<?php

declare(strict_types=1);

namespace App\Service;

use App\Report\FiscalHtmlReport;
use App\Service\Fiscal\FiscalCustomerEmailNormalizer;
use Greenter\Model\DocumentInterface;
use Greenter\Report\ReportInterface;
use Greenter\Report\Resolver\TemplateResolverInterface;

class HtmlReportDecorator implements ReportInterface
{
    private FiscalHtmlReport $htmlReport;

    private TemplateResolverInterface $resolver;

    private FiscalCustomerEmailNormalizer $emailNormalizer;

    public function __construct(
        FiscalHtmlReport $htmlReport,
        TemplateResolverInterface $resolver,
        FiscalCustomerEmailNormalizer $emailNormalizer
    ) {
        $this->htmlReport = $htmlReport;
        $this->resolver = $resolver;
        $this->emailNormalizer = $emailNormalizer;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function render(DocumentInterface $document, array $parameters = []): ?string
    {
        $template = $this->resolver->getTemplate($document);
        $this->htmlReport->setTemplate($template);

        $deliveryEmail = null;
        if (method_exists($document, 'getClient')) {
            $client = $document->getClient();
            if ($client !== null && method_exists($client, 'getEmail')) {
                $deliveryEmail = $client->getEmail();
            }
        }

        $document = $this->emailNormalizer->sanitizeGreenterDocument($document);
        $parameters = $this->emailNormalizer->sanitizePdfParameters($parameters, $deliveryEmail);

        return $this->htmlReport->render($document, $parameters);
    }
}
