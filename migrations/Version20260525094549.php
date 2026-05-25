<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260525094549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE program_detail (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, content LONGTEXT DEFAULT NULL, program_id INT NOT NULL, INDEX IDX_7A4641C93EB8070A (program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE program_highlight (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) DEFAULT NULL, content LONGTEXT DEFAULT NULL, program_id INT NOT NULL, INDEX IDX_9FAA29EB3EB8070A (program_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE program_detail ADD CONSTRAINT FK_7A4641C93EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
        $this->addSql('ALTER TABLE program_highlight ADD CONSTRAINT FK_9FAA29EB3EB8070A FOREIGN KEY (program_id) REFERENCES program (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE program_detail DROP FOREIGN KEY FK_7A4641C93EB8070A');
        $this->addSql('ALTER TABLE program_highlight DROP FOREIGN KEY FK_9FAA29EB3EB8070A');
        $this->addSql('DROP TABLE program_detail');
        $this->addSql('DROP TABLE program_highlight');
    }
}
