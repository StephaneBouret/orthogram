<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611134749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create courses table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE courses (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, content_type VARCHAR(20) NOT NULL, short_description LONGTEXT DEFAULT NULL, correction_text LONGTEXT DEFAULT NULL, position INT DEFAULT 0 NOT NULL, partial_file_name VARCHAR(255) DEFAULT NULL, audio_file_name VARCHAR(255) DEFAULT NULL, video_name VARCHAR(255) DEFAULT NULL, updated_at DATETIME DEFAULT NULL, section_id INT NOT NULL, INDEX IDX_A9A55A4CD823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE courses ADD CONSTRAINT FK_A9A55A4CD823E37A FOREIGN KEY (section_id) REFERENCES sections (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE courses DROP FOREIGN KEY FK_A9A55A4CD823E37A');
        $this->addSql('DROP TABLE courses');
    }
}
