<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
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
    public function __construct(
        private readonly ImporterRegistry $registry,
        private readonly EntityManagerInterface $em,
        private readonly EventRepository $events,
        private readonly VenueRepository $venues,
        private readonly CategoryRepository $categories,
        private readonly SluggerInterface $slugger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function import(Source $source, bool $dryRun = false): ImportReport
    {
        $report = new ImportReport($source->getKey());
        $now = $this->clock->now();

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
                $this->em->flush();
            }
        } catch (\Throwable $e) {
            $report->fatal = $e->getMessage();
            $source->setLastRunAt($now);
            $source->setLastStatus('FEHLER: '.$e->getMessage());
            if (!$dryRun) {
                $this->em->flush();
            }
            $this->logger->error('Import for source {source} failed: {error}', [
                'source' => $source->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        return $report;
    }

    private function upsert(Source $source, ImportedEvent $dto, \DateTimeImmutable $now, bool $dryRun, ImportReport $report): void
    {
        if ($dto->title === '') {
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

        $event->setTitle($dto->title);
        $event->setStartsAt($dto->startsAt);
        $event->setEndsAt($dto->endsAt);
        $event->setAllDay($dto->allDay);
        $event->setDescription($dto->description);
        $event->setLocationText($dto->locationText);
        $event->setVenue($this->venues->findOrCreate($dto->venueName, $dto->city));
        $event->setCategory($dto->categorySlug ? $this->categories->findBySlug($dto->categorySlug) : null);
        $event->setSourceUrl($dto->sourceUrl);
        $event->setImageUrl($dto->imageUrl);
        $event->setPrice($dto->price);
        $event->setOrganizer($dto->organizer);
        $event->setExternalId($dto->externalId);
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
