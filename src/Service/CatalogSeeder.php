<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Source;
use App\Entity\Venue;
use App\Enum\EventStatus;
use App\Enum\SourceType;
use App\Repository\CategoryRepository;
use App\Repository\SourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Seeds the real catalogue (categories + sources) idempotently and, on demand,
 * a batch of demo events. Used both by the data fixtures (dev) and the
 * prod-safe `dalketicker:seed` command.
 */
final class CatalogSeeder
{
    /** @var array<string, Category> */
    private array $categories = [];
    /** @var array<string, Venue> */
    private array $venues = [];
    /** @var array<string, Source> */
    private array $sources = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categoryRepo,
        private readonly SourceRepository $sourceRepo,
        private readonly SluggerInterface $slugger,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Idempotently create/update categories and sources. Safe to run anytime. */
    public function seedCatalog(): void
    {
        foreach ($this->categoryDefs() as $slug => [$name, $color, $order]) {
            $category = $this->categoryRepo->findBySlug($slug) ?? new Category($name, $slug);
            $category->setName($name)->setColor($color)->setIcon(null)->setSortOrder($order);
            $this->em->persist($category);
            $this->categories[$slug] = $category;
        }

        foreach ($this->sourceDefs() as $key => [$name, $type, $url, $importer, $city, $enabled]) {
            $isNew = false;
            $source = $this->sourceRepo->findByKey($key);
            if ($source === null) {
                $source = new Source($key, $name, $type);
                $isNew = true;
            }
            $source->setName($name)->setType($type)->setUrl($url)->setImporter($importer);
            if ($city !== null) {
                $config = $source->getConfig();
                $config['city'] = $city;
                $source->setConfig($config);
            }
            // Don't override an operator's enable/disable choice on re-seed:
            if ($isNew) {
                $source->setEnabled($enabled);
            }
            $this->em->persist($source);
            $this->sources[$key] = $source;
        }

        $this->em->flush();
    }

