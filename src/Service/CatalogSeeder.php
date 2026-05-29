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

        foreach ($this->sourceDefs() as $key => [$name, $type, $url, $enabled]) {
            $source = $this->sourceRepo->findByKey($key) ?? new Source($key, $name, $type);
            $source->setName($name)->setType($type)->setUrl($url);
            // Don't override an operator's enable/disable choice on re-seed:
            if ($source->getId() === null) {
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

    /** @return array<string, array{0:string,1:SourceType,2:?string,3:bool}> */
    private function sourceDefs(): array
    {
        return [
            'stadt_gt' => ['Stadt Gütersloh – Veranstaltungskalender', SourceType::Html, 'https://www.guetersloh.de/de/veranstaltungen/', false],
            'veranstaltungen_gt' => ['veranstaltungen-gt.de', SourceType::Html, 'https://veranstaltungen-gt.de/', false],
            'wapelbad' => ['Wapelbad', SourceType::Html, 'https://www.wapelbad.de/about-1', false],
            'radio_gt' => ['Radio Gütersloh – Veranstaltungstipps', SourceType::Html, 'https://www.radioguetersloh.de/service/veranstaltungstipps.html', false],
            'erfolgskreis_gt' => ['Erfolgskreis GT', SourceType::Html, 'https://www.erfolgskreis-gt.de/veranstaltungen', false],
            'nw' => ['Neue Westfälische – Kreis Gütersloh', SourceType::Html, 'https://www.nw.de/themen/lokal/kreis_guetersloh/veranstaltungen-im-kreis-guetersloh', false],
            'gtv1879' => ['GTV 1879 – Termine', SourceType::Html, 'https://gtv1879.de/termine/', false],
            'spexard' => ['Spexard – Termine', SourceType::Pdf, 'https://www.spexard.de/termine', false],
            'anno_events' => ['Anno Events', SourceType::Html, 'https://anno-events.de/', false],
            'wolpertinger' => ['Wolpertinger – Der Spieleladen', SourceType::Html, 'https://wolpertinger-der-spieleladen.de/', false],
            'stadtbibliothek' => ['Stadtbibliothek Gütersloh', SourceType::Html, null, false],
            'facebook' => ['Facebook Events (Umgebung)', SourceType::Facebook, 'https://www.facebook.com/events', false],
            'demo' => ['Dalketicker (Demo-Daten)', SourceType::Manual, null, true],
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
