<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Credenciales GRE OAuth por empresa (NubeFact / API REST).
 */
final class Version20260531000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Empresa: gre_client_id y gre_client_secret opcionales';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE empresa ADD gre_client_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE empresa ADD gre_client_secret VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE empresa DROP gre_client_secret');
        $this->addSql('ALTER TABLE empresa DROP gre_client_id');
    }
}
