<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260602064240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event ALTER locked_fields DROP DEFAULT');
        $this->addSql('ALTER TABLE source ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD approval_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE source ADD approval_image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE source ALTER facts_only DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE event ALTER locked_fields SET DEFAULT \'[]\'');
        $this->addSql('ALTER TABLE source DROP approved_at');
        $this->addSql('ALTER TABLE source DROP approval_note');
        $this->addSql('ALTER TABLE source DROP approval_image');
        $this->addSql('ALTER TABLE source ALTER facts_only SET DEFAULT false');
    }
}
