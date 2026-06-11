<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611203500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Wire the first additional automated Paderticker sources.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'erfolgskreis_gt',
                enabled = FALSE,
                facts_only = TRUE,
                config = (COALESCE(config, '{}'::json)::jsonb || '{"experience":"paderborner-land","bootstrapUrl":"https://pages.destination.one/de/paderborner-land/default/search/Event/mode:next_months,12/sort:chronological","maxItems":1200,"city":"Kreis Paderborn"}'::jsonb)::json
            WHERE source_key = 'paderborner_land_events'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'kommunal_events',
                enabled = TRUE
            WHERE source_key = 'delbrueck_veranstaltungen'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'kommunal_events',
                enabled = TRUE
            WHERE source_key = 'stadthalle_delbrueck'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'paderhalle',
                enabled = TRUE,
                facts_only = FALSE,
                config = (COALESCE(config, '{}'::json)::jsonb || '{"dataUrl":"https://www.paderhalle.de/data/events.json","city":"Paderborn"}'::jsonb)::json
            WHERE source_key = 'paderhalle_events'
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE source
            SET importer = 'manual',
                enabled = FALSE,
                facts_only = FALSE
            WHERE source_key IN ('paderborner_land_events', 'delbrueck_veranstaltungen', 'stadthalle_delbrueck', 'paderhalle_events')
              AND region_id = (SELECT id FROM region WHERE region_key = 'paderborn')
        SQL);
    }
}
