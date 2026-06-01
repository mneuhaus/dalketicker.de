<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit trail for the AI deduplication pass: one row per merge the AI proposed
 * and we applied (duplicate → canonical). Enables review and undo in /admin.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_dedup_decision')]
class AiDedupDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    /** The event that was demoted to a duplicate. */
    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Event $duplicateEvent = null;

    /** The kept (canonical) event the duplicate now points to. */
    #[ORM\ManyToOne(targetEntity: Event::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Event $canonicalEvent = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 80)]
    private string $model = '';

    /** False once an admin has undone this merge (kept for the audit log). */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $day, Event $duplicate, Event $canonical, string $model, ?string $reason, \DateTimeImmutable $createdAt)
    {
        $this->day = $day;
        $this->duplicateEvent = $duplicate;
        $this->canonicalEvent = $canonical;
        $this->model = substr($model, 0, 80);
        $this->reason = $reason;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getDuplicateEvent(): ?Event
    {
        return $this->duplicateEvent;
    }

    public function getCanonicalEvent(): ?Event
    {
        return $this->canonicalEvent;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
