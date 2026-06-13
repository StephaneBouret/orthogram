<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add display position to sections.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sections ADD position INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE sections s INNER JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY program_id ORDER BY id ASC) - 1 AS new_position FROM sections) ordered_sections ON ordered_sections.id = s.id SET s.position = ordered_sections.new_position');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sections DROP position');
    }
}
