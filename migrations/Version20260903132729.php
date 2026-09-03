<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903132729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table des rappels d\'apprentissage.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE learning_reminder (id INT AUTO_INCREMENT NOT NULL, frequency VARCHAR(10) NOT NULL, reminder_time TIME NOT NULL, weekdays JSON NOT NULL, scheduled_date DATE DEFAULT NULL, timezone VARCHAR(64) NOT NULL, next_run_at DATETIME DEFAULT NULL, last_sent_at DATETIME DEFAULT NULL, enabled TINYINT DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_LEARNING_REMINDER_DUE (enabled, next_run_at), UNIQUE INDEX UNIQ_LEARNING_REMINDER_USER (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE learning_reminder ADD CONSTRAINT FK_64474B5EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE learning_reminder DROP FOREIGN KEY FK_64474B5EA76ED395');
        $this->addSql('DROP TABLE learning_reminder');
    }
}
