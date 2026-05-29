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
 */
final class EventFilter
{
    /**
     * @param list<string> $categorySlugs
     */
    public function __construct(
        public ?string $q = null,
        public array $categorySlugs = [],
        public ?string $city = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $parseDate = static function (?string $value): ?\DateTimeImmutable {
            if (!$value) {
                return null;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('Europe/Berlin'));

            return $date ?: null;
        };

        $categories = [];
        foreach ((array) $request->query->all('kategorie') as $slug) {
            $slug = is_string($slug) ? trim($slug) : '';
            if ($slug !== '') {
                $categories[] = $slug;
            }
        }

        return new self(
            q: self::clean($request->query->get('q')),
            categorySlugs: array_values(array_unique($categories)),
            city: self::clean($request->query->get('ort')),
            from: $parseDate(self::clean($request->query->get('von'))),
            to: $parseDate(self::clean($request->query->get('bis'))),
        );
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
            || $this->from !== null
            || $this->to !== null;
    }

    /** Query params for building links/canonical URLs, dropping empty values. */
    public function toQueryParams(): array
    {
        return array_filter([
            'q' => $this->q,
            'kategorie' => $this->categorySlugs,
            'ort' => $this->city,
            'von' => $this->from?->format('Y-m-d'),
            'bis' => $this->to?->format('Y-m-d'),
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }
}