    /** Create demo venues + events (throwaway content for the test run). */
    public function seedDemoEvents(): void
    {
        if (!$this->categories) {
            $this->seedCatalog();
        }
        $this->loadVenues();

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'));
        foreach ($this->demoEventDefs() as $i => [$title, $offset, $time, $durationDays, $catSlug, $venueName, $sourceKey, $price, $desc]) {
            $day = $now->modify('+'.$offset.' days');
            if ($time !== null) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $start = $day->setTime($h, $m);
                $end = $start->modify('+2 hours');
                $allDay = false;
            } else {
                $start = $day->setTime(0, 0);
                $end = $durationDays > 1 ? $start->modify('+'.($durationDays - 1).' days')->setTime(23, 59) : null;
                $allDay = true;
            }

            $event = new Event($title, $start, $this->sources[$sourceKey]);
            $event->setEndsAt($end)
                ->setAllDay($allDay)
                ->setDescription($desc)
                ->setCategory($this->categories[$catSlug])
                ->setVenue($venueName !== null ? $this->venues[$venueName] : null)
                ->setLocationText($venueName === null ? 'Innenstadt Gütersloh' : null)
                ->setPrice($price)
                ->setStatus(EventStatus::Published)
                ->setExternalId('demo-'.$i)
                ->setContentHash(sha1($title.$start->format('c')))
                ->setDedupKey(substr(mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $title)).'|'.$start->format('Y-m-d'), 0, 191))
                ->setSlug($start->format('Y-m-d').'-'.$this->slugger->slug(mb_substr($title, 0, 60))->lower())
                ->setFirstSeenAt($now)
                ->setLastSeenAt($now);
            $this->em->persist($event);
        }

        $this->em->flush();
    }

    private function loadVenues(): void
    {
        foreach ($this->venueDefs() as $name => [$street, $plz, $city]) {
            $venue = new Venue($name, $city);
            $venue->setStreet($street)->setPostalCode($plz);
            $this->em->persist($venue);
            $this->venues[$name] = $venue;
        }
    }

    /** @return array<string, array{0:string,1:string,2:int}> */
    private function categoryDefs(): array
    {
        $defs = [
            'musik' => ['Konzert & Musik', '#e11d48'],
            'party' => ['Party & Nightlife', '#7c3aed'],
            'buehne' => ['Bühne & Theater', '#d97706'],
            'kino' => ['Kino', '#9f1239'],
            'kunst' => ['Kunst & Ausstellung', '#0891b2'],
            'familie' => ['Familie & Kinder', '#16a34a'],
            'sport' => ['Sport', '#2563eb'],
            'markt' => ['Markt & Fest', '#db2777'],
            'genuss' => ['Essen & Genuss', '#ca8a04'],
            'bildung' => ['Bildung & Vortrag', '#475569'],
            'sonstiges' => ['Sonstiges', '#0a8da3'],
        ];
        $order = 0;
        $out = [];
        foreach ($defs as $slug => [$name, $color]) {
            $out[$slug] = [$name, $color, $order++];
        }

        return $out;
    }

    /**
     * Full source catalogue. enabled is the *initial* state used only when a
     * source is first created; operators may flip it later and re-seeds keep
     * their choice. We enable a source out of the box only when a working
     * importer exists for it (one of the built keys plus the generic "ics").
     *
     * @return array<string, array{0:string,1:SourceType,2:?string,3:?string,4:?string,5:bool}>
     */
    private function sourceDefs(): array
    {
        // Importer keys with a verified working implementation (+ generic ics).
        $ready = [
            'jsonld', 'rss', 'stadt_gt', 'auf_schluer', 'theater_gt', 'stadthalle_gt',
            'weberei', 'bambi_kino', 'wapelbad', 'erfolgskreis_gt', 'radio_gt', 'wilhalm',
            'kgb_langenberg', 'stadtbib_gt', 'vhs_gt', 'vhs_re', 'ics', 'anno_events',
            'dreiecksplatz', 'wolpertinger', 'flowl', 'marktcom', 'gtv1879', 'json',
            // Built in the 2026-05-31 importer-backlog batch:
            'stadt_rietberg', 'stadt_shs', 'stadt_verl', 'stadt_versmold', 'gem_steinhagen',
            'gem_langenberg', 'burg_ravensberg', 'owl_arena', 'glanzlichter', 'musikschule_gt',
            'bib_verl', 'bib_borgholzhausen', 'gartenschaupark', 'weberei_fv',
            'club_hangover',
        ];

        // [key => [name, type, url, importer, city]]
        $catalog = [
            ['stadt_gt', 'Stadt Gütersloh Veranstaltungskalender', SourceType::Html, 'https://www.guetersloh.de/de/veranstaltungen/?from=2026-05-29%2000:00:00&to=2026-06-30%2023:59:59', 'stadt_gt', 'Gütersloh'],
            ['auf_schluer', 'Auf Schlür / veranstaltungen-gt.de (GTM)', SourceType::Html, 'https://veranstaltungen-gt.de/times?type=what', 'auf_schluer', 'Gütersloh'],
            ['theater_gt', 'Theater Gütersloh', SourceType::Html, 'https://www.theater-gt.de/spielplan', 'theater_gt', 'Gütersloh'],
            ['stadthalle_gt', 'Stadthalle Gütersloh', SourceType::Html, 'https://www.stadthalle-gt.de/', 'stadthalle_gt', 'Gütersloh'],
            ['weberei', 'Die Weberei', SourceType::Html, 'https://www.die-weberei.de/', 'weberei', 'Gütersloh'],
            ['weberei_fv', 'Förderverein Die Weberei', SourceType::Html, 'https://weberei-foerderverein.de/termine/', 'weberei_fv', 'Gütersloh'],
            ['bambi_kino', 'Bambi & Löwenherz Kino', SourceType::Html, 'https://www.bambikino.de/programm/', 'bambi_kino', 'Gütersloh'],
            ['club_hangover', 'Club Hangover', SourceType::Html, 'https://www.clubhangover.de/events/', 'club_hangover', 'Gütersloh'],
            ['filmwerk_gt', 'Filmwerk Gütersloh', SourceType::Html, 'https://web.filmwerk-gt.de/', 'manual', 'Gütersloh'],
            ['stadtbib_gt', 'Stadtbibliothek Gütersloh', SourceType::Html, 'https://stadtbibliothek-guetersloh.easy2book.de/veranstaltungen/', 'stadtbib_gt', 'Gütersloh'],
            ['vhs_gt', 'VHS Gütersloh', SourceType::Html, 'https://www.vhs-gt.de/kurssuche/liste', 'vhs_gt', 'Gütersloh'],
            ['musikschule_gt', 'Musikschule für den Kreis Gütersloh', SourceType::Html, 'https://www.musikschule-guetersloh.de/veranstaltungen', 'musikschule_gt', 'Gütersloh'],
            ['wapelbad', 'Wapelbad', SourceType::Html, 'https://www.wapelbad.de/about-1', 'wapelbad', 'Gütersloh'],
            ['stadtmuseum_gt', 'Stadtmuseum Gütersloh', SourceType::Html, 'https://www.stadtmuseum-guetersloh.de/termine', 'manual', 'Gütersloh'],
            ['erfolgskreis_gt', 'Erfolgskreis GT (kreisweit/Tourismus)', SourceType::Html, 'https://www.erfolgskreis-gt.de/veranstaltungen/', 'erfolgskreis_gt', 'Kreis Gütersloh'],
            ['radio_gt', 'Radio Gütersloh Veranstaltungstipps', SourceType::Html, 'https://www.radioguetersloh.de/service/veranstaltungstipps/126484', 'radio_gt', 'Kreis Gütersloh'],
            ['anno_events', 'ANNO-EVENTS', SourceType::Html, 'https://anno-events.de/', 'anno_events', 'Gütersloh'],
            ['flora_westfalica', 'Flora Westfalica', SourceType::Rss, 'https://www.rheda-wiedenbrueck.de/terminerw/rss.xml', 'rss', 'Rheda-Wiedenbrück'],
            ['a2_forum', 'A2 Forum', SourceType::Html, 'https://a2-forum.de/?ical_download=902', 'ics', 'Rheda-Wiedenbrück'],
            ['kloster_wiedenbrueck', 'Kloster Wiedenbrück', SourceType::Html, 'https://kloster-wiedenbrueck.de/programm/', 'jsonld', 'Rheda-Wiedenbrück'],
            ['vhs_re', 'VHS Reckenberg-Ems', SourceType::Html, 'https://www.vhs-re.de/programm', 'vhs_re', 'Rheda-Wiedenbrück'],
            ['stadt_rietberg', 'Stadt Rietberg Veranstaltungskalender', SourceType::Html, 'https://www.rietberg.de/tourismus/freizeitangebote/veranstaltungen/uebersicht.html', 'stadt_rietberg', 'Rietberg'],
            ['gartenschaupark', 'Gartenschaupark Rietberg', SourceType::Html, 'https://www.gartenschaupark-rietberg.de/veranstaltungen/veranstaltungen-konzerte-feste-etc.html', 'gartenschaupark', 'Rietberg'],
            ['kulturig', 'kulturig e.V.', SourceType::Html, 'https://www.kulturig.de/events-tickets/eventkalender.html', 'jsonld', 'Rietberg'],
            ['stadtbib_rietberg', 'Stadtbibliothek Rietberg', SourceType::Html, 'https://www.rietberg.de/tourismus/freizeitangebote/veranstaltungen/veranstaltungsort/stadtbibliothek-rietberg-394.html', 'manual', 'Rietberg'],
            ['stadt_harsewinkel', 'Stadt Harsewinkel Veranstaltungskalender', SourceType::Ics, 'https://www.harsewinkel.de/veranstaltungen/veranstaltungen.ical?zeitauswahl=1&auswahl_woche_tage=730&onlyMonat_select=0&selected_kommune=34050', 'ics', 'Harsewinkel'],
            ['wilhalm', 'Kulturort Wilhalm', SourceType::Html, 'https://www.wilhalm.de/api/events.php', 'wilhalm', 'Harsewinkel'],
            ['stadt_shs', 'Stadt Schloß Holte-Stukenbrock Veranstaltungskalender', SourceType::Html, 'https://www.teutonavigator.de/de/schlossholtestukenbrock/wlan/portal', 'stadt_shs', 'Schloß Holte-Stukenbrock'],
            ['glanzlichter', 'GLANZLICHTER', SourceType::Html, 'https://glanzlichter-openair.de/', 'glanzlichter', 'Schloß Holte-Stukenbrock'],
            ['heimatverein_shs', 'Heimatverein SHS', SourceType::Rss, 'https://www.heimatverein-shs.de/feed/', 'rss', 'Schloß Holte-Stukenbrock'],
            ['stadt_verl', 'Stadt Verl', SourceType::Html, 'https://www.verl.de/freizeit-kultur/veranstaltungskalender.html', 'stadt_verl', 'Verl'],
            ['bib_verl', 'Bibliothek Verl', SourceType::Html, 'https://bibliothek.verl.de/de/aktuelles/index-vorlesetermine.php', 'bib_verl', 'Verl'],
            ['owl_arena', 'OWL ARENA', SourceType::Html, 'https://www.heristo-arena.nrw/tickets-events/', 'owl_arena', 'Halle (Westf.)'],
            ['stadtbuecherei_halle', 'Stadtbücherei Halle (Westf.)', SourceType::Html, 'https://open.stadtbuecherei-halle.de/Veranstaltungen', 'manual', 'Halle (Westf.)'],
            ['vhs_ravensberg', 'VHS Ravensberg', SourceType::Ics, 'https://www.vhs-ravensberg.de/kurs?tx_itemkgconnect_coursedetails%5Baction%5D=iCal&tx_itemkgconnect_coursedetails%5Bcontroller%5D=Course&tx_itemkgconnect_coursedetails%5Bcourse%5D=786-C-261-31045&cHash=17bb7e7b6f426e5949dae4732c586643', 'ics', 'Halle (Westf.)'],
            ['stadt_werther', 'Stadt Werther Veranstaltungen', SourceType::Html, 'https://www.stadt-werther.de/entdecken/veranstaltungskalender', 'manual', 'Werther (Westf.)'],
            ['stadtbib_werther', 'Stadtbibliothek Werther', SourceType::Html, 'https://werther.bibliotheca-open.de/', 'manual', 'Werther (Westf.)'],
            ['museum_pab', 'Museum Peter August Böckstiegel', SourceType::Html, 'https://www.museumpab.de/kunstvermittlung/veranstaltungen/', 'manual', 'Werther (Westf.)'],
            ['gem_steinhagen', 'Gemeinde Steinhagen', SourceType::Html, 'https://www.steinhagen-app.de/veranstaltungen', 'gem_steinhagen', 'Steinhagen'],
            ['bib_steinhagen', 'Gemeindebibliothek Steinhagen', SourceType::Html, 'https://steinhagen.bibliotheca-open.de/Veranstaltungen/Mach-mit', 'manual', 'Steinhagen'],
            ['burg_ravensberg', 'Burg Ravensberg', SourceType::Html, 'https://burg-ravensberg.de/veranstaltungskalender/', 'burg_ravensberg', 'Borgholzhausen'],
            ['bib_borgholzhausen', 'Bibliothek Borgholzhausen', SourceType::Html, 'https://meta.et4.de/rest.ashx/search/?experience=borgholzhausen&type=Event&template=ET2014A.json&q=city%3A%22borgholzhausen%22', 'bib_borgholzhausen', 'Borgholzhausen'],
            ['stadt_versmold', 'Stadt Versmold Veranstaltungskalender', SourceType::Html, 'https://www.versmold.de/de/veranstaltungen/', 'stadt_versmold', 'Versmold'],
            ['gem_herzebrock', 'Gemeinde Herzebrock-Clarholz Veranstaltungskalender', SourceType::Html, 'https://www.herzebrock-clarholz.de/veranstaltungskalender/', 'manual', 'Herzebrock-Clarholz'],
            ['gem_langenberg', 'Gemeinde Langenberg Veranstaltungskalender', SourceType::Ics, 'https://www.langenberg.de/startseite/kalender/event.ics?weekends=false&tagMode=ALL', 'gem_langenberg', 'Langenberg'],
            ['langenberg_app', 'Langenberg App', SourceType::Ics, 'https://www.langenberg-app.de/frontend-event/exporticalendar/{eventId}/{timestamp}', 'ics', 'Langenberg'],
            ['kgb_langenberg', 'KGB Langenberg', SourceType::Rss, 'https://kgb-langenberg.de/feed/', 'kgb_langenberg', 'Langenberg'],
            ['dreiecksplatz', 'Dreiecksplatz Gütersloh – Freitag 18', SourceType::Html, 'https://www.dreiecksplatz-gt.de/events/freitag-18/2026/', 'dreiecksplatz', 'Gütersloh'],
            ['wolpertinger', 'Wolpertinger – Der Spieleladen', SourceType::Html, 'https://wolpertinger-der-spieleladen.de/', 'wolpertinger', 'Gütersloh'],
            ['flowl', 'flowl Flohmarktkalender (Kreis GT)', SourceType::Json, 'https://flowl.de/wp-json/tribe/events/v1/events?per_page=50&page=1&search=Gütersloh', 'flowl', 'Kreis Gütersloh'],
            ['marktcom', 'marktcom Marktverzeichnis (Kreis GT)', SourceType::Html, 'https://www.marktcom.de/termine/verzeichnis?q[event_bundesland_matches]=Nordrhein-Westfalen&q[event_landkreis_matches]=Gütersloh', 'marktcom', 'Kreis Gütersloh'],
            ['gtv1879', 'GTV 1879 – Termine', SourceType::Html, 'https://gtv1879.de/termine/', 'gtv1879', 'Gütersloh'],
        ];

        $out = [];
        foreach ($catalog as [$key, $name, $type, $url, $importer, $city]) {
            $out[$key] = [$name, $type, $url, $importer, $city, \in_array($importer, $ready, true)];
        }

        // Throwaway demo source for the optional demo-event batch.
        $out['demo'] = ['Dalketicker (Demo-Daten)', SourceType::Manual, null, null, null, true];

        // Legacy demo-event sources kept (disabled) so seedDemoEvents() still works.
        $demoSources = [
            'veranstaltungen_gt' => ['veranstaltungen-gt.de (Demo)', SourceType::Html, 'https://veranstaltungen-gt.de/'],
            'gtv1879' => ['GTV 1879 – Termine (Demo)', SourceType::Html, 'https://gtv1879.de/termine/'],
            'spexard' => ['Spexard – Termine (Demo)', SourceType::Pdf, 'https://www.spexard.de/termine'],
            'wolpertinger' => ['Wolpertinger – Der Spieleladen (Demo)', SourceType::Html, 'https://wolpertinger-der-spieleladen.de/'],
            'stadtbibliothek' => ['Stadtbibliothek Gütersloh (Demo)', SourceType::Html, null],
        ];
        foreach ($demoSources as $key => [$name, $type, $url]) {
            if (!isset($out[$key])) {
                $out[$key] = [$name, $type, $url, 'manual', 'Gütersloh', false];
            }
        }

        return $out;
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    private function venueDefs(): array
    {
        return [
            'Stadthalle Gütersloh' => ['Friedrichstr. 10', '33330', 'Gütersloh'],
            'Wapelbad' => ['Verler Str. 215', '33334', 'Gütersloh'],
            'Die Weberei' => ['Bogenstr. 1-8', '33330', 'Gütersloh'],
            'Theater Gütersloh' => ['Barkeystr. 17', '33330', 'Gütersloh'],
            'Bauernhaus Spexard' => ['Lukasstr. 1', '33334', 'Gütersloh'],
            'GTV 1879 – Sportpark' => ['Wiesenstr. 21', '33330', 'Gütersloh'],
            'Stadtbibliothek Gütersloh' => ['Blessenstätte 1', '33330', 'Gütersloh'],
            'Reethus Rietberg' => ['Rathausstr. 32', '33397', 'Rietberg'],
            'Stadthalle Rheda-Wiedenbrück' => ['Doktorplatz 1', '33378', 'Rheda-Wiedenbrück'],
            'Wolpertinger – Der Spieleladen' => ['Berliner Str. 25', '33330', 'Gütersloh'],
        ];
    }

    /** @return list<array{0:string,1:int,2:?string,3:int,4:string,5:?string,6:string,7:?string,8:string}> */
    private function demoEventDefs(): array
    {
        return [
            ['Dalkemann – GTV Triathlon', 2, '09:00', 1, 'sport', 'GTV 1879 – Sportpark', 'gtv1879', 'kostenlos für Zuschauer', 'Der traditionelle Dalkemann-Triathlon des GTV 1879. Schwimmen, Radfahren, Laufen rund um den Sportpark.'],
            ['Kinderdisko', 1, '15:00', 1, 'familie', 'Wapelbad', 'wapelbad', 'Eintritt frei', 'Kinderdisko am Wapelbad – Musik, Tanz und gute Laune für die Kleinen.'],
            ['101 Jahre Wapelbad – Frühstück Open Air', 2, null, 1, 'markt', 'Wapelbad', 'wapelbad', 'Anmeldung erforderlich', 'Open-Air-Frühstück zum 101-jährigen Jubiläum des Wapelbads.'],
            ['Rittermarkt Kruse', 9, null, 2, 'markt', null, 'anno_events', 'Tageskasse', 'Mittelalterlicher Rittermarkt mit Handwerk, Musik und Lagerleben.'],
            ['200 Jahre Stadthalle – Festabend', 5, '19:30', 1, 'buehne', 'Stadthalle Gütersloh', 'stadt_gt', 'ab 24 €', 'Großer Festabend zum 200-jährigen Jubiläum der Stadthalle.'],
            ['Jazz im Hof', 4, '20:00', 1, 'musik', 'Die Weberei', 'veranstaltungen_gt', '12 €', 'Lauer Sommerabend mit Live-Jazz im Hof der Weberei.'],
            ['Spieleabend für alle', 3, '18:00', 1, 'familie', 'Wolpertinger – Der Spieleladen', 'wolpertinger', 'Eintritt frei', 'Offener Brettspielabend im Wolpertinger – Spiele werden gestellt.'],
            ['Bilderbuchkino für Kita-Kinder', 6, '10:00', 1, 'familie', 'Stadtbibliothek Gütersloh', 'stadtbibliothek', 'kostenlos', 'Vorlesen und gemeinsames Entdecken in der Stadtbibliothek.'],
            ['Sommerkonzert Blasorchester', 7, '17:00', 1, 'musik', 'Bauernhaus Spexard', 'spexard', 'Spende erbeten', 'Das Blasorchester Spexard lädt zum Sommerkonzert am Bauernhaus.'],
            ['Premiere: Sommernachtstraum', 8, '19:30', 1, 'buehne', 'Theater Gütersloh', 'stadt_gt', 'ab 18 €', 'Shakespeare-Klassiker in einer neuen Inszenierung.'],
            ['Feierabendmarkt', 5, '16:00', 1, 'genuss', 'Die Weberei', 'veranstaltungen_gt', 'Eintritt frei', 'Regionale Stände, Streetfood und Getränke zum Wochenausklang.'],
            ['Vortrag: KI im Alltag', 10, '19:00', 1, 'bildung', 'Stadtbibliothek Gütersloh', 'stadtbibliothek', 'kostenlos', 'Was künstliche Intelligenz heute schon kann – verständlich erklärt.'],
            ['Flohmarkt Rietberg', 12, null, 1, 'markt', 'Reethus Rietberg', 'anno_events', null, 'Großer Trödel- und Flohmarkt in der Innenstadt.'],
            ['Tanz in den Abend', 11, '20:30', 1, 'party', 'Stadthalle Rheda-Wiedenbrück', 'veranstaltungen_gt', '8 €', 'Tanzabend mit Live-Band für jede Generation.'],
            ['Ausstellungseröffnung: Lichträume', 6, '18:30', 1, 'kunst', 'Theater Gütersloh', 'stadt_gt', 'Eintritt frei', 'Vernissage einer Lichtinstallation lokaler Künstler:innen.'],
            ['Familiensonntag im Wapelbad', 16, null, 1, 'familie', 'Wapelbad', 'wapelbad', 'Badeintritt', 'Spiel, Spaß und Aktionen für die ganze Familie.'],
            ['Heimspiel GTV 1879', 14, '15:00', 1, 'sport', 'GTV 1879 – Sportpark', 'gtv1879', 'Tageskasse', 'Punktspiel der ersten Mannschaft im heimischen Sportpark.'],
            ['Late Night Brettspiele', 17, '20:00', 1, 'familie', 'Wolpertinger – Der Spieleladen', 'wolpertinger', '3 €', 'Bis spät in die Nacht zocken – Neuheiten zum Antesten.'],
            ['Open-Air-Kino', 20, '21:30', 1, 'buehne', 'Die Weberei', 'veranstaltungen_gt', '9 €', 'Sommerkino unter freiem Himmel.'],
            ['Stadtführung: Geheimnisse der Dalke', 13, '14:00', 1, 'bildung', null, 'stadt_gt', '6 €', 'Geführter Spaziergang entlang der Dalke mit Geschichten und Anekdoten.'],
        ];
    }
}
