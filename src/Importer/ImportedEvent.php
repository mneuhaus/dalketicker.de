<?php

declare(strict_types=1);

namespace App\Importer;

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
    ) {
        $this->title = trim($title);
    }

    /**
     * Cross-source dedup key: normalized title + calendar day + place. Two
     * sources advertising the same event on the same day collapse onto this.
     */
    public function dedupKey(): string
    {
        $place = $this->venueName ?? $this->city ?? $this->locationText ?? '';

        return substr(
            self::normalize($this->title).'|'.$this->startsAt->format('Y-m-d').'|'.self::normalize($place),
            0,
            191,
        );
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
            $this->categorySlug ?? '',
        ]));
    }

    public static function normalize(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value))) ?? '';
    }
}
