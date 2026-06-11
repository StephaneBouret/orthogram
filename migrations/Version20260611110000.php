<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending email confirmation fields to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD pending_email VARCHAR(180) DEFAULT NULL, ADD email_change_token VARCHAR(64) DEFAULT NULL, ADD email_change_requested_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_EMAIL_CHANGE_TOKEN ON user (email_change_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_EMAIL_CHANGE_TOKEN ON user');
        $this->addSql('ALTER TABLE user DROP pending_email, DROP email_change_token, DROP email_change_requested_at');
    }
}
