<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Entity\ImportRun;
use App\Entity\Source;
use App\Enum\EventStatus;
use App\Importer\ImportedEvent;
use App\Importer\ImporterRegistry;
use App\Repository\CategoryRepository;
use App\Repository\EventRepository;
use App\Repository\VenueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Runs a source through its importer and upserts the results, handling slugs,
 * venue/category resolution, content-hash change detection and cross-source
 * deduplication. Returns a small {@see ImportReport} for CLI output.
 */
final class EventImporter
{
    /** How many upserted DTOs to collect before flushing mid-run. */
    private const FLUSH_BATCH_SIZE = 50;

    /**
     * Keys (source:externalId) of NEW events persisted in the current run,
     * to avoid inserting two rows that collide on the unique (source, externalId).
     *
     * @var array<string, true>
     */
    private array $newKeysThisRun = [];

    public function __construct(
        private readonly ImporterRegistry $registry,
        private readonly ManagerRegistry $managerRegistry,
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $events,
        private readonly VenueRepository $venues,
        private readonly CategoryRepository $categories,
        private readonly SluggerInterface $slugger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CityNormalizer $cityNormalizer,
    ) {
    }

    public function import(Source $source, bool $dryRun = false): ImportReport
    {
        $report = new ImportReport($source->getKey());
        $now = $this->clock->now();
        $this->newKeysThisRun = [];
        // The venue memo may still hold venues from a previous run that hung on
        // a since-reset (closed) EntityManager — detached entities that would
        // make every flush referencing them fail.
        $this->venues->resetRunMemo();
        $run = new ImportRun($source, $now, $dryRun);

        try {
            if (!$dryRun) {
                $this->beginRun($run);
            }

            $importer = $this->registry->get($source);
            $pending = 0;
            foreach ($importer->import($source) as $dto) {
                $report->seen++;
                try {
                    $this->upsert($source, $dto, $now, $dryRun, $report);
                } catch (\Throwable $e) {
                    $report->errors++;
                    $this->logger->error('Failed to upsert event "{title}" from {source}: {error}', [
                        'title' => $dto->title,
                        'source' => $source->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
                // Flush in batches so a row the database rejects surfaces as a
                // fatal error instead of silently discarding the whole run.
                if (!$dryRun && ++$pending >= self::FLUSH_BATCH_SIZE) {
                    $this->em->flush();
                    $pending = 0;
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $report->fatal = $e->getMessage();
            $this->logger->error('Import for source {source} failed: {error}', [
                'source' => $source->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        if (!$dryRun) {
            // A failed flush closes the EM. Reset it so this run can still be
            // recorded and subsequent sources (--all) keep working; previously
            // held entity references are detached then, so reload them.
            if (!$this->em->isOpen()) {
                $this->managerRegistry->resetManager();
                $this->venues->resetRunMemo();
                $sourceId = $source->getId();
                $source = ($sourceId !== null ? $this->em->find(Source::class, $sourceId) : null) ?? $source;
                $run = $this->reattachRun($run) ?? $run;
            }
            $source->setLastRunAt($now);
            $source->setLastStatus($report->fatal !== null
                ? 'FEHLER: '.substr($report->fatal, 0, 1024)
                : sprintf(
                    'OK: %d gesehen, %d neu, %d aktualisiert, %d Duplikate, %d Fehler',
                    $report->seen, $report->created, $report->updated, $report->duplicates, $report->errors,
                ));
            $this->recordRun($run, $report, $report->fatal);
        }

        return $report;
    }

    /**
     * Manually upsert a single event into a source — e.g. an operator adding a
     * Facebook-only or flyer event that no automated importer can reach. Runs
     * through the exact same path as a real import (city normalization,
     * venue + category resolution, slug, content hash and cross-source dedup),
     * so a later automated run reconciles cleanly instead of duplicating it.
     *
     * @return bool true if a new event was created, false if an existing one was updated/unchanged
     */
    public function upsertOne(Source $source, ImportedEvent $dto): bool
    {
        $report = new ImportReport($source->getKey());
        $this->newKeysThisRun = [];
        $this->venues->resetRunMemo();
        $this->upsert($source, $dto, $this->clock->now(), false, $report);
        $this->em->flush();

        return $report->created > 0;
    }

    /** Persist the run upfront as "running" so killed runs leave a visible trace. */
    private function beginRun(ImportRun $run): void
    {
        $run->setStatus(ImportRun::STATUS_RUNNING);
        $this->em->persist($run);
        $this->em->flush();
    }

    /**
     * Finalize and persist the run record + everything pending in one flush.
     * Failures are logged and retried once on a fresh EntityManager — the run
     * record is the operator's monitoring trail and must not vanish silently.
     */
    private function recordRun(ImportRun $run, ImportReport $report, ?string $fatal): void
    {
        $finishedAt = $this->clock->now();
        $complete = static fn (ImportRun $r): ImportRun => $r->complete(
            $finishedAt,
            $report->seen, $report->created, $report->updated,
            $report->unchanged, $report->duplicates, $report->errors,
            $fatal,
        );

        try {
            $this->em->persist($complete($run));
            $this->em->flush();

            return;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record import run for {source}: {error}', [
                'source' => $report->sourceKey,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            if (!$this->em->isOpen()) {
                $this->managerRegistry->resetManager();
                $this->venues->resetRunMemo();
            }
            $retry = $this->reattachRun($run);
            if ($retry === null) {
                return;
            }
            $this->em->persist($complete($retry));
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to record import run after EntityManager reset for {source}: {error}', [
                'source' => $report->sourceKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * After an EntityManager reset every held reference is detached: fetch the
     * managed copy of the run, or rebuild it when it was never flushed. Null
     * when the source itself is gone.
     */
    private function reattachRun(ImportRun $run): ?ImportRun
    {
        if ($run->getId() !== null) {
            $managed = $this->em->find(ImportRun::class, $run->getId());
            if ($managed !== null) {
                return $managed;
            }
        }

        $sourceId = $run->getSource()->getId();
        $source = $sourceId !== null ? $this->em->find(Source::class, $sourceId) : null;

        return $source !== null ? new ImportRun($source, $run->getStartedAt(), $run->isDryRun()) : null;
    }

    private function upsert(Source $source, ImportedEvent $dto, \DateTimeImmutable $now, bool $dryRun, ImportReport $report): void
    {
        if ($dto->title === '') {
            return;
        }

        // Map sub-localities / spelling variants onto the 13 Kreis municipalities
        // before it feeds the venue and the cross-source dedup key.
        $dto->city = $this->cityNormalizer->normalize($dto->city, $source->getRegion());

        // Skip a second occurrence of the same externalId within this run —
        // the first persist isn't flushed yet, so a DB lookup wouldn't see it
        // and we'd violate the unique (source, externalId) constraint.
        $runKey = $dto->externalId !== null && $dto->externalId !== ''
            ? $source->getId().':'.$dto->externalId
            : null;
        if ($runKey !== null && isset($this->newKeysThisRun[$runKey])) {
            $report->unchanged++;

            return;
        }

        $dedupKey = $dto->dedupKey();
        $hash = $dto->contentHash();
        $existing = $this->events->findForUpsert((int) $source->getId(), $dto->externalId, $dedupKey);

        if ($existing !== null && $existing->getContentHash() === $hash) {
            // Unchanged — just bump the "still alive" timestamp. The dedup key
            // is refreshed regardless: its derivation can change between
            // releases without the content hash noticing, and a stale key
            // breaks cross-source dedup for every future match.
            $existing->setLastSeenAt($now);
            $existing->setDedupKey($dedupKey);
            $this->republishIfPruned($existing);
            $report->unchanged++;

            return;
        }

        if ($dryRun) {
            $existing !== null ? $report->updated++ : $report->created++;

            return;
        }

        $event = $existing ?? new Event($dto->title, $dto->startsAt, $source);
        $isNew = $existing === null;

        // Admin-pinned fields keep their corrected value across re-imports.
        if (!$event->isFieldLocked('title')) {
            $event->setTitle(mb_substr($dto->title, 0, 300));
        }
        // Date/time can be admin-pinned too (e.g. a source lists a bogus
        // multi-month span for a single concert) — then keep the corrected value.
        if (!$event->isFieldLocked('datum')) {
            $event->setStartsAt($dto->startsAt);
            $event->setEndsAt($dto->endsAt);
            $event->setAllDay($dto->allDay);
        }
        if (!$event->isFieldLocked('description')) {
            $event->setDescription($dto->description);
        }
        $event->setLocationText($dto->locationText !== null ? mb_substr($dto->locationText, 0, 255) : null);
        $event->setRegion($source->getRegion());
        $event->setVenue($this->venues->findOrCreate($dto->venueName, $dto->city, $source->getRegion()));
        if (!$event->isFieldLocked('categories')) {
            $cats = [];
            foreach ($dto->allCategorySlugs() as $slug) {
                $category = $this->categories->findBySlug($slug);
                if ($category !== null) {
                    $cats[] = $category;
                }
            }
            $event->setCategories($cats);
        }
        $event->setSourceUrl($dto->sourceUrl !== null ? substr($dto->sourceUrl, 0, 1024) : null);
        if (!$event->isFieldLocked('imageUrl')) {
            $event->setImageUrl($dto->imageUrl !== null ? substr($dto->imageUrl, 0, 1024) : null);
        }
        $event->setPrice($dto->price !== null ? mb_substr($dto->price, 0, 120) : null);
        if (!$event->isFieldLocked('organizer')) {
            $event->setOrganizer($dto->organizer !== null ? mb_substr($dto->organizer, 0, 200) : null);
        }
        $event->setExternalId($dto->externalId);
        $event->setIsCourse($dto->isCourse || (bool) ($source->getConfig()['isCourse'] ?? false));
        $event->setBookingStatus($dto->bookingStatus);
        $event->setContentHash($hash);
        $event->setDedupKey($dedupKey);
        $event->setRaw($dto->raw);
        $event->setLastSeenAt($now);
        // Keep the existing slug when the title is pinned (it reflects the override).
        if (!$event->isFieldLocked('title') || $event->getSlug() === '') {
            $event->setSlug($this->buildSlug($dto));
        }

        if ($isNew) {
            $event->setFirstSeenAt($now);
            // Demote to a duplicate if another source already published this.
            $other = $this->events->findCrossSourceDuplicate($dedupKey, (int) $source->getId(), $source->getRegion());
            if ($other !== null) {
                $event->setStatus(EventStatus::Duplicate);
                $event->setDuplicateOf($other);
                $report->duplicates++;
            } else {
                $report->created++;
            }
            $this->em->persist($event);
            if ($runKey !== null) {
                $this->newKeysThisRun[$runKey] = true;
            }
        } else {
            $this->republishIfPruned($event);
            $report->updated++;
        }
    }

    /**
     * Undo an automatic prune ({@see \App\Command\PruneUnseenCommand}) once the
     * source lists the event again: the hide encoded "source dropped it", which
     * no longer holds. Deliberately admin-hidden events carry no prune marker
     * and stay hidden.
     */
    private function republishIfPruned(Event $event): void
    {
        if ($event->getPrunedAt() === null) {
            return;
        }
        if ($event->getStatus() === EventStatus::Hidden) {
            $event->setStatus(EventStatus::Published);
        }
        $event->setPrunedAt(null);
    }

    private function buildSlug(ImportedEvent $dto): string
    {
        $base = $this->slugger->slug(mb_substr($dto->title, 0, 80))->lower();

        return substr($dto->startsAt->format('Y-m-d').'-'.$base, 0, 300);
    }
}
