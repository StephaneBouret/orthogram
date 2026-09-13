<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quiz, questions, propositions et association facultative des cours aux quiz.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('CREATE TABLE quiz (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_question (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, text LONGTEXT DEFAULT NULL, explanation LONGTEXT NOT NULL, multiple TINYINT NOT NULL, position INT NOT NULL, theme VARCHAR(255) DEFAULT NULL, quiz_id INT NOT NULL, INDEX IDX_6033B00B853CD175 (quiz_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz_answer (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, correct TINYINT NOT NULL, position INT NOT NULL, question_id INT NOT NULL, INDEX IDX_3799BA7C1E27F6BF (question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE quiz_question ADD CONSTRAINT FK_6033B00B853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz_answer ADD CONSTRAINT FK_3799BA7C1E27F6BF FOREIGN KEY (question_id) REFERENCES quiz_question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE courses ADD quiz_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE courses ADD CONSTRAINT FK_A9A55A4C853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A9A55A4C853CD175 ON courses (quiz_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('ALTER TABLE courses DROP FOREIGN KEY FK_A9A55A4C853CD175');
        $this->addSql('DROP INDEX IDX_A9A55A4C853CD175 ON courses');
        $this->addSql('ALTER TABLE courses DROP quiz_id');
        $this->addSql('ALTER TABLE quiz_answer DROP FOREIGN KEY FK_3799BA7C1E27F6BF');
        $this->addSql('ALTER TABLE quiz_question DROP FOREIGN KEY FK_6033B00B853CD175');
        $this->addSql('DROP TABLE quiz_answer');
        $this->addSql('DROP TABLE quiz_question');
        $this->addSql('DROP TABLE quiz');
    }
}
