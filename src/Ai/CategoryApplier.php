<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\AiCategoryDecision;
use App\Entity\Category;
use App\Entity\Event;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies (and reverses) AI category decisions, recording an auditable
 * {@see AiCategoryDecision} per event. Mirrors {@see DuplicateMerger}. The AI
 * never writes the DB itself; this is the only place categories change.
 * Callers flush.
 */
final class CategoryApplier
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * Event had no real category → set the AI categories straight away.
     *
     * @param list<string> $slugs
     */
    public function applyFill(Event $event, array $slugs, string $model, ?string $reason): AiCategoryDecision
    {
        $previous = $this->currentSlugs($event);
        $event->setCategories($this->resolve($slugs));

        $decision = new AiCategoryDecision($event, 'fill', 'applied', $previous, $slugs, $model, $reason);
        $this->em->persist($decision);

        return $decision;
    }

    /**
     * Event already had a category and the AI clearly disagrees → record a
     * pending suggestion for an admin to accept/dismiss (no change yet).
     *
     * @param list<string> $slugs
     */
    public function recordSuggestion(Event $event, array $slugs, string $model, ?string $reason): AiCategoryDecision
    {
        $decision = new AiCategoryDecision($event, 'opinion', 'pending', $this->currentSlugs($event), $slugs, $model, $reason);
        $this->em->persist($decision);

        return $decision;
    }

    /** Opinion matched the existing category → just mark the event as checked. */
    public function recordAgreed(Event $event, string $model): AiCategoryDecision
    {
        $current = $this->currentSlugs($event);
        $decision = new AiCategoryDecision($event, 'opinion', 'agreed', $current, $current, $model, null);
        $this->em->persist($decision);

        return $decision;
    }

    /** Admin accepts a pending suggestion → apply the proposed categories. */
    public function acceptSuggestion(AiCategoryDecision $decision): void
    {
        if ($decision->getStatus() !== 'pending') {
            return;
        }
        $decision->getEvent()->setCategories($this->resolve($decision->getProposedSlugs()));
        $decision->setStatus('applied')->setReviewedAt(new \DateTimeImmutable('now'));
    }

    public function dismissSuggestion(AiCategoryDecision $decision): void
    {
        if ($decision->getStatus() !== 'pending') {
            return;
        }
        $decision->setStatus('dismissed')->setReviewedAt(new \DateTimeImmutable('now'));
    }

    /** Restore the categories the event had before this decision was applied. */
    public function undo(AiCategoryDecision $decision): void
    {
        if ($decision->getStatus() !== 'applied') {
            return;
        }
        $decision->getEvent()->setCategories($this->resolve($decision->getPreviousSlugs()));
        $decision->setStatus('undone')->setReviewedAt(new \DateTimeImmutable('now'));
    }

    /** @return list<string> */
    private function currentSlugs(Event $event): array
    {
        $slugs = [];
        foreach ($event->getCategories() as $category) {
            $slugs[] = $category->getSlug();
        }

        return $slugs;
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<Category>
     */
    private function resolve(array $slugs): array
    {
        $cats = [];
        foreach ($slugs as $slug) {
            $category = $this->categories->findBySlug($slug);
            if ($category !== null) {
                $cats[] = $category;
            }
        }

        return $cats;
    }
}
