<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611211500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the Paderborner Land feed for Paderticker.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'erfolgskreis_gt',
                enabled = TRUE,
                facts_only = TRUE,
                config = (COALESCE(config, '{}'::json)::jsonb || '{"experience":"paderborner-land","bootstrapUrl":"https://pages.destination.one/de/paderborner-land/default/search/Event/mode:next_months,12/sort:chronological","maxItems":1200,"city":"Kreis Paderborn"}'::jsonb)::json
            WHERE source_key = 'paderborner_land_events'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE source
            SET enabled = FALSE,
                facts_only = TRUE
            WHERE source_key = 'paderborner_land_events'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
    }
}
