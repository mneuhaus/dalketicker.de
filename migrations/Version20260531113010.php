<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260531113010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cookieless visit stats: daily_stat, page_stat, visitor_day (aggregate counts only).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_stat (day DATE NOT NULL, views INT NOT NULL DEFAULT 0, visitors INT NOT NULL DEFAULT 0, PRIMARY KEY(day))');
        $this->addSql('CREATE TABLE page_stat (day DATE NOT NULL, route_key VARCHAR(64) NOT NULL, views INT NOT NULL DEFAULT 0, PRIMARY KEY(day, route_key))');
        $this->addSql('CREATE TABLE visitor_day (day DATE NOT NULL, token CHAR(64) NOT NULL, PRIMARY KEY(day, token))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE daily_stat');
        $this->addSql('DROP TABLE page_stat');
        $this->addSql('DROP TABLE visitor_day');
    }
}
