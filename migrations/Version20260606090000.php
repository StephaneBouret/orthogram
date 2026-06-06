<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account status to users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE user ADD account_status VARCHAR(32) DEFAULT 'active' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP account_status');
    }
}
