<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the never-used messenger_messages table (created by the initial
 * skeleton; symfony/messenger is not installed, so migrations:diff kept
 * proposing to drop it) and index import_run for the per-source lookups.
 */
final class Version20260902090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unused messenger_messages; composite (source_id, started_at) index on import_run';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
        $this->addSql('CREATE INDEX idx_import_run_source_started ON import_run (source_id, started_at)');
        // Covered by the composite index (leading column).
        $this->addSql('DROP INDEX IF EXISTS idx_import_run_source');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_import_run_source ON import_run (source_id)');
        $this->addSql('DROP INDEX idx_import_run_source_started');
        // messenger_messages is deliberately not recreated: nothing ever wrote to it.
    }
}
