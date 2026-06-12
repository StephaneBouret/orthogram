<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612101537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add estimated duration to courses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses ADD duration_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP duration_minutes');
    }
}
