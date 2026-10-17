<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Códigos reservados que el backend de Tukifac envía en `details[].atributos` (DetailAttribute) para
 * llevar los datos de la detracción de transporte de carga (operación 1004) que el modelo Greenter 5.3
 * no soporta (el destino, el detalle del viaje y los valores referenciales van en
 * cac:InvoiceLine/cac:Delivery, no en cac:AdditionalItemProperty). TukifacInvoiceBuilder los convierte
 * en XML y los quita de los AdditionalItemProperty.
 *
 * Fuente: SUNAT, Anexo 1 de la R.S. 123-2022 (guía XML factura electrónica UBL 2.1), campos 113-118;
 * errores 3116-3126 del catálogo de errores.
 */
final class Transporte1004
{
    public const ORIG_UBIGEO = 'T1004_ORIG_UBIGEO';
    public const ORIG_DIR = 'T1004_ORIG_DIR';
    public const DEST_UBIGEO = 'T1004_DEST_UBIGEO';
    public const DEST_DIR = 'T1004_DEST_DIR';
    public const VIAJE = 'T1004_VIAJE';
    public const VR_SERVICIO = 'T1004_VR_SERVICIO';
    public const VR_CARGA_EFECTIVA = 'T1004_VR_CARGA_EFECTIVA';
    public const VR_CARGA_UTIL = 'T1004_VR_CARGA_UTIL';

    public const ALL = [
        self::ORIG_UBIGEO,
        self::ORIG_DIR,
        self::DEST_UBIGEO,
        self::DEST_DIR,
        self::VIAJE,
        self::VR_SERVICIO,
        self::VR_CARGA_EFECTIVA,
        self::VR_CARGA_UTIL,
    ];

    private function __construct()
    {
    }
}
