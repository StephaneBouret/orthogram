<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260531170055 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, status VARCHAR(20) NOT NULL, price_cents INT NOT NULL, title VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, is_lifetime TINYINT DEFAULT 0 NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, payment_reference VARCHAR(255) DEFAULT NULL, terms_accepted_at DATETIME DEFAULT NULL, immediate_access_requested_at DATETIME DEFAULT NULL, withdrawal_right_waived_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT DEFAULT NULL, INDEX IDX_A3C664D3A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3A76ED395');
        $this->addSql('DROP TABLE subscription');
    }
}
