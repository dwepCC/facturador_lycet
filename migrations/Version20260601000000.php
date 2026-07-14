<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FiscalDocument: error_type y retryable para desambiguar STATUS_ERROR
 * (transitorio reintentable vs permanente vs rechazo de negocio) y permitir
 * el reconcile de errores transitorios.
 */
final class Version20260601000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fiscal_documents: error_type + retryable';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents ADD error_type VARCHAR(20) DEFAULT NULL');
        $this->addSql("ALTER TABLE fiscal_documents ADD retryable TINYINT(1) NOT NULL DEFAULT 1");
        // Marcar como no reintentables los errores previos ya terminales para no re-encolarlos masivamente.
        $this->addSql("UPDATE fiscal_documents SET retryable = 0 WHERE status IN ('accepted','observed','rejected','cancelled')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents DROP error_type');
        $this->addSql('ALTER TABLE fiscal_documents DROP retryable');
    }
}
