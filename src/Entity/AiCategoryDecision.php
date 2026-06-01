<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit trail for the AI categorization pass. One row per event the AI looked
 * at, enabling review/undo in /admin and skip-tracking on re-runs.
 *
 * mode:   'fill'    — event had no real category; AI categories were applied.
 *         'opinion' — event already had a category; AI gave a second opinion.
 * status: 'applied'   — categories were set by us (fill, or an accepted suggestion).
 *         'pending'   — opinion contradicts the importer category; awaits admin.
 *         'agreed'    — opinion matched; nothing changed (just a "checked" marker).
 *         'dismissed' — admin rejected the suggestion.
 *         'undone'    — admin reverted a previously applied decision.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_category_decision')]
#[ORM\Index(name: 'idx_aicat_status', columns: ['status'])]
#[ORM\UniqueConstraint(name: 'uniq_aicat_event', columns: ['event_id'])]
class AiCategoryDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Event $event;

    #[ORM\Column(length: 20)]
    private string $mode;

    #[ORM\Column(length: 20)]
    private string $status;

    /** @var list<string> category slugs the event had before */
    #[ORM\Column(type: Types::JSON)]
    private array $previousSlugs = [];

    /** @var list<string> category slugs the AI proposed */
    #[ORM\Column(type: Types::JSON)]
    private array $proposedSlugs = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 80)]
    private string $model = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /**
     * @param list<string> $previousSlugs
     * @param list<string> $proposedSlugs
     */
    public function __construct(Event $event, string $mode, string $status, array $previousSlugs, array $proposedSlugs, string $model, ?string $reason)
    {
        $this->event = $event;
        $this->mode = $mode;
        $this->status = $status;
        $this->previousSlugs = $previousSlugs;
        $this->proposedSlugs = $proposedSlugs;
        $this->model = substr($model, 0, 80);
        $this->reason = $reason;
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @return list<string> */
    public function getPreviousSlugs(): array
    {
        return $this->previousSlugs;
    }

    /** @return list<string> */
    public function getProposedSlugs(): array
    {
        return $this->proposedSlugs;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }
}
