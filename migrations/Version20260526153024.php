<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260526153024 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sections table linked to programs';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE sections (
              id INT AUTO_INCREMENT NOT NULL,
              name VARCHAR(255) NOT NULL,
              slug VARCHAR(255) NOT NULL,
              short_description LONGTEXT DEFAULT NULL,
              program_id INT NOT NULL,
              INDEX IDX_2B9643983EB8070A (program_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              sections
            ADD
              CONSTRAINT FK_2B9643983EB8070A FOREIGN KEY (program_id) REFERENCES program (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sections DROP FOREIGN KEY FK_2B9643983EB8070A');
        $this->addSql('DROP TABLE sections');
    }
}
