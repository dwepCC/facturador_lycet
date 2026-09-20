<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fase 8 del Panel Central Fiscal: `/superadmin/fiscal/operations/queue` tardaba 7.5-7.8s en
 * producción. Medido en vivo (curl directo a facturador_lycet + SHOW FULL PROCESSLIST durante
 * la petición): el tiempo completo lo consume UNA sola query, la de
 * FiscalDocumentRepository::findByStatuses() (`WHERE status IN (...) AND document_type NOT IN
 * ('00','NV') ORDER BY updated_at DESC LIMIT 25`).
 *
 * `updated_at` no tenía ningún índice (confirmado con SHOW INDEX), así que MySQL hacía
 * full table scan + filesort sobre las ~38.6k filas de `fiscal_documents`. La versión estrecha
 * de esa query (`SELECT id` en vez de todas las columnas) solo tardaba 32ms pese al scan
 * completo — pero Doctrine hidrata la entidad completa, incluyendo `snapshot_json` (hasta 16KB
 * por fila, ~53MB en total), así que el filesort tiene que mover filas anchas en vez de filas
 * de un solo entero. Reproducido exacto: `SELECT *` con el mismo WHERE/ORDER/LIMIT tomó 7.621s
 * en producción, contra 0.032s de `SELECT id`.
 *
 * Como `status` tiene cardinalidad baja (accepted/observed/error/rejected/sent/...), un índice
 * (status, updated_at) deja que MySQL resuelva el `IN` + `ORDER BY` con un range scan por cada
 * status en vez de escanear y ordenar la tabla entera — y de paso vuelve index-only las 4
 * llamadas a countByStatuses() del mismo endpoint (COUNT(id) ya está en la clave primaria
 * implícita de InnoDB).
 */
final class Version20260920010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Agrega idx_fiscal_doc_status_updated (status, updated_at) en fiscal_documents para el monitor de cola de /fiscal-operations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_fiscal_doc_status_updated ON fiscal_documents (status, updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_fiscal_doc_status_updated ON fiscal_documents');
    }
}
