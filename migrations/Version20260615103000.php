<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exercice attempts table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exercice_attempt (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, exercice_id INT NOT NULL, score INT NOT NULL, total INT NOT NULL, percentage INT NOT NULL, selected_token_ids JSON NOT NULL, correction_items JSON NOT NULL, submitted_at DATETIME NOT NULL, INDEX IDX_E55774CBA76ED395 (user_id), INDEX IDX_E55774CB89D40298 (exercice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE exercice_attempt ADD CONSTRAINT FK_5CF50C31A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE exercice_attempt ADD CONSTRAINT FK_5CF50C3183F9A54D FOREIGN KEY (exercice_id) REFERENCES exercice (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exercice_attempt DROP FOREIGN KEY FK_5CF50C31A76ED395');
        $this->addSql('ALTER TABLE exercice_attempt DROP FOREIGN KEY FK_5CF50C3183F9A54D');
        $this->addSql('DROP TABLE exercice_attempt');
    }
}
