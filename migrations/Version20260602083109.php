<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602083109 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Per-event view counts (event_stat) for "most popular events".';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_stat (day DATE NOT NULL, event_id INT NOT NULL, views INT NOT NULL DEFAULT 0, PRIMARY KEY(day, event_id))');
        $this->addSql('CREATE INDEX idx_event_stat_event ON event_stat (event_id)');
        $this->addSql('ALTER TABLE event_stat ADD CONSTRAINT fk_event_stat_event FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_stat DROP CONSTRAINT fk_event_stat_event');
        $this->addSql('DROP TABLE event_stat');
    }
}
