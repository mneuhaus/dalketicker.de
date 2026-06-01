<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601184725 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event.locked_fields: admin-gepinnte Felder, die der Import nicht überschreibt.';
    }

    public function up(Schema $schema): void
    {
        // DEFAULT '[]' so the NOT NULL column backfills cleanly on existing rows.
        $this->addSql("ALTER TABLE event ADD locked_fields JSON NOT NULL DEFAULT '[]'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event DROP locked_fields');
    }
}
