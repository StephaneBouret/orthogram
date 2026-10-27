<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identités éditoriales persistantes des nouveaux quiz ; aucun import ni activation.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_manifest_identity (quiz_key VARCHAR(96) NOT NULL, quiz_id INT DEFAULT NULL, manifest_hash VARCHAR(64) NOT NULL, PRIMARY KEY (quiz_key), UNIQUE INDEX UNIQ_MANIFEST_QUIZ (quiz_id), CONSTRAINT FK_MANIFEST_QUIZ FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE SET NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les identités éditoriales, y compris les quiz supprimés.');
    }
}
