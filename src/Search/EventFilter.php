<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\HttpFoundation\Request;

/**
 * Immutable-ish bag of listing filters, hydrated straight from query params.
 * Kept deliberately scalar so it round-trips cleanly into links and forms.
 */
final class EventFilter
{
    public function __construct(
        public ?string $q = null,
        public ?string $categorySlug = null,
        public ?string $city = null,
        public ?string $sourceKey = null,
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

        return new self(
            q: self::clean($request->query->get('q')),
            categorySlug: self::clean($request->query->get('kategorie')),
            city: self::clean($request->query->get('ort')),
            sourceKey: self::clean($request->query->get('quelle')),
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

    public function hasAny(): bool
    {
        return $this->q !== null
            || $this->categorySlug !== null
            || $this->city !== null
            || $this->sourceKey !== null
            || $this->from !== null
            || $this->to !== null;
    }

    /** Query params for building links/canonical URLs, dropping empty values. */
    public function toQueryParams(): array
    {
        return array_filter([
            'q' => $this->q,
            'kategorie' => $this->categorySlug,
            'ort' => $this->city,
            'quelle' => $this->sourceKey,
            'von' => $this->from?->format('Y-m-d'),
            'bis' => $this->to?->format('Y-m-d'),
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
