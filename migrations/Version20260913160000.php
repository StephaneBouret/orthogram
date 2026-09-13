<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tentatives de quiz liées au cours, contenu figé et réponses persistées.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('CREATE TABLE quiz_attempt (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, active_slot INT DEFAULT NULL, total INT NOT NULL, score INT NOT NULL, snapshot JSON NOT NULL, responses JSON NOT NULL, user_id INT NOT NULL, course_id INT DEFAULT NULL, quiz_id INT DEFAULT NULL, previous_attempt_id INT DEFAULT NULL, INDEX IDX_AB6AFC6A76ED395 (user_id), INDEX IDX_AB6AFC6591CC992 (course_id), INDEX IDX_AB6AFC6853CD175 (quiz_id), UNIQUE INDEX UNIQ_QUIZ_ACTIVE (user_id, course_id, quiz_id, active_slot), UNIQUE INDEX UNIQ_QUIZ_RESTART (previous_attempt_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quiz_attempt ADD CONSTRAINT FK_AB6AFC6C691FCD2 FOREIGN KEY (previous_attempt_id) REFERENCES quiz_attempt (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('DROP TABLE quiz_attempt');
    }
}
