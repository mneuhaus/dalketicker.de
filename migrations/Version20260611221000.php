<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable the first automated Weserticker sources for Minden-Luebbecke.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET canonical_host = 'weserticker.neuhaus.nrw',
                host_aliases = '["weserticker.de","www.weserticker.de","weserticker.traefik.me"]'::json
            WHERE region_key = 'minden-luebbecke'
        SQL);

        $this->updateSource('muehlenkreis_events', 'html', 'https://www.muehlenkreis.de/Erleben-Entdecken/Erkunden/Veranstaltungen/index.php?La=1&ModID=11&NavID=3147.45&catsum=1&k_sub=1&kat=2832.78.1&object=tx%2C1891.869.1', 'ikiss_modid11', '{"city":"Kreis Minden-Lübbecke","maxEvents":180}', false, true);
        $this->updateSource('teutoburgerwald_minden_luebbecke_events', 'html', 'https://www.teutoburgerwald.de/region/gastro-event/veranstaltungskalender', 'erfolgskreis_gt', '{"city":"Kreis Minden-Lübbecke","experience":"teutoburgerwald","bootstrapUrl":"https://pages.destination.one/de/teutoburgerwald/default/search/Event/mode:next_months,12/sort:chronological","allowedCities":["Minden","Bad Oeynhausen","Espelkamp","Hille","Hüllhorst","Lübbecke","Petershagen","Porta Westfalica","Preußisch Oldendorf","Rahden","Stemwede"],"postalPrefixes":["32312","32339","32351","32361","32369","32423","32425","32427","32429","32457","32469","32479","32545","32547","32549","32609"],"maxItems":1200}', true, true);
        $this->updateSource('westliches_weserbergland_events', 'html', 'https://www.westliches-weserbergland.de/', 'erfolgskreis_gt', '{"city":"Kreis Minden-Lübbecke","experience":"westliches-weserbergland","bootstrapUrl":"https://pages.destination.one/de/westliches-weserbergland/default/search/Event/mode:next_months,12/sort:chronological","allowedCities":["Porta Westfalica","Petershagen"],"postalPrefixes":["32457","32469"],"maxItems":500}', true, true);
        $this->updateSource('vhs_minden_bad_oeynhausen', 'html', 'https://www.vhs-minden.de/kurssuche/liste', 'vhs_re', '{"city":"Kreis Minden-Lübbecke","category":"bildung","maxPages":12}', false, true);
        $this->updateSource('bad_holzhausen_tribe', 'json', 'https://www.bad-holzhausen.de/wp-json/tribe/events/v1/events', 'tribe_events', '{"city":"Preußisch Oldendorf"}', false, false);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE region
            SET canonical_host = 'weserticker.de',
                host_aliases = '["www.weserticker.de","weserticker.traefik.me"]'::json
            WHERE region_key = 'minden-luebbecke'
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE source
            SET enabled = FALSE
            WHERE source_key IN (
                'muehlenkreis_events',
                'teutoburgerwald_minden_luebbecke_events',
                'westliches_weserbergland_events',
                'vhs_minden_bad_oeynhausen',
                'bad_holzhausen_tribe'
            )
              AND region_id = (SELECT id FROM region WHERE region_key = 'minden-luebbecke')
        SQL);
    }

    private function updateSource(string $key, string $type, string $url, string $importer, string $configJson, bool $factsOnly, bool $enabled): void
    {
        $this->addSql(<<<SQL
            UPDATE source
            SET type = '$type',
                url = '$url',
                importer = '$importer',
                enabled = {$this->bool($enabled)},
                facts_only = {$this->bool($factsOnly)},
                config = (COALESCE(config, '{}'::json)::jsonb || '$configJson'::jsonb)::json
            WHERE source_key = '$key'
              AND region_id = (SELECT id FROM region WHERE region_key = 'minden-luebbecke')
        SQL);
    }

    private function bool(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }
}
