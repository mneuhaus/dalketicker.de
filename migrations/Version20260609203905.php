<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260609203905 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Composite index event (venue_id, title) for the correlated series-collapse subquery on the start page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_event_venue_title ON event (venue_id, title)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_event_venue_title');
    }
}
