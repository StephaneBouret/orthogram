<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémoriser le dernier cours consulté sur le compte, indépendamment de la progression.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('ALTER TABLE user ADD last_visited_course_id INT DEFAULT NULL, ADD course_bookmark_version INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX IDX_USER_LAST_VISITED_COURSE ON user (last_visited_course_id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_USER_LAST_VISITED_COURSE FOREIGN KEY (last_visited_course_id) REFERENCES courses (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_USER_LAST_VISITED_COURSE');
        $this->addSql('DROP INDEX IDX_USER_LAST_VISITED_COURSE ON user');
        $this->addSql('ALTER TABLE user DROP last_visited_course_id, DROP course_bookmark_version');
    }
}
