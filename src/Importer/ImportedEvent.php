<?php

declare(strict_types=1);

namespace App\Importer;

use App\Enum\BookingStatus;

/**
 * Normalized, source-agnostic representation of one event as produced by an
 * importer. The {@see \App\Service\EventImporter} turns these into persisted
 * {@see \App\Entity\Event}s, resolving venues/categories and deduplicating.
 */
final class ImportedEvent
{
    public function __construct(
        public string $title,
        public \DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt = null,
        public bool $allDay = false,
        public ?string $description = null,
        public ?string $venueName = null,
        public ?string $city = null,
        public ?string $locationText = null,
        public ?string $categorySlug = null,
        public ?string $sourceUrl = null,
        public ?string $imageUrl = null,
        public ?string $price = null,
        public ?string $organizer = null,
        public ?string $externalId = null,
        public array $raw = [],
        public bool $isCourse = false,
        public ?BookingStatus $bookingStatus = null,
        // Additional equal-rank categories beyond $categorySlug (e.g. a cinema's
        // kids' film is ['kino'] + ['familie']). Merged via allCategorySlugs().
        /** @var list<string> */
        public array $categorySlugs = [],
    ) {
        $this->title = trim($title);
    }

    /**
     * All distinct category slugs for this event: the primary $categorySlug
     * plus any $categorySlugs, empties dropped.
     *
     * @return list<string>
     */
    public function allCategorySlugs(): array
    {
        $slugs = array_merge($this->categorySlug !== null ? [$this->categorySlug] : [], $this->categorySlugs);

        return array_values(array_unique(array_filter($slugs, static fn (string $s) => $s !== '')));
    }

    /**
     * Cross-source dedup key: normalized title + calendar day + place. Two
     * sources advertising the same event on the same day collapse onto this.
     */
    public function dedupKey(): string
    {
        // Place: prefer the municipality over the (often varying) venue string,
        // so the same event from two sources with slightly different venue
        // wording still collapses.
        $place = $this->city ?? $this->venueName ?? $this->locationText ?? '';

        return substr(
            self::normalizeTitle($this->title).'|'.$this->startsAt->format('Y-m-d').'|'.self::normalize($place),
            0,
            191,
        );
    }

    /**
     * Title normalization for deduplication: drop a trailing subtitle/suffix
     * after a spaced dash (e.g. "… - Eintritt frei") and any parentheticals,
     * then strip to alphanumerics. Catches the common cross-source variants.
     */
    public static function normalizeTitle(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = preg_replace('/\s+[–—-]\s+.*$/u', '', $t) ?? $t; // cut at first " - "
        $t = preg_replace('/\([^)]*\)/u', '', $t) ?? $t;       // drop (…)

        return self::normalize($t);
    }

    /** Hash of meaningful fields; unchanged hash => skip the update. */
    public function contentHash(): string
    {
        return sha1(implode('|', [
            $this->title,
            $this->startsAt->format('c'),
            $this->endsAt?->format('c') ?? '',
            $this->allDay ? '1' : '0',
            $this->venueName ?? '',
            $this->city ?? '',
            $this->locationText ?? '',
            $this->description ?? '',
            $this->sourceUrl ?? '',
            $this->imageUrl ?? '',
            $this->price ?? '',
            $this->organizer ?? '',
            implode(',', $this->allCategorySlugs()),
            $this->isCourse ? '1' : '0',
            $this->bookingStatus?->value ?? '',
        ]));
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value))) ?? '';
    }
}
