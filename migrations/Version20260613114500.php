<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613114500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create lessons table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE lesson (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, course_id INT NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, studied_at DATETIME NOT NULL, INDEX IDX_F87474F3A76ED395 (user_id), INDEX IDX_F87474F3591CC992 (course_id), UNIQUE INDEX UNIQ_LESSON_USER_COURSE (user_id, course_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE lesson ADD CONSTRAINT FK_F87474F3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson ADD CONSTRAINT FK_F87474F3591CC992 FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson DROP FOREIGN KEY FK_F87474F3A76ED395');
        $this->addSql('ALTER TABLE lesson DROP FOREIGN KEY FK_F87474F3591CC992');
        $this->addSql('DROP TABLE lesson');
    }
}
