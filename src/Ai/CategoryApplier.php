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
     * Set the AI categories on the event straight away (used for both filling a
     * gap, mode 'fill', and overriding a wrong importer category, mode 'opinion').
     * The previous categories are recorded so the change can be undone.
     * Admin-pinned categories are never touched: the decision is recorded as
     * "dismissed" so the event still counts as checked and re-runs skip it.
     *
     * @param list<string> $slugs
     */
    public function apply(Event $event, array $slugs, string $mode, string $model, ?string $reason): AiCategoryDecision
    {
        $previous = $this->currentSlugs($event);

        if ($event->isFieldLocked('categories')) {
            $decision = new AiCategoryDecision($event, $mode, 'dismissed', $previous, $slugs, $model, 'Kategorien vom Admin gepinnt');
            $this->em->persist($decision);

            return $decision;
        }

        $event->setCategories($this->resolve($slugs));

        $decision = new AiCategoryDecision($event, $mode, 'applied', $previous, $slugs, $model, $reason);
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

    /**
     * Mark an event as checked even though the AI returned no proposal (too
     * little text to judge). Recorded as "dismissed" so it counts as processed
     * and isn't retried forever — otherwise the progress bar sticks at 99%.
     */
    public function recordSkipped(Event $event, string $model): AiCategoryDecision
    {
        $current = $this->currentSlugs($event);
        $decision = new AiCategoryDecision($event, 'fill', 'dismissed', $current, $current, $model, 'kein KI-Vorschlag');
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

    /**
     * Restore the categories the event had before this decision was applied.
     * Pinned categories stay untouched (the admin's pick wins over the restore).
     */
    public function undo(AiCategoryDecision $decision): void
    {
        if ($decision->getStatus() !== 'applied') {
            return;
        }
        $event = $decision->getEvent();
        if (!$event->isFieldLocked('categories')) {
            $event->setCategories($this->resolve($decision->getPreviousSlugs()));
        }
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
