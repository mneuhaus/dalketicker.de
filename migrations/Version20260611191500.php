<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611191500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope cookieless visit statistics by region.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stat ADD region_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE page_stat ADD region_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE visitor_day ADD region_id INT DEFAULT NULL');

        $this->addSql("UPDATE daily_stat SET region_id = (SELECT id FROM region WHERE region_key = 'guetersloh')");
        $this->addSql("UPDATE page_stat SET region_id = (SELECT id FROM region WHERE region_key = 'guetersloh')");
        $this->addSql("UPDATE visitor_day SET region_id = (SELECT id FROM region WHERE region_key = 'guetersloh')");

        $this->addSql('ALTER TABLE daily_stat ALTER region_id SET NOT NULL');
        $this->addSql('ALTER TABLE page_stat ALTER region_id SET NOT NULL');
        $this->addSql('ALTER TABLE visitor_day ALTER region_id SET NOT NULL');

        $this->addSql('ALTER TABLE daily_stat DROP CONSTRAINT daily_stat_pkey');
        $this->addSql('ALTER TABLE page_stat DROP CONSTRAINT page_stat_pkey');
        $this->addSql('ALTER TABLE visitor_day DROP CONSTRAINT visitor_day_pkey');

        $this->addSql('ALTER TABLE daily_stat ADD PRIMARY KEY (region_id, day)');
        $this->addSql('ALTER TABLE page_stat ADD PRIMARY KEY (region_id, day, route_key)');
        $this->addSql('ALTER TABLE visitor_day ADD PRIMARY KEY (region_id, day, token)');

        $this->addSql('ALTER TABLE daily_stat ADD CONSTRAINT FK_DAILY_STAT_REGION FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE page_stat ADD CONSTRAINT FK_PAGE_STAT_REGION FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE visitor_day ADD CONSTRAINT FK_VISITOR_DAY_REGION FOREIGN KEY (region_id) REFERENCES region (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE daily_stat DROP CONSTRAINT FK_DAILY_STAT_REGION');
        $this->addSql('ALTER TABLE page_stat DROP CONSTRAINT FK_PAGE_STAT_REGION');
        $this->addSql('ALTER TABLE visitor_day DROP CONSTRAINT FK_VISITOR_DAY_REGION');

        $this->addSql('ALTER TABLE daily_stat DROP CONSTRAINT daily_stat_pkey');
        $this->addSql('CREATE TEMPORARY TABLE daily_stat_rollback AS SELECT day, SUM(views)::INT AS views, SUM(visitors)::INT AS visitors FROM daily_stat GROUP BY day');
        $this->addSql('TRUNCATE daily_stat');
        $this->addSql('INSERT INTO daily_stat (day, views, visitors, region_id) SELECT day, views, visitors, (SELECT id FROM region WHERE region_key = \'guetersloh\') FROM daily_stat_rollback');
        $this->addSql('ALTER TABLE daily_stat DROP region_id');
        $this->addSql('ALTER TABLE daily_stat ADD PRIMARY KEY (day)');

        $this->addSql('ALTER TABLE page_stat DROP CONSTRAINT page_stat_pkey');
        $this->addSql('CREATE TEMPORARY TABLE page_stat_rollback AS SELECT day, route_key, SUM(views)::INT AS views FROM page_stat GROUP BY day, route_key');
        $this->addSql('TRUNCATE page_stat');
        $this->addSql('INSERT INTO page_stat (day, route_key, views, region_id) SELECT day, route_key, views, (SELECT id FROM region WHERE region_key = \'guetersloh\') FROM page_stat_rollback');
        $this->addSql('ALTER TABLE page_stat DROP region_id');
        $this->addSql('ALTER TABLE page_stat ADD PRIMARY KEY (day, route_key)');

        $this->addSql('ALTER TABLE visitor_day DROP CONSTRAINT visitor_day_pkey');
        $this->addSql('CREATE TEMPORARY TABLE visitor_day_rollback AS SELECT DISTINCT day, token FROM visitor_day');
        $this->addSql('TRUNCATE visitor_day');
        $this->addSql('INSERT INTO visitor_day (day, token, region_id) SELECT day, token, (SELECT id FROM region WHERE region_key = \'guetersloh\') FROM visitor_day_rollback');
        $this->addSql('ALTER TABLE visitor_day DROP region_id');
        $this->addSql('ALTER TABLE visitor_day ADD PRIMARY KEY (day, token)');
    }
}
