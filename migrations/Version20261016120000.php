<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dictées : identité par section, versions éditoriales immuables et tentative pédagogique unique.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('CREATE TABLE dictation (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, section_id INT NOT NULL, UNIQUE INDEX UNIQ_4DA9C497D823E37A (section_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dictation_version (id INT AUTO_INCREMENT NOT NULL, revision INT DEFAULT 1 NOT NULL, number INT NOT NULL, title VARCHAR(255) NOT NULL, canonical_text LONGTEXT NOT NULL, variants JSON NOT NULL, correction_content LONGTEXT DEFAULT NULL, contract_version VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, published_at DATETIME DEFAULT NULL, dictation_id INT NOT NULL, INDEX IDX_4833E8109E81620E (dictation_id), UNIQUE INDEX UNIQ_DICTATION_VERSION (dictation_id, number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE dictation_attempt (id INT AUTO_INCREMENT NOT NULL, state VARCHAR(32) NOT NULL, revision INT NOT NULL, draft LONGTEXT NOT NULL, initial_copy LONGTEXT DEFAULT NULL, initial_result JSON DEFAULT NULL, corrected_copy LONGTEXT DEFAULT NULL, corrected_result JSON DEFAULT NULL, final_copy LONGTEXT DEFAULT NULL, final_result JSON DEFAULT NULL, receipts JSON NOT NULL, late_passage TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, initial_evaluated_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, resumed_at DATETIME DEFAULT NULL, finalized_at DATETIME DEFAULT NULL, user_id INT NOT NULL, dictation_id INT NOT NULL, version_id INT NOT NULL, INDEX IDX_EFC339B5A76ED395 (user_id), INDEX IDX_EFC339B59E81620E (dictation_id), INDEX IDX_EFC339B54BBC2705 (version_id), UNIQUE INDEX UNIQ_DICTATION_ATTEMPT (user_id, dictation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE dictation ADD CONSTRAINT FK_4DA9C497D823E37A FOREIGN KEY (section_id) REFERENCES sections (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE dictation_version ADD CONSTRAINT FK_4833E8109E81620E FOREIGN KEY (dictation_id) REFERENCES dictation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE dictation_attempt ADD CONSTRAINT FK_EFC339B5A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dictation_attempt ADD CONSTRAINT FK_EFC339B59E81620E FOREIGN KEY (dictation_id) REFERENCES dictation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE dictation_attempt ADD CONSTRAINT FK_EFC339B54BBC2705 FOREIGN KEY (version_id) REFERENCES dictation_version (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(!$this->platform instanceof AbstractMySQLPlatform, 'Cette migration cible MySQL/MariaDB.');
        $this->addSql('DROP TABLE dictation_attempt');
        $this->addSql('DROP TABLE dictation_version');
        $this->addSql('DROP TABLE dictation');
    }
}
