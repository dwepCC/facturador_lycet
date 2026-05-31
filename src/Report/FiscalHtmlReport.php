<?php

declare(strict_types=1);

namespace App\Report;

use App\Report\Extension\SafeReportTwigExtension;
use Greenter\Model\DocumentInterface;
use Greenter\Model\TimeZonePe;
use Greenter\Report\Extension\RuntimeLoader;
use Greenter\Report\ReportInterface;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\FilesystemLoader;

/**
 * Render HTML fiscal con variables extra y filtros null-safe.
 */
class FiscalHtmlReport implements ReportInterface
{
    private Environment $twig;

    private string $template = 'invoice.html.twig';

    /**
     * @param array<string, mixed> $optionTwig
     */
    public function __construct(?string $templatesDir = '', array $optionTwig = [])
    {
        $this->twig = $this->buildTwig($templatesDir, $optionTwig);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function render(DocumentInterface $document, array $parameters = []): ?string
    {
        $customerEmail = isset($parameters['customer_email']) && is_string($parameters['customer_email'])
            ? $parameters['customer_email']
            : null;

        return $this->twig->render($this->template, [
            'doc' => $document,
            'params' => $parameters,
            'customer_email' => $customerEmail,
        ]);
    }

    public function setTemplate(?string $template): void
    {
        if ($template !== null && $template !== '') {
            $this->template = $template;
        }
    }

    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildTwig(?string $directory, array $options): Environment
    {
        $dirs = [];
        if ($directory) {
            $dirs[] = $directory;
        }
        $dirs[] = dirname(__DIR__, 2) . '/templates/pdf';
        $dirs[] = dirname(__DIR__, 2) . '/vendor/greenter/report/src/Report/Templates';

        $loader = new FilesystemLoader($dirs);
        $twigEnv = new Environment($loader, $options);

        $twigEnv->addRuntimeLoader(new RuntimeLoader());
        $twigEnv->addExtension(new SafeReportTwigExtension());
        $extension = $twigEnv->getExtension(CoreExtension::class);
        if ($extension instanceof CoreExtension) {
            $extension->setTimezone(TimeZonePe::DEFAULT);
        }

        return $twigEnv;
    }
}
