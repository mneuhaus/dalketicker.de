<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260601102446 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kategorien als n:m (event_category) statt einzelner FK; bestehende Zuordnungen werden übernommen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE event_category (event_id INT NOT NULL, category_id INT NOT NULL, PRIMARY KEY (event_id, category_id))');
        $this->addSql('CREATE INDEX IDX_40A0F01171F7E88B ON event_category (event_id)');
        $this->addSql('CREATE INDEX IDX_40A0F01112469DE2 ON event_category (category_id)');
        $this->addSql('ALTER TABLE event_category ADD CONSTRAINT FK_40A0F01171F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE event_category ADD CONSTRAINT FK_40A0F01112469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        // Carry the existing single category of every event over to the join table.
        $this->addSql('INSERT INTO event_category (event_id, category_id) SELECT id, category_id FROM event WHERE category_id IS NOT NULL');
        $this->addSql('ALTER TABLE event DROP CONSTRAINT fk_3bae0aa712469de2');
        $this->addSql('DROP INDEX idx_3bae0aa712469de2');
        $this->addSql('ALTER TABLE event DROP category_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE event ADD CONSTRAINT fk_3bae0aa712469de2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_3bae0aa712469de2 ON event (category_id)');
        // Restore a single (arbitrary first) category per event before dropping the table.
        $this->addSql('UPDATE event e SET category_id = (SELECT ec.category_id FROM event_category ec WHERE ec.event_id = e.id ORDER BY ec.category_id LIMIT 1)');
        $this->addSql('ALTER TABLE event_category DROP CONSTRAINT FK_40A0F01171F7E88B');
        $this->addSql('ALTER TABLE event_category DROP CONSTRAINT FK_40A0F01112469DE2');
        $this->addSql('DROP TABLE event_category');
    }
}
