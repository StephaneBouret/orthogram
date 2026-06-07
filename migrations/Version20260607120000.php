<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user deletion and anonymization timestamps.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD deleted_at DATETIME DEFAULT NULL, ADD anonymized_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP deleted_at, DROP anonymized_at');
    }
}
