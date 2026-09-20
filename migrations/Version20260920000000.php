<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 7 del plan de alineación del Panel Central Fiscal (limpieza de schema, sin cambios de
 * comportamiento). Elimina `idx_fiscal_doc_tenant_status` (tenant_slug, status) de
 * `fiscal_documents`: es un duplicado EXACTO de `IDX_FISCAL_TENANT_STATUS` (mismas columnas,
 * mismo orden), agregado por accidente en Version20260525000000 sin notar que el índice
 * original ya existía desde la creación de la tabla en Version20260523000000.
 *
 * Confirmado con EXPLAIN contra producción (Fase 6, ver
 * backend_go/docs/AUDITORIA-PANEL-CENTRAL-FISCAL-FASE6-CIERRE-PERFORMANCE.md) que ambos
 * índices son intercambiables para toda query real del sistema — MySQL siempre puede usar
 * IDX_FISCAL_TENANT_STATUS (que se conserva) donde antes también podía usar este duplicado.
 * No se toca ningún otro índice.
 */
final class Version20260920000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elimina idx_fiscal_doc_tenant_status: duplicado exacto de IDX_FISCAL_TENANT_STATUS (tenant_slug, status) en fiscal_documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_fiscal_doc_tenant_status ON fiscal_documents');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_fiscal_doc_tenant_status ON fiscal_documents (tenant_slug, status)');
    }
}
