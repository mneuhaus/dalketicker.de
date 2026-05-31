<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\HttpFoundation\Request;

/**
 * Immutable-ish bag of listing filters, hydrated straight from query params.
 * Kept deliberately scalar so it round-trips cleanly into links and forms.
 *
 * Categories are multi-select: an empty set means "show everything"; otherwise
 * only the selected categories are shown (uncheck a box to hide that category).
 *
 * The time range is chosen via named presets ({@see PERIODS}) rather than a
 * date picker; the preset is resolved to a concrete from/to window here.
 */
final class EventFilter
{
    /** Preset key => human label, in display order. */
    public const PERIODS = [
        'heute' => 'Heute',
        'morgen' => 'Morgen',
        'wochenende' => 'Wochenende',
        'woche' => 'Diese Woche',
        '7tage' => 'Nächste 7 Tage',
    ];

    /**
     * @param list<string> $categorySlugs
     * @param list<int>    $savedIds
     */
    public function __construct(
        public ?string $q = null,
        public array $categorySlugs = [],
        public ?string $city = null,
        public ?string $period = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public bool $onlySaved = false,
        public array $savedIds = [],
        public ?string $course = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $categories = [];
        foreach ((array) $request->query->all('kategorie') as $slug) {
            $slug = is_string($slug) ? trim($slug) : '';
            if ($slug !== '') {
                $categories[] = $slug;
            }
        }

        // Custom date range (von/bis) takes precedence over the named presets.
        $von = self::parseDate(self::clean($request->query->get('von')));
        $bis = self::parseDate(self::clean($request->query->get('bis')));

        $period = self::clean($request->query->get('zeitraum'));
        if ($period !== null && !isset(self::PERIODS[$period])) {
            $period = null;
        }

        if ($von !== null || $bis !== null) {
            $period = null;
            $from = $von;
            $to = $bis;
        } else {
            [$from, $to] = self::resolvePeriod($period);
        }

        $course = self::clean($request->query->get('kurse'));
        if (!in_array($course, ['only', 'hide'], true)) {
            $course = null;
        }

        $onlySaved = $request->query->get('meine') === '1';
        $savedIds = [];
        foreach (explode(',', (string) $request->query->get('ids')) as $id) {
            $id = (int) trim($id);
            if ($id > 0) {
                $savedIds[] = $id;
            }
        }

        return new self(
            q: self::clean($request->query->get('q')),
            categorySlugs: array_values(array_unique($categories)),
            city: self::clean($request->query->get('ort')),
            period: $period,
            from: $from,
            to: $to,
            onlySaved: $onlySaved,
            savedIds: array_values(array_unique($savedIds)),
            course: $course,
        );
    }

    /**
     * Turn a preset key into an inclusive [from, to] day window (or [null, null]
     * for "no time filter"). Both bounds are at 00:00 Europe/Berlin; the repo
     * treats `to` as the last day to include.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private static function resolvePeriod(?string $period): array
    {
        if ($period === null) {
            return [null, null];
        }

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Berlin'));
        $dow = (int) $today->format('N'); // 1 = Mon … 7 = Sun

        return match ($period) {
            'heute' => [$today, $today],
            'morgen' => [$today->modify('+1 day'), $today->modify('+1 day')],
            '7tage' => [$today, $today->modify('+6 days')],
            // Rest of the current ISO week (today … Sunday).
            'woche' => [$today, $today->modify('+'.(7 - $dow).' days')],
            // The coming weekend (Sat+Sun); on Sat/Sun it's the current one.
            'wochenende' => match ($dow) {
                6 => [$today, $today->modify('+1 day')],      // Saturday
                7 => [$today, $today],                         // Sunday
                default => [$today->modify('+'.(6 - $dow).' days'), $today->modify('+'.(7 - $dow).' days')],
            },
            default => [null, null],
        };
    }

    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Berlin'));

        return $date ?: null;
    }

    /** True when a custom date range is active (rather than a named preset). */
    public function hasCustomDate(): bool
    {
        return $this->period === null && ($this->from !== null || $this->to !== null);
    }

    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function hasCategory(string $slug): bool
    {
        return in_array($slug, $this->categorySlugs, true);
    }

    public function hasAny(): bool
    {
        return $this->q !== null
            || $this->categorySlugs !== []
            || $this->city !== null
            || $this->period !== null
            || $this->hasCustomDate()
            || $this->onlySaved
            || $this->course !== null;
    }

    /** Query params for building links/canonical URLs, dropping empty values. */
    public function toQueryParams(): array
    {
        $params = [
            'q' => $this->q,
            'kategorie' => $this->categorySlugs,
            'ort' => $this->city,
            'zeitraum' => $this->period,
            'von' => $this->hasCustomDate() ? $this->from?->format('Y-m-d') : null,
            'bis' => $this->hasCustomDate() ? $this->to?->format('Y-m-d') : null,
            'kurse' => $this->course,
        ];
        if ($this->onlySaved) {
            $params['meine'] = '1';
            $params['ids'] = implode(',', $this->savedIds);
        }

        return array_filter($params, static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
