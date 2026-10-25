<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz : renouvellement désactivé, questions initiales par défaut et équilibre explicite facultatif.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quiz ADD renewal_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD error_question_quota INT DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz_question ADD reserve TINYINT(1) DEFAULT 0 NOT NULL, ADD contains_error TINYINT(1) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les rôles et métadonnées de composition doivent être conservés. Restaurer une sauvegarde pour revenir en arrière.');
    }
}
