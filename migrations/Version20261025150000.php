<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Traçabilité des réserves par quiz et source éditoriale ; aucun import ni changement de configuration.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quiz_reserve_import (quiz_id INT NOT NULL, source_key VARCHAR(96) NOT NULL, question_id INT DEFAULT NULL, payload_hash VARCHAR(64) NOT NULL, manifest_hash VARCHAR(64) NOT NULL, PRIMARY KEY (quiz_id, source_key), UNIQUE INDEX UNIQ_RESERVE_IMPORT_QUESTION (question_id), INDEX IDX_RESERVE_IMPORT_QUIZ (quiz_id), CONSTRAINT FK_RESERVE_IMPORT_QUIZ FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE CASCADE, CONSTRAINT FK_RESERVE_IMPORT_QUESTION FOREIGN KEY (question_id) REFERENCES quiz_question (id) ON DELETE SET NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver la traçabilité et les historiques. Désactiver le renouvellement en premier lieu.');
    }
}
