<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exercices and link courses to an optional exercice.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exercice (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, instruction LONGTEXT NOT NULL, type VARCHAR(50) NOT NULL, data JSON NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE courses ADD exercice_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_A9A55A4C89D40298 ON courses (exercice_id)');
        $this->addSql('ALTER TABLE courses ADD CONSTRAINT FK_9B85DD1C83F9A54D FOREIGN KEY (exercice_id) REFERENCES exercice (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP FOREIGN KEY FK_9B85DD1C83F9A54D');
        $this->addSql('DROP INDEX IDX_A9A55A4C89D40298 ON courses');
        $this->addSql('ALTER TABLE courses DROP exercice_id');
        $this->addSql('DROP TABLE exercice');
    }
}
