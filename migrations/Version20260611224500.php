<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260611224500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enable automated imports for additional manual Guetersloh and Paderborn sources.';
    }

    public function up(Schema $schema): void
    {
        $this->updateSource('guetersloh', 'stadtbib_rietberg', 'html', 'https://www.rietberg.de/tourismus/freizeitangebote/veranstaltungen/veranstaltungsort/stadtbibliothek-rietberg-394.html', 'stadtbib_rietberg', '{"city":"Rietberg"}', false, true);
        $this->updateSource('guetersloh', 'museum_pab', 'html', 'https://www.museumpab.de/kunstvermittlung/veranstaltungen/', 'museum_pab', '{"city":"Werther (Westf.)"}', false, true);

        $this->updateSource('paderborn', 'teutoburgerwald_events', 'html', 'https://www.teutoburgerwald.de/region/gastro-event/veranstaltungskalender', 'erfolgskreis_gt', '{"city":"Kreis Paderborn","experience":"teutoburgerwald","bootstrapUrl":"https://pages.destination.one/de/teutoburgerwald/default/search/Event/mode:next_months,12/sort:chronological","allowedCities":["Paderborn","Altenbeken","Bad Lippspringe","Bad Wünnenberg","Borchen","Büren","Delbrück","Hövelhof","Lichtenau","Salzkotten"],"postalPrefixes":["3309","3310","3312","3314","3315","3316","3317","3318"],"maxItems":1200}', true, true);
        $this->updateSource('paderborn', 'vhs_paderborn_webbasys', 'html', 'https://vhskurse.paderborn.de/webbasys/index.php', 'vhs_re', '{"city":"Kreis Paderborn","category":"bildung","maxPages":6}', false, true);
        $this->updateSource('paderborn', 'paderborn_veranstaltungskalender', 'html', 'https://www.paderborn.de/tourismus-kultur/veranstaltungen/veranstaltungskalender.php', 'sitepark_teasers', '{"city":"Paderborn","maxPages":40,"maxEvents":500}', false, true);
        $this->updateSource('paderborn', 'stadtbibliothek_paderborn_events', 'html', 'https://www.paderborn.de/veranstaltungsorte/109010100000089642.php', 'sitepark_teasers', '{"city":"Paderborn","venue":"Stadtbibliothek Paderborn","maxPages":10,"maxEvents":120}', false, true);
    }

    public function down(Schema $schema): void
    {
        $this->disableSource('guetersloh', 'stadtbib_rietberg');
        $this->disableSource('guetersloh', 'museum_pab');
        $this->disableSource('paderborn', 'teutoburgerwald_events');
        $this->disableSource('paderborn', 'vhs_paderborn_webbasys');
        $this->disableSource('paderborn', 'paderborn_veranstaltungskalender');
        $this->disableSource('paderborn', 'stadtbibliothek_paderborn_events');
    }

    private function updateSource(string $regionKey, string $key, string $type, string $url, string $importer, string $configJson, bool $factsOnly, bool $enabled): void
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
              AND region_id = (SELECT id FROM region WHERE region_key = '$regionKey')
        SQL);
    }

    private function disableSource(string $regionKey, string $key): void
    {
        $this->addSql(<<<SQL
            UPDATE source
            SET importer = 'manual',
                enabled = FALSE,
                facts_only = FALSE
            WHERE source_key = '$key'
              AND region_id = (SELECT id FROM region WHERE region_key = '$regionKey')
        SQL);
    }

    private function bool(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }
}
