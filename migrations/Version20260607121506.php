<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607121506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_device (id INT AUTO_INCREMENT NOT NULL, device_uuid VARCHAR(64) NOT NULL, user_agent_hash VARCHAR(255) DEFAULT NULL, device_type VARCHAR(50) DEFAULT NULL, browser VARCHAR(100) DEFAULT NULL, platform VARCHAR(100) DEFAULT NULL, first_ip VARCHAR(45) DEFAULT NULL, last_ip VARCHAR(45) DEFAULT NULL, status VARCHAR(20) NOT NULL, first_seen_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_6C7DADB3A76ED395 (user_id), UNIQUE INDEX UNIQ_USER_DEVICE_UUID (user_id, device_uuid), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_device ADD CONSTRAINT FK_6C7DADB3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_device DROP FOREIGN KEY FK_6C7DADB3A76ED395');
        $this->addSql('DROP TABLE user_device');
    }
}
