<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * fiscal_documents: ambiente de origen y contador de reemisiones.
 *
 * sunat_mode se reescribe en cada intento, así que al reenviar en producción un
 * comprobante emitido contra beta se perdía el rastro del ambiente original.
 * original_sunat_mode lo fija en el primer envío; el backfill lo siembra con el
 * valor vigente, que para los documentos ya emitidos es el ambiente real.
 */
final class Version20260603000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fiscal_documents: original_sunat_mode + reissue_count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents ADD original_sunat_mode VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_documents ADD reissue_count INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE fiscal_documents SET original_sunat_mode = sunat_mode WHERE sunat_mode IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents DROP original_sunat_mode');
        $this->addSql('ALTER TABLE fiscal_documents DROP reissue_count');
    }
}
