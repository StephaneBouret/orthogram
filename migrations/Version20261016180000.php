<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dictées 2B : références audio privées, segments et validation des occurrences.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL requis.');
        $this->addSql('ALTER TABLE dictation_version ADD complete_audio JSON DEFAULT NULL, ADD writing_audio JSON DEFAULT NULL, ADD segments JSON DEFAULT NULL, ADD segments_audio_key VARCHAR(36) DEFAULT NULL, ADD variants_text_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('UPDATE dictation_version SET segments = JSON_ARRAY()');
        $this->addSql('ALTER TABLE dictation_version MODIFY segments JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dictation_version DROP complete_audio, DROP writing_audio, DROP segments, DROP segments_audio_key, DROP variants_text_hash');
    }
}
