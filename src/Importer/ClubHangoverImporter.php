<?php

declare(strict_types=1);

namespace App\Importer;

use App\Entity\Source;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bespoke importer for Club Hangover (Gütersloh). The Jimdo events page lists
 * each party as an <h1> title followed by promo text with emoji-anchored
 * details (📅 date, 📍 location, time). We split on the headings, pull the date
 * from the 📅 anchor (formats vary: "23.05", "13.06.", "20. Juni 2026",
 * "27. Juni"), infer the year from proximity to today (validated by an optional
 * weekday word) and read an optional start time. Free-text source → tolerant
 * parsing, only emits a block that yields a usable date.
 */
#[AutoconfigureTag('app.source_importer')]
final class ClubHangoverImporter implements SourceImporter
{
    private const DEFAULT_URL = 'https://www.clubhangover.de/events/';
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private const MONTHS = [
        'januar' => 1, 'jan' => 1, 'februar' => 2, 'feb' => 2, 'märz' => 3, 'maerz' => 3, 'mrz' => 3,
        'april' => 4, 'apr' => 4, 'mai' => 5, 'juni' => 6, 'jun' => 6, 'juli' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'oktober' => 10, 'okt' => 10,
        'november' => 11, 'nov' => 11, 'dezember' => 12, 'dez' => 12,
    ];
    /** German weekday word → ISO day (1=Mon … 7=Sun), for year validation. */
    private const WEEKDAYS = [
        'montag' => 1, 'dienstag' => 2, 'mittwoch' => 3, 'donnerstag' => 4,
        'freitag' => 5, 'samstag' => 6, 'sonnabend' => 6, 'sonntag' => 7,
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public static function getKey(): string
    {
        return 'club_hangover';
    }

    public function import(Source $source): iterable
    {
        $url = $source->getUrl() ?: self::DEFAULT_URL;
        $config = $source->getConfig();
        $city = $config['city'] ?? 'Gütersloh';
        $venue = $config['venue'] ?? 'Club Hangover';

        $html = $this->http->request('GET', $url, [
            'headers' => ['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'de-DE,de;q=0.9'],
            'timeout' => 30,
        ])->getContent();

        $now = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $seen = [];

        // Each event is an <h1> title followed by its promo block (up to the next <h1>).
        if (!preg_match_all('#<h1[^>]*>(.*?)</h1>(.*?)(?=<h1[^>]*>|</body>|$)#is', $html, $matches, \PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $block) {
            $title = $this->cleanText(strip_tags($block[1]));
            $body = $this->cleanText(strip_tags($block[2]));
            if ($title === '' || mb_strlen($title) > 90) {
                continue; // section header / promo line, not an event title
            }

            $date = $this->parseDate($body, $now);
            if ($date === null) {
                continue; // no usable date in this block → not an event
            }

            [$start, $allDay] = $this->applyTime($date, $body);
            $key = $start->format('Y-m-d').'|'.ImportedEvent::normalizeTitle($title);
            if (isset($seen[$key])) {
                continue; // de-dupe the German/Polish twins of the same party
            }
            $seen[$key] = true;

            yield new ImportedEvent(
                title: $title,
                startsAt: $start,
                allDay: $allDay,
                description: mb_substr($body, 0, 1500) ?: null,
                venueName: $venue,
                city: $city,
                categorySlug: 'party',
                sourceUrl: $url,
                organizer: $venue,
                externalId: 'hangover-'.substr(sha1($key), 0, 16),
            );
        }
    }

    /** Find the 📅-anchored (or first) date in the block; null if none parses. */
    private function parseDate(string $body, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        // Prefer the part right after the calendar emoji, else scan the whole block.
        $scan = $body;
        if (($pos = mb_strpos($body, '📅')) !== false) {
            $scan = mb_substr($body, $pos, 60).' '.$body;
        }
        $weekday = null;
        foreach (self::WEEKDAYS as $word => $iso) {
            if (mb_stripos($scan, $word) !== false) {
                $weekday = $iso;
                break;
            }
        }

        // "20. Juni 2026" / "27. Juni"
        if (preg_match('/(\d{1,2})\.?\s*('.implode('|', array_keys(self::MONTHS)).')\.?\s*(\d{4})?/iu', $scan, $m)) {
            $day = (int) $m[1];
            $month = self::MONTHS[mb_strtolower($m[2])];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

            return $this->build($day, $month, $year, $weekday, $now);
        }
        // "23.05" / "13.06." / "13.06.2026"
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.?\s*(\d{4})?/', $scan, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return $this->build($day, $month, $year, $weekday, $now);
            }
        }

        return null;
    }

    /**
     * Build the date. With an explicit year, use it. Otherwise pick the year
     * (last/this/next) that lands closest to today, preferring a match for the
     * given weekday so "Samstag 23.05" can't resolve to a non-Saturday year.
     */
    private function build(int $day, int $month, ?int $year, ?int $weekday, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $tz = $now->getTimezone();
        if ($year !== null) {
            return SafeDate::create($year, $month, $day, 0, 0, $tz);
        }

        $base = (int) $now->format('Y');
        $best = null;
        $bestScore = null;
        foreach ([$base - 1, $base, $base + 1] as $y) {
            $cand = SafeDate::create($y, $month, $day, 0, 0, $tz);
            if ($cand === null) {
                continue;
            }
            $matchesWeekday = $weekday === null || (int) $cand->format('N') === $weekday;
            $distance = abs($cand->getTimestamp() - $now->getTimestamp());
            // Weekday mismatch is heavily penalised but not impossible (typo tolerance).
            $score = $distance + ($matchesWeekday ? 0 : 400 * 86400);
            if ($bestScore === null || $score < $bestScore) {
                $bestScore = $score;
                $best = $cand;
            }
        }

        return $best;
    }

    /**
     * Apply a start time if the block states one ("Beginn: 22:00", "22–24 Uhr",
     * "ab 22 Uhr"); otherwise the event stays all-day.
     *
     * @return array{0: \DateTimeImmutable, 1: bool}
     */
    private function applyTime(\DateTimeImmutable $date, string $body): array
    {
        if (preg_match('/(?:beginn|einlass|start)[:\s]*?(\d{1,2})[:.](\d{2})/iu', $body, $m)
            || preg_match('/(?:ab|um)\s*(\d{1,2})(?:[:.](\d{2}))?\s*(?:uhr|h)\b/iu', $body, $m)
            || preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*[–-]\s*\d{1,2}\s*uhr/iu', $body, $m)) {
            $h = (int) $m[1];
            $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return [$date->setTime($h, $min), false];
            }
        }

        return [$date, true];
    }

    private function cleanText(string $s): string
    {
        $s = html_entity_decode($s, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }
}
