<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * "Atendido": campo administrativo independiente del status técnico SUNAT/PSE — un documento
 * puede quedar 'rejected'/'error' para siempre (así lo ve SUNAT) pero ya haber sido revisado
 * y resuelto por un admin del panel central (habló con el cliente, lo corrigió por otra vía,
 * decidió que no aplica reenvío). Antes no existía ninguna forma de distinguir "ya lo vi y no
 * hace falta nada más" de "sigue pendiente de acción" — la lista de documentos con error/
 * rechazados crecía indefinidamente aunque la mayoría ya estuviera resuelta.
 *
 * `idx_fiscal_doc_attended` acompaña a los guards que excluyen documentos atendidos de los
 * barridos automáticos de reintento (FiscalDocumentRepository::findRetryableTransientErrors/
 * findEmitOrphans), que corren cada 60s desde el worker — sin índice, cada barrido escanearía
 * la tabla completa filtrando por esta columna.
 */
final class Version20260922130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega attended/attended_reason/attended_by/attended_at a fiscal_documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents ADD attended TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE fiscal_documents ADD attended_reason LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_documents ADD attended_by VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_documents ADD attended_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_fiscal_doc_attended ON fiscal_documents (attended)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_fiscal_doc_attended ON fiscal_documents');
        $this->addSql('ALTER TABLE fiscal_documents DROP attended, DROP attended_reason, DROP attended_by, DROP attended_at');
    }
}
