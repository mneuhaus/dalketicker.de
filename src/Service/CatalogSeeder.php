<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Region;
use App\Entity\Source;
use App\Entity\Venue;
use App\Enum\EventStatus;
use App\Enum\SourceType;
use App\Repository\CategoryRepository;
use App\Repository\EventRepository;
use App\Repository\RegionRepository;
use App\Repository\SourceRepository;
use App\Repository\VenueRepository;
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
    /**
     * Aggregator/competitor feeds that re-bundle other people's events. For
     * these we only show facts (title/date/place/price) + a link, never their
     * description text or images. {@see Source::isFactsOnly()}.
     */
    private const FACTS_ONLY_SOURCES = [
        'auf_schluer', 'radio_gt', 'erfolgskreis_gt', 'marktcom', 'flowl',
        'paderborner_land_events', 'teutoburgerwald_events', 'teutoburgerwald_minden_luebbecke_events', 'westliches_weserbergland_events',
        'owl_live_paderborn', 'nw_kreis_paderborn_events',
        'ewu_bund_reitsport', 'ewu_bund_reitsport_paderborn',
        'ewu_bund_reitsport_minden_luebbecke', 'ewu_bund_reitsport_bielefeld',
    ];

    /** @var array<string, Category> */
    private array $categories = [];
    /** @var array<string, Venue> */
    private array $venues = [];
    /** @var array<string, Region> */
    private array $regions = [];
    /** @var array<string, Source> */
    private array $sources = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categoryRepo,
        private readonly RegionRepository $regionRepo,
        private readonly SourceRepository $sourceRepo,
        private readonly VenueRepository $venueRepo,
        private readonly EventRepository $eventRepo,
        private readonly SluggerInterface $slugger,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Idempotently create/update categories and sources. Safe to run anytime. */
    public function seedCatalog(): void
    {
        foreach ($this->regionDefs() as $key => $def) {
            $region = $this->regionRepo->findByKey($key) ?? new Region($key, $def['siteName'], $def['areaName'], $def['canonicalHost']);
            $region
                ->setSiteName($def['siteName'])
                ->setAreaName($def['areaName'])
                ->setTagline($def['tagline'])
                ->setCanonicalHost($def['canonicalHost'])
                ->setHostAliases($def['hostAliases'])
                ->setThemeColor($def['themeColor'])
                ->setLogoLetter($def['logoLetter'])
                ->setDefaultCity($def['defaultCity'])
                ->setCities($def['cities'])
                ->setCityAliases($def['cityAliases'])
                ->setEnabled($def['enabled']);
            $this->em->persist($region);
            $this->regions[$key] = $region;
        }

        foreach ($this->categoryDefs() as $slug => [$name, $color, $order]) {
            $category = $this->categoryRepo->findBySlug($slug) ?? new Category($name, $slug);
            $category->setName($name)->setColor($color)->setIcon(null)->setSortOrder($order);
            $this->em->persist($category);
            $this->categories[$slug] = $category;
        }

        foreach ($this->sourceDefs() as $key => $def) {
            [$regionKey, $name, $type, $url, $importer, $city, $enabled] = array_slice($def, 0, 7);
            $sourceConfig = \is_array($def[7] ?? null) ? $def[7] : [];
            $region = $this->regions[$regionKey] ?? $this->regionRepo->findByKey($regionKey);
            if ($region === null) {
                throw new \RuntimeException(sprintf('Region "%s" for source "%s" is not configured.', $regionKey, $key));
            }
            $isNew = false;
            $source = $this->sourceRepo->findByKey($key, $region);
            if ($source === null) {
                $source = new Source($key, $name, $type, $region);
                $isNew = true;
            }
            $source->setRegion($region)->setName($name)->setType($type)->setUrl($url)->setImporter($importer);
            if ($sourceConfig !== []) {
                $source->setConfig(array_merge($source->getConfig(), $sourceConfig));
            }
            if ($city !== null) {
                $config = $source->getConfig();
                $config['city'] = $city;
                $source->setConfig($config);
            }
            // Don't override an operator's choices on re-seed:
            if ($isNew) {
                $source->setEnabled($enabled);
            }
            // Aggregators that bundle third-party events -> facts only (no
            // foreign description text/images), per the legal safeguard.
            if ($isNew || \in_array($key, self::FACTS_ONLY_SOURCES, true)) {
                $source->setFactsOnly(\in_array($key, self::FACTS_ONLY_SOURCES, true));
            }
            $this->em->persist($source);
            $this->sources[$key] = $source;
        }

        $this->em->flush();
    }

    /** Create demo venues + events (throwaway content for the test run). Idempotent. */
    public function seedDemoEvents(): void
    {
        if (!$this->categories) {
            $this->seedCatalog();
        }
        $this->loadVenues();

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'));
        foreach ($this->demoEventDefs() as $i => [$title, $offset, $time, $durationDays, $catSlug, $venueName, $sourceKey, $price, $desc]) {
            // Re-runs must not violate uniq_event_source_external — keep the
            // previously seeded demo event untouched.
            if ($this->eventRepo->findOneBy(['source' => $this->sources[$sourceKey], 'externalId' => 'demo-'.$i]) !== null) {
                continue;
            }
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
        $region = $this->regions['guetersloh'] ?? $this->regionRepo->findByKey('guetersloh');
        if ($region === null) {
            throw new \RuntimeException('Region "guetersloh" is not configured.');
        }
        foreach ($this->venueDefs() as $name => [$street, $plz, $city]) {
            // Real imports (or an earlier --demo run) may already have created
            // the venue — re-persisting would violate uniq_venue_region_dedup.
            $venue = $this->venueRepo->findByDedupKey(Venue::buildDedupKey($name, $city), $region)
                ?? new Venue($name, $city, $region);
            $venue->setStreet($street)->setPostalCode($plz);
            $this->em->persist($venue);
            $this->venues[$name] = $venue;
        }
    }

    /**
     * @return array<string, array{
     *     siteName:string,
     *     areaName:string,
     *     tagline:string,
     *     canonicalHost:string,
     *     hostAliases:list<string>,
     *     themeColor:string,
     *     logoLetter:string,
     *     defaultCity:string,
     *     cities:list<string>,
     *     cityAliases:array<string,string>,
     *     enabled:bool
     * }>
     */
    private function regionDefs(): array
    {
        return [
            'guetersloh' => [
                'siteName' => 'dalketicker',
                'areaName' => 'Kreis Gütersloh',
                'tagline' => 'Was läuft im Kreis Gütersloh',
                'canonicalHost' => 'dalketicker.de',
                'hostAliases' => ['www.dalketicker.de', 'dalketicker.neuhaus.nrw', 'dalketicker.traefik.me'],
                'themeColor' => '#0a8da3',
                'logoLetter' => 'd',
                'defaultCity' => 'Kreis Gütersloh',
                'cities' => [
                    'Gütersloh', 'Rheda-Wiedenbrück', 'Rietberg', 'Harsewinkel', 'Schloß Holte-Stukenbrock',
                    'Verl', 'Halle (Westf.)', 'Steinhagen', 'Borgholzhausen', 'Werther (Westf.)',
                    'Langenberg', 'Versmold', 'Herzebrock-Clarholz', 'Kreis Gütersloh',
                ],
                'cityAliases' => [
                    'guetersloh' => 'Gütersloh', 'gutersloh' => 'Gütersloh',
                    'isselhorst' => 'Gütersloh', 'avenwedde' => 'Gütersloh', 'spexard' => 'Gütersloh',
                    'friedrichsdorf' => 'Gütersloh', 'blankenhagen' => 'Gütersloh', 'niehorst' => 'Gütersloh',
                    'hollen' => 'Gütersloh', 'ebbesloh' => 'Gütersloh', 'kattenstroth' => 'Gütersloh', 'pavenstaedt' => 'Gütersloh',
                    'rhedawiedenbrueck' => 'Rheda-Wiedenbrück', 'rhedawiedenbruck' => 'Rheda-Wiedenbrück',
                    'batenhorst' => 'Rheda-Wiedenbrück', 'lintel' => 'Rheda-Wiedenbrück',
                    'stvit' => 'Rheda-Wiedenbrück', 'sanktvit' => 'Rheda-Wiedenbrück', 'nordrheda' => 'Rheda-Wiedenbrück',
                    'rietberg' => 'Rietberg', 'druffel' => 'Rietberg', 'varensell' => 'Rietberg', 'mastholte' => 'Rietberg',
                    'neuenkirchen' => 'Rietberg', 'westerwiehe' => 'Rietberg',
                    'verl' => 'Verl', 'suerenheide' => 'Verl', 'kaunitz' => 'Verl',
                    'harsewinkel' => 'Harsewinkel', 'marienfeld' => 'Harsewinkel', 'greffen' => 'Harsewinkel',
                    'hallewestf' => 'Halle (Westf.)', 'hallewestfalen' => 'Halle (Westf.)',
                    'hoerste' => 'Halle (Westf.)', 'kuensebeck' => 'Halle (Westf.)', 'koelkebeck' => 'Halle (Westf.)',
                    'steinhagen' => 'Steinhagen', 'brockhagen' => 'Steinhagen', 'amshausen' => 'Steinhagen',
                    'borgholzhausen' => 'Borgholzhausen', 'westbarthausen' => 'Borgholzhausen', 'cleve' => 'Borgholzhausen',
                    'schlossholtestukenbrock' => 'Schloß Holte-Stukenbrock', 'schlossholte' => 'Schloß Holte-Stukenbrock',
                    'stukenbrock' => 'Schloß Holte-Stukenbrock', 'sende' => 'Schloß Holte-Stukenbrock', 'liemke' => 'Schloß Holte-Stukenbrock',
                    'langenberg' => 'Langenberg', 'benteler' => 'Langenberg',
                    'versmold' => 'Versmold', 'bockhorst' => 'Versmold', 'peckeloh' => 'Versmold', 'oesterweg' => 'Versmold',
                    'hesselteich' => 'Versmold', 'loxten' => 'Versmold',
                    'herzebrockclarholz' => 'Herzebrock-Clarholz', 'clarholz' => 'Herzebrock-Clarholz',
                    'kreisguetersloh' => 'Kreis Gütersloh', 'kreisgutersloh' => 'Kreis Gütersloh',
                ],
                'enabled' => true,
            ],
            'paderborn' => [
                'siteName' => 'paderticker',
                'areaName' => 'Kreis Paderborn',
                'tagline' => 'Was läuft im Kreis Paderborn',
                'canonicalHost' => 'paderticker.neuhaus.nrw',
                'hostAliases' => ['paderticker.de', 'www.paderticker.de', 'paderticker.traefik.me'],
                'themeColor' => '#1d7f64',
                'logoLetter' => 'p',
                'defaultCity' => 'Kreis Paderborn',
                'cities' => ['Paderborn', 'Altenbeken', 'Bad Lippspringe', 'Bad Wünnenberg', 'Borchen', 'Büren', 'Delbrück', 'Hövelhof', 'Lichtenau', 'Salzkotten', 'Kreis Paderborn'],
                'cityAliases' => [
                    'paderborn' => 'Paderborn', 'altenbeken' => 'Altenbeken', 'badlippspringe' => 'Bad Lippspringe',
                    'badwuennenberg' => 'Bad Wünnenberg', 'badwunnenberg' => 'Bad Wünnenberg',
                    'borchen' => 'Borchen', 'bueren' => 'Büren', 'buren' => 'Büren', 'delbrueck' => 'Delbrück',
                    'delbruck' => 'Delbrück', 'hoevelhof' => 'Hövelhof', 'hovelhof' => 'Hövelhof',
                    'lichtenau' => 'Lichtenau', 'salzkotten' => 'Salzkotten', 'kreispaderborn' => 'Kreis Paderborn',
                ],
                'enabled' => true,
            ],
            'minden-luebbecke' => [
                'siteName' => 'weserticker',
                'areaName' => 'Kreis Minden-Lübbecke',
                'tagline' => 'Was läuft im Kreis Minden-Lübbecke',
                'canonicalHost' => 'weserticker.neuhaus.nrw',
                'hostAliases' => ['weserticker.de', 'www.weserticker.de', 'weserticker.traefik.me'],
                'themeColor' => '#527d08',
                'logoLetter' => 'w',
                'defaultCity' => 'Kreis Minden-Lübbecke',
                'cities' => ['Minden', 'Bad Oeynhausen', 'Espelkamp', 'Hille', 'Hüllhorst', 'Lübbecke', 'Petershagen', 'Porta Westfalica', 'Preußisch Oldendorf', 'Rahden', 'Stemwede', 'Kreis Minden-Lübbecke'],
                'cityAliases' => [
                    'minden' => 'Minden', 'badoeynhausen' => 'Bad Oeynhausen', 'espelkamp' => 'Espelkamp',
                    'hille' => 'Hille', 'huellhorst' => 'Hüllhorst', 'hullhorst' => 'Hüllhorst',
                    'luebbecke' => 'Lübbecke', 'lubbecke' => 'Lübbecke', 'petershagen' => 'Petershagen',
                    'portawestfalica' => 'Porta Westfalica', 'preussischoldendorf' => 'Preußisch Oldendorf',
                    'preusischoldendorf' => 'Preußisch Oldendorf', 'rahden' => 'Rahden', 'stemwede' => 'Stemwede',
                    'kreismindenluebbecke' => 'Kreis Minden-Lübbecke', 'kreismindenlubbecke' => 'Kreis Minden-Lübbecke',
                ],
                'enabled' => true,
            ],
            'bielefeld' => [
                'siteName' => 'sparrenticker',
                'areaName' => 'Bielefeld',
                'tagline' => 'Was läuft in Bielefeld',
                'canonicalHost' => 'sparrenticker.de',
                'hostAliases' => ['www.sparrenticker.de', 'sparrenticker.neuhaus.nrw', 'sparrenticker.traefik.me'],
                'themeColor' => '#e30014',
                'logoLetter' => 's',
                'defaultCity' => 'Bielefeld',
                'cities' => ['Bielefeld'],
                'cityAliases' => [
                    'bielefeld' => 'Bielefeld', 'brackwede' => 'Bielefeld', 'senne' => 'Bielefeld',
                    'sennestadt' => 'Bielefeld', 'dornberg' => 'Bielefeld', 'heepen' => 'Bielefeld',
                    'joellenbeck' => 'Bielefeld', 'jollenbeck' => 'Bielefeld', 'schildesche' => 'Bielefeld',
                    'stieghorst' => 'Bielefeld', 'gadderbaum' => 'Bielefeld',
                ],
                'enabled' => true,
            ],
        ];
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
     * @return array<string, array{0:string,1:string,2:SourceType,3:?string,4:?string,5:?string,6:bool,7?:array<string,mixed>}>
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
            'club_hangover', 'kulturig', 'tribe_events', 'gt_isselhorst', 'sv_pavenstaedt',
            'kommunal_events', 'hnf', 'ewu_bund', 'paderhalle', 'kloster_dalheim',
            'bielefeld_jetzt', 'ikiss_modid11', 'museum_pab', 'sitepark_teasers',
            'stadtbib_rietberg',
        ];
        $disabledInitially = [
            // Site currently returns 403 to the importer user agent; keep the
            // source visible in the catalogue, but don't run it automatically.
            'bad_wuennenberg_veranstaltungen',
            // The research signature looked like The Events Calendar, but the
            // public Tribe REST route currently returns 404.
            'bad_holzhausen_tribe',
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
            ['crossnight', 'Crossnight Gütersloh e.V.', SourceType::Html, 'https://crossnight.de/events/', 'tribe_events', 'Gütersloh'],
            ['stadtschuetzen', 'Gütersloher Schützengesellschaft von 1832', SourceType::Html, 'https://www.stadtschuetzen.de/events/', 'tribe_events', 'Gütersloh'],
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
            ['kulturig', 'kulturig e.V.', SourceType::Html, 'https://www.kulturig.de/events-tickets/eventkalender.html', 'kulturig', 'Rietberg'],
            ['stadtbib_rietberg', 'Stadtbibliothek Rietberg', SourceType::Html, 'https://www.rietberg.de/tourismus/freizeitangebote/veranstaltungen/veranstaltungsort/stadtbibliothek-rietberg-394.html', 'stadtbib_rietberg', 'Rietberg'],
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
            ['museum_pab', 'Museum Peter August Böckstiegel', SourceType::Html, 'https://www.museumpab.de/kunstvermittlung/veranstaltungen/', 'museum_pab', 'Werther (Westf.)'],
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
            ['gt_isselhorst', 'Isselhorster Werbegemeinschaft', SourceType::Html, 'http://www.gt-isselhorst.de/veranstaltungen/', 'gt_isselhorst', 'Gütersloh'],
            ['sv_pavenstaedt', 'SV Pavenstädt – Termine', SourceType::Html, 'https://www.xn--sv-pavenstdt-pcb.de/index.php?option=com_content&view=article&id=147&Itemid=118', 'sv_pavenstaedt', 'Gütersloh'],
        ];

        $out = [];
        foreach ($catalog as [$key, $name, $type, $url, $importer, $city]) {
            $out[$key] = ['guetersloh', $name, $type, $url, $importer, $city, \in_array($importer, $ready, true)];
        }

        // [key => [name, type, url, importer, city, optional config]]
        $paderbornCatalog = [
            ['paderborner_land_events', 'Paderborner Land Veranstaltungskalender', SourceType::Html, 'https://www.paderborner-land.de/deu/veranstaltungen/', 'erfolgskreis_gt', 'Kreis Paderborn', [
                'experience' => 'paderborner-land',
                'bootstrapUrl' => 'https://pages.destination.one/de/paderborner-land/default/search/Event/mode:next_months,12/sort:chronological',
                'maxItems' => 1200,
            ]],
            ['teutoburgerwald_events', 'Teutoburger Wald Veranstaltungskalender OWL', SourceType::Html, 'https://www.teutoburgerwald.de/region/gastro-event/veranstaltungskalender', 'erfolgskreis_gt', 'Kreis Paderborn', [
                'experience' => 'teutoburgerwald',
                'bootstrapUrl' => 'https://pages.destination.one/de/teutoburgerwald/default/search/Event/mode:next_months,12/sort:chronological',
                'allowedCities' => ['Paderborn', 'Altenbeken', 'Bad Lippspringe', 'Bad Wünnenberg', 'Borchen', 'Büren', 'Delbrück', 'Hövelhof', 'Lichtenau', 'Salzkotten'],
                'postalPrefixes' => ['3309', '3310', '3312', '3314', '3315', '3316', '3317', '3318'],
                'maxItems' => 1200,
            ]],
            ['vhs_vor_ort', 'VHS vor Ort', SourceType::Html, 'https://www.vhs-vor-ort.de/kurssuche/liste', 'vhs_re', 'Kreis Paderborn'],
            ['vhs_paderborn_webbasys', 'VHS Paderborn Kurssystem', SourceType::Html, 'https://vhskurse.paderborn.de/webbasys/index.php', 'vhs_re', 'Kreis Paderborn', [
                'category' => 'bildung',
                'maxPages' => 6,
            ]],
            ['kulturtipp_spl', 'KulturTipp Südliches Paderborner Land', SourceType::Pdf, 'https://www.leader-spl.eu/images/KulturTipp%20FS_25_26_03.pdf', 'manual', 'Kreis Paderborn'],
            ['altenbeken_veranstaltungen', 'Gemeinde Altenbeken Veranstaltungskalender', SourceType::Html, 'https://www.altenbeken.de/de/veranstaltungen/', 'kommunal_events', 'Altenbeken'],
            ['bad_lippspringe_veranstaltungen', 'Stadt Bad Lippspringe Veranstaltungen', SourceType::Html, 'https://www.bad-lippspringe.de/bali/veranstaltungen/', 'kommunal_events', 'Bad Lippspringe'],
            ['bad_wuennenberg_veranstaltungen', 'Stadt Bad Wünnenberg Veranstaltungen', SourceType::Html, 'https://www.bad-wuennenberg.de/de/veranstaltungen/', 'kommunal_events', 'Bad Wünnenberg'],
            ['borchen_veranstaltungen', 'Gemeinde Borchen Veranstaltungen', SourceType::Html, 'https://www.borchen.de/de/veranstaltungen/', 'kommunal_events', 'Borchen'],
            ['bueren_veranstaltungen', 'Stadt Büren Veranstaltungen', SourceType::Html, 'https://www.bueren.de/de/veranstaltungen/', 'kommunal_events', 'Büren'],
            ['delbrueck_veranstaltungen', 'Stadt Delbrück Veranstaltungskalender', SourceType::Html, 'https://www.stadt-delbrueck.de/de/aktuelles/veranstaltungen.php?navid=641030641030', 'kommunal_events', 'Delbrück'],
            ['hoevelhof_veranstaltungen', 'Sennegemeinde Hövelhof Veranstaltungskalender', SourceType::Html, 'https://www.hoevelhof.de/de/veranstaltungen/', 'kommunal_events', 'Hövelhof'],
            ['lichtenau_veranstaltungen', 'Stadt Lichtenau Veranstaltungen', SourceType::Html, 'https://www.lichtenau.de/de/veranstaltungen/', 'kommunal_events', 'Lichtenau'],
            ['paderborn_veranstaltungskalender', 'Stadt Paderborn Veranstaltungskalender', SourceType::Html, 'https://www.paderborn.de/tourismus-kultur/veranstaltungen/veranstaltungskalender.php', 'sitepark_teasers', 'Paderborn', [
                'maxPages' => 40,
                'maxEvents' => 500,
            ]],
            ['salzkotten_veranstaltungen', 'Stadt Salzkotten Veranstaltungen', SourceType::Html, 'https://www.salzkotten.de/de/veranstaltungen/', 'kommunal_events', 'Salzkotten'],
            ['stadtbibliothek_paderborn_events', 'Stadtbibliothek Paderborn', SourceType::Html, 'https://www.paderborn.de/veranstaltungsorte/109010100000089642.php', 'sitepark_teasers', 'Paderborn', [
                'venue' => 'Stadtbibliothek Paderborn',
                'maxPages' => 10,
                'maxEvents' => 120,
            ]],
            ['musikschule_paderborn_events', 'Städtische Musikschule Paderborn', SourceType::Html, 'https://www.paderborn.de/microsite/musikschule/unterricht/Veranstaltungen.php', 'manual', 'Paderborn'],
            ['stadthalle_delbrueck', 'Stadthalle Delbrück', SourceType::Html, 'https://www.stadthalle-delbrueck.de/de/events-erleben/programm/veranstaltungen.php', 'kommunal_events', 'Delbrück'],
            ['delbrueck_kauft_lokal', 'Delbrücker Marketinggemeinschaft', SourceType::Html, 'https://www.delbrueckkauftlokal.de/', 'manual', 'Delbrück'],
            ['verkehrsverein_hoevelhof', 'Verkehrsverein Hövelhof', SourceType::Html, 'https://www.hoevelhof.de/de/tourismus/hoevelhof-feiert.php', 'manual', 'Hövelhof'],
            ['salzkotten_marketing', 'Salzkotten Marketing e.V.', SourceType::Html, 'https://www.salzkotten-marketing.de/', 'manual', 'Salzkotten'],
            ['wewelsburg_veranstaltungen', 'Kreismuseum Wewelsburg', SourceType::Html, 'https://www.wewelsburg.de/de/aktuelles/veranstaltungen.php', 'kommunal_events', 'Büren'],
            ['kloster_dalheim_veranstaltungen', 'Stiftung Kloster Dalheim', SourceType::Html, 'https://www.stiftung-kloster-dalheim.lwl.org/de/veranstaltungen/veranstaltungskalender/', 'kloster_dalheim', 'Lichtenau', [
                'fetchDetails' => true,
            ]],
            ['paderhalle_events', 'PaderHalle', SourceType::Html, 'https://www.paderhalle.de/veranstaltungen/', 'paderhalle', 'Paderborn', [
                'dataUrl' => 'https://www.paderhalle.de/data/events.json',
            ]],
            ['theater_paderborn_kalender', 'Theater Paderborn', SourceType::Html, 'https://www.theater-paderborn.de/kalender-und-karten', 'manual', 'Paderborn'],
            ['hnf_veranstaltungen', 'Heinz Nixdorf MuseumsForum', SourceType::Html, 'https://www.hnf.de/veranstaltungen.html', 'hnf', 'Paderborn'],
            ['schuetzenhof_paderborn_programm', 'Schützenhof Paderborn', SourceType::Html, 'https://www.schuetzenhof.de/programm/', 'manual', 'Paderborn'],
            ['owl_live_paderborn', 'OWL live – Kreis Paderborn', SourceType::Html, 'https://www.owl-live.de/paderborn', 'manual', 'Kreis Paderborn'],
            ['nw_kreis_paderborn_events', 'Neue Westfälische – Veranstaltungen im Kreis Paderborn', SourceType::Html, 'https://www.nw.de/themen/lokal/kreis_paderborn/veranstaltungen-im-kreis-paderborn', 'manual', 'Kreis Paderborn'],
        ];
        foreach ($paderbornCatalog as $entry) {
            [$key, $name, $type, $url, $importer, $city] = array_slice($entry, 0, 6);
            $config = \is_array($entry[6] ?? null) ? $entry[6] : [];
            $out[$key] = [
                'paderborn',
                $name,
                $type,
                $url,
                $importer,
                $city,
                \in_array($importer, $ready, true) && !\in_array($key, $disabledInitially, true),
                $config,
            ];
        }

        $mindenLuebbeckePostalPrefixes = ['32312', '32339', '32351', '32361', '32369', '32423', '32425', '32427', '32429', '32457', '32469', '32479', '32545', '32547', '32549', '32609'];
        $mindenLuebbeckeCities = ['Minden', 'Bad Oeynhausen', 'Espelkamp', 'Hille', 'Hüllhorst', 'Lübbecke', 'Petershagen', 'Porta Westfalica', 'Preußisch Oldendorf', 'Rahden', 'Stemwede'];
        $mindenLuebbeckeCatalog = [
            ['muehlenkreis_events', 'Portal Minden-Lübbecke / Mühlenkreis Veranstaltungen', SourceType::Html, 'https://www.muehlenkreis.de/Erleben-Entdecken/Erkunden/Veranstaltungen/index.php?La=1&ModID=11&NavID=3147.45&catsum=1&k_sub=1&kat=2832.78.1&object=tx%2C1891.869.1', 'ikiss_modid11', 'Kreis Minden-Lübbecke', [
                'maxEvents' => 180,
            ]],
            ['teutoburgerwald_minden_luebbecke_events', 'Teutoburger Wald Veranstaltungskalender – Kreis Minden-Lübbecke', SourceType::Html, 'https://www.teutoburgerwald.de/region/gastro-event/veranstaltungskalender', 'erfolgskreis_gt', 'Kreis Minden-Lübbecke', [
                'experience' => 'teutoburgerwald',
                'bootstrapUrl' => 'https://pages.destination.one/de/teutoburgerwald/default/search/Event/mode:next_months,12/sort:chronological',
                'allowedCities' => $mindenLuebbeckeCities,
                'postalPrefixes' => $mindenLuebbeckePostalPrefixes,
                'maxItems' => 1200,
            ]],
            ['westliches_weserbergland_events', 'Westliches Weserbergland Veranstaltungskalender', SourceType::Html, 'https://www.westliches-weserbergland.de/', 'erfolgskreis_gt', 'Kreis Minden-Lübbecke', [
                'experience' => 'westliches-weserbergland',
                'bootstrapUrl' => 'https://pages.destination.one/de/westliches-weserbergland/default/search/Event/mode:next_months,12/sort:chronological',
                'allowedCities' => ['Porta Westfalica', 'Petershagen'],
                'postalPrefixes' => ['32457', '32469'],
                'maxItems' => 500,
            ]],
            ['vhs_minden_bad_oeynhausen', 'VHS Minden / Bad Oeynhausen', SourceType::Html, 'https://www.vhs-minden.de/kurssuche/liste', 'vhs_re', 'Kreis Minden-Lübbecke', [
                'category' => 'bildung',
                'maxPages' => 12,
            ]],
            ['bad_holzhausen_tribe', 'Bad Holzhausen Veranstaltungen', SourceType::Json, 'https://www.bad-holzhausen.de/wp-json/tribe/events/v1/events', 'tribe_events', 'Preußisch Oldendorf'],
        ];
        foreach ($mindenLuebbeckeCatalog as $entry) {
            [$key, $name, $type, $url, $importer, $city] = array_slice($entry, 0, 6);
            $config = \is_array($entry[6] ?? null) ? $entry[6] : [];
            $out[$key] = [
                'minden-luebbecke',
                $name,
                $type,
                $url,
                $importer,
                $city,
                \in_array($importer, $ready, true) && !\in_array($key, $disabledInitially, true),
                $config,
            ];
        }

        // [key => [name, type, url, importer, city, optional config]]
        $bielefeldCatalog = [
            ['bielefeld_jetzt_cityteam_events', 'Bielefeld.JETZT / City.Team Veranstaltungskalender', SourceType::Html, 'https://www.citybielefeld.de/termine/monat', 'bielefeld_jetzt', 'Bielefeld'],
            ['uni_bielefeld_veranstaltungen', 'Universität Bielefeld – Veranstaltungskalender', SourceType::Html, 'https://aktuell.uni-bielefeld.de/alle-events/', 'tribe_events', 'Bielefeld'],
            ['stadtbibliothek_bielefeld_events', 'Stadtbibliothek Bielefeld', SourceType::Ics, 'https://events.stadtbibliothek-bielefeld.de/events/ical/?locale=de', 'ics', 'Bielefeld', [
                'venue' => 'Stadtbibliothek Bielefeld',
                'category' => 'bildung',
            ]],
            ['historisches_museum_bielefeld', 'Historisches Museum Bielefeld', SourceType::Html, 'https://www.historisches-museum-bielefeld.de/events/', 'tribe_events', 'Bielefeld'],
            ['vhs_bielefeld', 'Volkshochschule Bielefeld', SourceType::Html, 'https://www.vhs-bielefeld.de/kurssuche/liste', 'vhs_re', 'Bielefeld', [
                'category' => 'bildung',
                'maxPages' => 12,
            ]],
            ['stereo_bielefeld', 'Stereo Bielefeld', SourceType::Html, 'https://stereo-bielefeld.de/programm/', 'jsonld', 'Bielefeld', [
                'venue' => 'Stereo Bielefeld',
                'category' => 'party',
            ]],
        ];
        foreach ($bielefeldCatalog as $entry) {
            [$key, $name, $type, $url, $importer, $city] = array_slice($entry, 0, 6);
            $config = \is_array($entry[6] ?? null) ? $entry[6] : [];
            $out[$key] = [
                'bielefeld',
                $name,
                $type,
                $url,
                $importer,
                $city,
                \in_array($importer, $ready, true),
                $config,
            ];
        }

        foreach ($this->ewuBundSourceDefs() as [$regionKey, $key, $name, $city, $postalPrefixes]) {
            $out[$key] = [
                $regionKey,
                $name,
                SourceType::Json,
                'https://ewu-bund.com/wp-json/tribe/events/v1/events',
                'ewu_bund',
                $city,
                true,
                ['postalPrefixes' => $postalPrefixes],
            ];
        }

        // Throwaway demo source for the optional demo-event batch. Disabled: a
        // manual source has no importer, and an enabled one only produces a
        // "kein Importer" warning on every scheduled --all-regions run.
        $out['demo'] = ['guetersloh', 'Dalketicker (Demo-Daten)', SourceType::Manual, null, null, null, false];

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
                $out[$key] = ['guetersloh', $name, $type, $url, 'manual', 'Gütersloh', false];
            }
        }

        return $out;
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string,4:list<string>}>
     */
    private function ewuBundSourceDefs(): array
    {
        return [
            ['guetersloh', 'ewu_bund_reitsport', 'EWU Bund – Reitsporttermine im Kreis Gütersloh', 'Kreis Gütersloh', ['3333', '3337', '3339', '3341', '3342', '3344', '3375', '3377', '3380', '3382']],
            ['paderborn', 'ewu_bund_reitsport_paderborn', 'EWU Bund – Reitsporttermine im Kreis Paderborn', 'Kreis Paderborn', ['3309', '3310', '3312', '3314', '3315', '3316', '3317', '3318']],
            ['minden-luebbecke', 'ewu_bund_reitsport_minden_luebbecke', 'EWU Bund – Reitsporttermine im Kreis Minden-Lübbecke', 'Kreis Minden-Lübbecke', ['3231', '3233', '3235', '3236', '3237', '3242', '3245', '3254', '3258', '3260', '3262']],
            ['bielefeld', 'ewu_bund_reitsport_bielefeld', 'EWU Bund – Reitsporttermine in Bielefeld', 'Bielefeld', ['336', '3371', '3372', '3373']],
        ];
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
