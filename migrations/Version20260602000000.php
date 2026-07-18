<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * fiscal_documents: decisión MANUAL de sincronización al tenant tras una consulta de validez.
 * tenant_sync_state (pending/synced/skipped) + razón cuando se omite + fecha de decisión.
 */
final class Version20260602000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fiscal_documents: tenant_sync_state + tenant_sync_reason + tenant_sync_decided_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents ADD tenant_sync_state VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE fiscal_documents ADD tenant_sync_reason LONGTEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE fiscal_documents ADD tenant_sync_decided_at DATETIME DEFAULT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_documents DROP tenant_sync_state');
        $this->addSql('ALTER TABLE fiscal_documents DROP tenant_sync_reason');
        $this->addSql('ALTER TABLE fiscal_documents DROP tenant_sync_decided_at');
    }
}
