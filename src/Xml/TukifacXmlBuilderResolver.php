<?php

declare(strict_types=1);

namespace App\Xml;

use Greenter\Builder\BuilderInterface;
use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\Sale\Invoice;

/** Igual que el de Greenter, pero las facturas/boletas usan TukifacInvoiceBuilder. */
class TukifacXmlBuilderResolver extends XmlBuilderResolver
{
    /** @var array<string,mixed> */
    private array $builderOptions;

    public function __construct(array $options = [])
    {
        parent::__construct($options);
        $this->builderOptions = $options;
    }

    public function find(string $docClass): BuilderInterface
    {
        if ($docClass === Invoice::class) {
            return new TukifacInvoiceBuilder($this->builderOptions);
        }

        return parent::find($docClass);
    }
}
