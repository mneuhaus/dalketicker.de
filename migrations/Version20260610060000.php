<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Two event-lifecycle fixes:
 *
 * 1. event.pruned_at — marker set by dalketicker:prune-unseen so the importer
 *    can republish an auto-hidden event once its source lists it again
 *    (distinguishes auto-pruned from deliberately admin-hidden events).
 *
 * 2. One-time dedup_key backfill: the key derivation changed (start time for
 *    timed events, umlaut transliteration) and existing rows keep their old
 *    key forever otherwise — the importer skips unchanged events early and
 *    the key inputs are not part of the content hash. Mixed key formats break
 *    cross-source dedup (new imports no longer match old canonicals, existing
 *    duplicate pairs decay and get falsely promoted by the rededup rescue
 *    pass). The content hash itself is unaffected by the format change, so
 *    only dedup_key needs backfilling.
 *
 * The normalization below intentionally mirrors ImportedEvent::dedupKey() as
 * of this migration (copied, not referenced, so later code changes cannot
 * alter what this migration did).
 */
final class Version20260610060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Event: pruned_at marker for auto-pruned events + one-time dedup_key backfill onto the new key format (start time, umlaut transliteration).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event ADD pruned_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // The dedup_key backfill is not reversed: old keys cannot be restored
        // and the importer rewrites keys on the next run anyway.
        $this->addSql('ALTER TABLE event DROP pruned_at');
    }

    public function postUp(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.title, e.starts_at, e.all_day::int AS all_day, e.dedup_key,
                    e.location_text, v.name AS venue_name, v.city AS venue_city
               FROM event e
               LEFT JOIN venue v ON v.id = e.venue_id',
        );

        foreach ($rows as $row) {
            $startsAt = new \DateTimeImmutable((string) $row['starts_at']);
            $place = $this->resolvePlace($row, $startsAt);

            $when = ((int) $row['all_day']) === 1
                ? $startsAt->format('Y-m-d')
                : $startsAt->format('Y-m-d Hi');
            $newKey = substr(
                self::normalizeTitleNew((string) $row['title']).'|'.$when.'|'.self::normalizeNew($place),
                0,
                191,
            );

            if ($newKey !== (string) $row['dedup_key']) {
                $this->connection->executeStatement(
                    'UPDATE event SET dedup_key = :key WHERE id = :id',
                    ['key' => $newKey, 'id' => $row['id']],
                );
            }
        }
    }

    /**
     * Reconstruct the place the original key was built from. The importer used
     * city ?? venueName ?? locationText; the city ended up as venue.city when a
     * venue exists, but a missing city got the "Kreis Gütersloh" default there
     * while the key used the venue *name*. Disambiguate by recomputing the OLD
     * key per candidate and picking the one that reproduces the stored key.
     *
     * @param array<string, mixed> $row
     */
    private function resolvePlace(array $row, \DateTimeImmutable $startsAt): string
    {
        $candidates = array_values(array_unique(array_map(strval(...), array_filter(
            [$row['venue_city'] ?? null, $row['venue_name'] ?? null, $row['location_text'] ?? null, ''],
            static fn ($place) => $place !== null,
        ))));

        $titleOld = self::normalizeTitleOld((string) $row['title']);
        $day = $startsAt->format('Y-m-d'); // old keys never carried a time
        foreach ($candidates as $place) {
            if (substr($titleOld.'|'.$day.'|'.self::normalizeOld($place), 0, 191) === (string) $row['dedup_key']) {
                return $place;
            }
        }

        // No candidate reproduces the stored key (admin-edited rows etc.) —
        // best guess; the importer refreshes the key on the next sighting.
        return (string) ($row['venue_city'] ?? $row['location_text'] ?? '');
    }

    // --- New key normalization (ImportedEvent as of this migration) ---------

    private static function normalizeTitleNew(string $title): string
    {
        return self::normalizeNew(self::stripTitleDecoration($title));
    }

    private static function normalizeNew(string $value): string
    {
        $value = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß', 'ẞ'],
            ['ae', 'oe', 'ue', 'Ae', 'Oe', 'Ue', 'ss', 'ss'],
            $value,
        );

        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value))) ?? '';
    }

    // --- Old key normalization (ImportedEvent before this migration) --------

    private static function normalizeTitleOld(string $title): string
    {
        return self::normalizeOld(self::stripTitleDecoration($title));
    }

    private static function normalizeOld(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($value))) ?? '';
    }

    /** Shared title pre-clean: cut at the first spaced dash, drop parentheticals. */
    private static function stripTitleDecoration(string $title): string
    {
        $t = mb_strtolower(trim($title));
        $t = preg_replace('/\s+[–—-]\s+.*$/u', '', $t) ?? $t;

        return preg_replace('/\([^)]*\)/u', '', $t) ?? $t;
    }
}
