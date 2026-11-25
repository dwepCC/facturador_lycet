<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * RUC del emisor como columna indexada de fiscal_documents. El filtro por RUC del panel buscaba con
 * LIKE dentro de snapshot_json (10-15 s en producción). Los documentos antiguos se rellenan aparte,
 * por lotes, con `app:fiscal:backfill-company-ruc` (no aquí: la tabla es grande).
 */
final class Version20261125000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega company_ruc e idx_fiscal_doc_company_ruc a fiscal_documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents ADD company_ruc VARCHAR(11) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_fiscal_doc_company_ruc ON fiscal_documents (company_ruc, created_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_fiscal_doc_company_ruc ON fiscal_documents');
        $this->addSql('ALTER TABLE fiscal_documents DROP company_ruc');
    }
}
