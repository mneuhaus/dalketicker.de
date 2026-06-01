<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\AiDedupDecision;
use App\Entity\Event;
use App\Enum\EventStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies (and undoes) an AI-decided duplicate merge — the exact same mechanism
 * the importer uses for key-based dedup: status=Duplicate + duplicateOf. Records
 * an {@see AiDedupDecision} so every AI merge is auditable and reversible.
 * Callers flush.
 */
final class DuplicateMerger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function apply(Event $duplicate, Event $canonical, \DateTimeImmutable $day, string $model, ?string $reason): AiDedupDecision
    {
        $duplicate->setStatus(EventStatus::Duplicate);
        $duplicate->setDuplicateOf($canonical);

        $decision = new AiDedupDecision($day, $duplicate, $canonical, $model, $reason, new \DateTimeImmutable('now'));
        $this->em->persist($decision);

        return $decision;
    }

    /** Restore a previously AI-merged event to Published and mark the decision undone. */
    public function undo(AiDedupDecision $decision): void
    {
        $event = $decision->getDuplicateEvent();
        if ($event !== null && $event->getStatus() === EventStatus::Duplicate) {
            $event->setStatus(EventStatus::Published);
            $event->setDuplicateOf(null);
        }
        $decision->setActive(false);
    }
}
