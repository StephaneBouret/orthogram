<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260614110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comment reports and comment moderation fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comment_report (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, comment_content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, comment_id INT NOT NULL, reporter_id INT NOT NULL, resolved_by_id INT DEFAULT NULL, INDEX IDX_E3C2F96F8697D13 (comment_id), INDEX IDX_E3C2F96E1CFE6F5 (reporter_id), INDEX IDX_E3C2F966713A32B (resolved_by_id), UNIQUE INDEX UNIQ_COMMENT_REPORT_REPORTER (comment_id, reporter_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96F8697D13 FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F96E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE comment_report ADD CONSTRAINT FK_E3C2F966713A32B FOREIGN KEY (resolved_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE comments ADD is_hidden TINYINT DEFAULT 0 NOT NULL, ADD hidden_at DATETIME DEFAULT NULL, ADD hidden_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE comments ADD CONSTRAINT FK_5F9E962A750EF4DA FOREIGN KEY (hidden_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5F9E962A750EF4DA ON comments (hidden_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_E3C2F96F8697D13');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_E3C2F96E1CFE6F5');
        $this->addSql('ALTER TABLE comment_report DROP FOREIGN KEY FK_E3C2F966713A32B');
        $this->addSql('ALTER TABLE comments DROP FOREIGN KEY FK_5F9E962A750EF4DA');
        $this->addSql('DROP TABLE comment_report');
        $this->addSql('DROP INDEX IDX_5F9E962A750EF4DA ON comments');
        $this->addSql('ALTER TABLE comments DROP hidden_by_id, DROP is_hidden, DROP hidden_at');
    }
}
