<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Código reservado que el backend de Tukifac envía en `details[].atributos` para las exportaciones de servicios
 * 0201 y 0208: el país donde se usa, explota o aprovecha el servicio (campo 51 de la guía XML de factura, R.S. 123-2022).
 * Greenter 5.3 no tiene ese nodo (va en cac:Delivery/cac:DeliveryLocation/cac:Address/cac:Country de la factura, no en una línea):
 * TukifacInvoiceBuilder lo convierte en XML y lo quita de los AdditionalItemProperty.
 */
final class ExportacionServicios
{
    public const PAIS_USO = 'EXP_PAIS_USO';

    private function __construct()
    {
    }
}
