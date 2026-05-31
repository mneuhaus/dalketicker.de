<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BookingStatus;
use App\Enum\EventStatus;
use App\Repository\EventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single normalized event, aggregated from one {@see Source}.
 *
 * Two layers of deduplication:
 *  - within a source: ({@see $source}, {@see $externalId}) is unique, so a
 *    re-import updates the existing row instead of inserting a copy;
 *  - across sources: {@see $dedupKey} (normalized title + day + city) is
 *    indexed; the import pipeline demotes later matches to
 *    {@see EventStatus::Duplicate} and links them via {@see $duplicateOf}.
 */
#[ORM\Entity(repositoryClass: EventRepository::class)]
#[ORM\Table(name: 'event')]
#[ORM\UniqueConstraint(name: 'uniq_event_source_external', columns: ['source_id', 'external_id'])]
#[ORM\Index(name: 'idx_event_starts_at', columns: ['starts_at'])]
#[ORM\Index(name: 'idx_event_dedup_key', columns: ['dedup_key'])]
#[ORM\Index(name: 'idx_event_status', columns: ['status'])]
class Event
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 300)]
    private string $title;

    #[ORM\Column(length: 320)]
    private string $slug;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column]
    private bool $allDay = false;

    #[ORM\ManyToOne(targetEntity: Venue::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Venue $venue = null;

    /** Raw location string when no {@see Venue} could be resolved. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $locationText = null;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Source $source;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $sourceUrl = null;

    #[ORM\Column(length: 1024, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $price = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $organizer = null;

    /** Identifier within the originating source (UID, permalink, hash). */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $externalId = null;

    /** Hash of the meaningful fields; lets us skip unchanged re-imports. */
    #[ORM\Column(length: 40)]
    private string $contentHash = '';

    /** Normalized title + day + city, indexed for cross-source dedup. */
    #[ORM\Column(length: 191)]
    private string $dedupKey = '';

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $duplicateOf = null;

    #[ORM\Column(length: 20, enumType: EventStatus::class)]
    private EventStatus $status = EventStatus::Published;

    /** Secondary classification: course-like offering (VHS etc.), orthogonal to category. */
    #[ORM\Column]
    private bool $isCourse = false;

    /** Booking/availability state for courses (null = unknown / not a course). */
    #[ORM\Column(length: 20, nullable: true, enumType: BookingStatus::class)]
    private ?BookingStatus $bookingStatus = null;

    /** Untrusted raw payload from the source, kept for debugging/re-mapping. */
    #[ORM\Column(type: Types::JSON)]
    private array $raw = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(string $title, \DateTimeImmutable $startsAt, Source $source)
    {
        $this->title = $title;
        $this->startsAt = $startsAt;
        $this->source = $source;
        $this->slug = '';
        $now = $startsAt; // overwritten by lifecycle/import; placeholder for non-managed instances
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): static
    {
        $this->allDay = $allDay;

        return $this;
    }

    public function getVenue(): ?Venue
    {
        return $this->venue;
    }

    public function setVenue(?Venue $venue): static
    {
        $this->venue = $venue;

        return $this;
    }

    public function getLocationText(): ?string
    {
        return $this->locationText;
    }

    public function setLocationText(?string $locationText): static
    {
        $this->locationText = $locationText;

        return $this;
    }

    /** Human-readable location, preferring a resolved venue. */
    public function getDisplayLocation(): ?string
    {
        if ($this->venue !== null) {
            return $this->venue->getName().' · '.$this->venue->getCity();
        }

        return $this->locationText;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function setSource(Source $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSourceUrl(): ?string
    {
        return $this->sourceUrl;
    }

    public function setSourceUrl(?string $sourceUrl): static
    {
        $this->sourceUrl = $sourceUrl;

        return $this;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): static
    {
        $this->imageUrl = $imageUrl;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function getOrganizer(): ?string
    {
        return $this->organizer;
    }

    public function setOrganizer(?string $organizer): static
    {
        $this->organizer = $organizer;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId !== null ? substr($externalId, 0, 191) : null;

        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;

        return $this;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function setDedupKey(string $dedupKey): static
    {
        $this->dedupKey = substr($dedupKey, 0, 191);

        return $this;
    }

    public function getDuplicateOf(): ?self
    {
        return $this->duplicateOf;
    }

    public function setDuplicateOf(?self $duplicateOf): static
    {
        $this->duplicateOf = $duplicateOf;

        return $this;
    }

    public function getStatus(): EventStatus
    {
        return $this->status;
    }

    public function setStatus(EventStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isCourse(): bool
    {
        return $this->isCourse;
    }

    public function setIsCourse(bool $isCourse): static
    {
        $this->isCourse = $isCourse;

        return $this;
    }

    public function getBookingStatus(): ?BookingStatus
    {
        return $this->bookingStatus;
    }

    public function setBookingStatus(?BookingStatus $bookingStatus): static
    {
        $this->bookingStatus = $bookingStatus;

        return $this;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }

    public function setRaw(array $raw): static
    {
        $this->raw = $raw;

        return $this;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }
}
