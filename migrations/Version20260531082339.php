<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260531082339 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event.is_course flag and event.booking_status (course availability).';
    }

    public function up(Schema $schema): void
    {
        // Add with a default so existing rows backfill to false, then drop the
        // default to keep the schema in line with the entity (no DB default).
        $this->addSql('ALTER TABLE event ADD is_course BOOLEAN NOT NULL DEFAULT false');
        $this->addSql('ALTER TABLE event ALTER COLUMN is_course DROP DEFAULT');
        $this->addSql('ALTER TABLE event ADD booking_status VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event DROP is_course');
        $this->addSql('ALTER TABLE event DROP booking_status');
    }
}
