<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Parcours dictées : inscription prospective, tous les comptes existants restent exemptés.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('ALTER TABLE user ADD dictation_prerequisites JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP dictation_prerequisites');
    }
}
