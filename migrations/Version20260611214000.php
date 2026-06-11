<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611214000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the first automated Sparrenticker sources for Bielefeld.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["www.sparrenticker.de","sparrenticker.neuhaus.nrw","sparrenticker.traefik.me"]'::json
            WHERE region_key = 'bielefeld'
        SQL);

        $this->updateSource('bielefeld_jetzt_cityteam_events', 'html', 'https://www.citybielefeld.de/termine/monat', 'bielefeld_jetzt', '{"city":"Bielefeld"}');
        $this->updateSource('uni_bielefeld_veranstaltungen', 'html', 'https://aktuell.uni-bielefeld.de/alle-events/', 'tribe_events', '{"city":"Bielefeld"}');
        $this->updateSource('stadtbibliothek_bielefeld_events', 'ics', 'https://events.stadtbibliothek-bielefeld.de/events/ical/?locale=de', 'ics', '{"city":"Bielefeld","venue":"Stadtbibliothek Bielefeld","category":"bildung"}');
        $this->updateSource('historisches_museum_bielefeld', 'html', 'https://www.historisches-museum-bielefeld.de/events/', 'tribe_events', '{"city":"Bielefeld"}');
        $this->updateSource('vhs_bielefeld', 'html', 'https://www.vhs-bielefeld.de/kurssuche/liste', 'vhs_re', '{"city":"Bielefeld","category":"bildung","maxPages":12}');
        $this->updateSource('stereo_bielefeld', 'html', 'https://stereo-bielefeld.de/programm/', 'jsonld', '{"city":"Bielefeld","venue":"Stereo Bielefeld","category":"party"}');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET host_aliases = '["www.sparrenticker.de","sparrenticker.traefik.me"]'::json
            WHERE region_key = 'bielefeld'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE source
            SET enabled = FALSE
            WHERE source_key IN (
                'bielefeld_jetzt_cityteam_events',
                'uni_bielefeld_veranstaltungen',
                'stadtbibliothek_bielefeld_events',
                'historisches_museum_bielefeld',
                'vhs_bielefeld',
                'stereo_bielefeld'
            )
              AND region_id = (SELECT id FROM region WHERE region_key = 'bielefeld')
        SQL);
    }

    private function updateSource(string $key, string $type, string $url, string $importer, string $configJson): void
    {
        $this->addSql(<<<SQL
            UPDATE source
            SET type = '$type',
                url = '$url',
                importer = '$importer',
                enabled = TRUE,
                facts_only = FALSE,
                config = (COALESCE(config, '{}'::json)::jsonb || '$configJson'::jsonb)::json
            WHERE source_key = '$key'
              AND region_id = (SELECT id FROM region WHERE region_key = 'bielefeld')
        SQL);
    }
}
