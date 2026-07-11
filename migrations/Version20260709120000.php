<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260709120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI-Dedup: Admin-Undo als dauerhaftes Veto speichern (undone_by_admin_at), damit der nächtliche Lauf einen überstimmten Merge nicht erneut anwendet.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dedup_decision ADD undone_by_admin_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_dedup_decision DROP undone_by_admin_at');
    }
}
