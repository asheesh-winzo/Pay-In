<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260524082340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE accounts (id VARCHAR(36) NOT NULL, owner_id VARCHAR(100) NOT NULL, balance_minor_units BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, is_active TINYINT NOT NULL, version INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_CAC89EAC7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE transfer_transactions (id VARCHAR(36) NOT NULL, source_account_id VARCHAR(36) NOT NULL, destination_account_id VARCHAR(36) NOT NULL, amount_minor_units BIGINT NOT NULL, currency VARCHAR(3) NOT NULL, status VARCHAR(20) NOT NULL, description VARCHAR(255) DEFAULT NULL, idempotency_key VARCHAR(255) NOT NULL, failure_reason VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_A475958F7FD1C147 (idempotency_key), INDEX idx_source_account (source_account_id), INDEX idx_destination_account (destination_account_id), INDEX idx_status (status), INDEX idx_idempotency_key (idempotency_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE transfer_transactions');
    }
}
