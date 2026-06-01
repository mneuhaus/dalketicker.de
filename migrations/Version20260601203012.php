<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601203012 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event.summary (AI teaser) and source.facts_only (legal safeguard); flag aggregator sources.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD summary TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD facts_only BOOLEAN NOT NULL DEFAULT false');
        // Aggregator/competitor feeds → facts only (no foreign text/images).
        $this->addSql("UPDATE source SET facts_only = true WHERE key IN ('auf_schluer', 'radio_gt', 'erfolgskreis_gt', 'marktcom', 'flowl')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event DROP summary');
        $this->addSql('ALTER TABLE source DROP facts_only');
    }
}
