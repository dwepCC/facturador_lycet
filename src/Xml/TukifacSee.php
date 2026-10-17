<?php

declare(strict_types=1);

namespace App\Xml;

use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BaseResult;
use Greenter\Model\Sale\Invoice;
use Greenter\See;

/**
 * See de Greenter que arma las facturas con TukifacInvoiceBuilder (nodos de transporte de carga 1004).
 * Greenter\See construye su XmlBuilderResolver internamente y no es inyectable, así que getXmlSigned y
 * send se reimplementan SOLO para Invoice y SOLO cuando la factura trae atributos reservados de 1004;
 * todo lo demás pasa por Greenter sin cambios.
 */
class TukifacSee extends See
{
    /** @var array<string,mixed> */
    private array $xmlOptions = ['autoescape' => false];

    public function setBuilderOptions(array $options)
    {
        parent::setBuilderOptions($options);
        $this->xmlOptions = array_merge($this->xmlOptions, $options);
    }

    public function setCachePath(?string $directory)
    {
        parent::setCachePath($directory);
        $this->xmlOptions['cache'] = empty($directory) ? false : $directory;
    }

    public function getXmlSigned(DocumentInterface $document): ?string
    {
        if (!self::usesTransporte($document)) {
            return parent::getXmlSigned($document);
        }
        $resolver = new TukifacXmlBuilderResolver($this->xmlOptions);

        return $this->getFactory()
            ->setBuilder($resolver->find(get_class($document)))
            ->getXmlSigned($document);
    }

    public function send(DocumentInterface $document): ?BaseResult
    {
        if (!self::usesTransporte($document)) {
            return parent::send($document);
        }
        $xml = $this->getXmlSigned($document);

        // sendXml deja configurado el sender; el XML firmado queda en getFactory()->getLastXml().
        return $this->sendXml(get_class($document), $document->getName(), (string) $xml);
    }

    private static function usesTransporte(DocumentInterface $document): bool
    {
        return $document instanceof Invoice && TukifacInvoiceBuilder::hasReservedAttributes($document);
    }
}
