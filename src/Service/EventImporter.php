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
    /** Keys (source:externalId) of NEW events persisted in the current run,
     * to avoid inserting two rows that collide on the unique (source, externalId). */
    private array $newKeysThisRun = [];

    public function __construct(
        private readonly ImporterRegistry $registry,
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
        $run = new ImportRun($source, $now, $dryRun);

        try {
            $importer = $this->registry->get($source);
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
            }

            if (!$dryRun) {
                $source->setLastRunAt($now);
                $source->setLastStatus(sprintf(
                    'OK: %d gesehen, %d neu, %d aktualisiert, %d Duplikate, %d Fehler',
                    $report->seen, $report->created, $report->updated, $report->duplicates, $report->errors,
                ));
                $this->recordRun($run, $report, null);
            }
        } catch (\Throwable $e) {
            $report->fatal = $e->getMessage();
            // A failed flush closes the EM; only touch it if it's still open.
            if ($this->em->isOpen()) {
                $source->setLastRunAt($now);
                $source->setLastStatus('FEHLER: '.substr($e->getMessage(), 0, 1024));
                if (!$dryRun) {
                    $this->recordRun($run, $report, $e->getMessage());
                }
            }
            $this->logger->error('Import for source {source} failed: {error}', [
                'source' => $source->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        return $report;
    }

    /** Persist the run record + everything pending in one flush. */
    private function recordRun(ImportRun $run, ImportReport $report, ?string $fatal): void
    {
        $run->complete(
            $this->clock->now(),
            $report->seen, $report->created, $report->updated,
            $report->unchanged, $report->duplicates, $report->errors,
            $fatal,
        );
        try {
            $this->em->persist($run);
            $this->em->flush();
        } catch (\Throwable) {
            // Monitoring bookkeeping must never crash the import itself.
        }
    }

    private function upsert(Source $source, ImportedEvent $dto, \DateTimeImmutable $now, bool $dryRun, ImportReport $report): void
    {
        if ($dto->title === '') {
            return;
        }

        // Map sub-localities / spelling variants onto the 13 Kreis municipalities
        // before it feeds the venue and the cross-source dedup key.
        $dto->city = $this->cityNormalizer->normalize($dto->city);

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
            // Unchanged — just bump the "still alive" timestamp.
            $existing->setLastSeenAt($now);
            $report->unchanged++;

            return;
        }

        if ($dryRun) {
            $existing !== null ? $report->updated++ : $report->created++;

            return;
        }

        $event = $existing ?? new Event($dto->title, $dto->startsAt, $source);
        $isNew = $existing === null;

        $event->setTitle(mb_substr($dto->title, 0, 300));
        $event->setStartsAt($dto->startsAt);
        $event->setEndsAt($dto->endsAt);
        $event->setAllDay($dto->allDay);
        $event->setDescription($dto->description);
        $event->setLocationText($dto->locationText !== null ? mb_substr($dto->locationText, 0, 255) : null);
        $event->setVenue($this->venues->findOrCreate($dto->venueName, $dto->city));
        $event->setCategory($dto->categorySlug ? $this->categories->findBySlug($dto->categorySlug) : null);
        $event->setSourceUrl($dto->sourceUrl !== null ? substr($dto->sourceUrl, 0, 1024) : null);
        $event->setImageUrl($dto->imageUrl !== null ? substr($dto->imageUrl, 0, 1024) : null);
        $event->setPrice($dto->price !== null ? mb_substr($dto->price, 0, 120) : null);
        $event->setOrganizer($dto->organizer !== null ? mb_substr($dto->organizer, 0, 200) : null);
        $event->setExternalId($dto->externalId);
        $event->setIsCourse($dto->isCourse || (bool) ($source->getConfig()['isCourse'] ?? false));
        $event->setBookingStatus($dto->bookingStatus);
        $event->setContentHash($hash);
        $event->setDedupKey($dedupKey);
        $event->setRaw($dto->raw);
        $event->setLastSeenAt($now);
        $event->setSlug($this->buildSlug($dto));

        if ($isNew) {
            $event->setFirstSeenAt($now);
            // Demote to a duplicate if another source already published this.
            $other = $this->events->findCrossSourceDuplicate($dedupKey, (int) $source->getId());
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
            $report->updated++;
        }
    }

    private function buildSlug(ImportedEvent $dto): string
    {
        $base = $this->slugger->slug(mb_substr($dto->title, 0, 80))->lower();

        return substr($dto->startsAt->format('Y-m-d').'-'.$base, 0, 300);
    }
}
