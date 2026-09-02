<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Event;
use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\ImporterRegistry;
use App\Repository\CategoryRepository;
use App\Repository\EventRepository;
use App\Repository\VenueRepository;
use App\Service\CityNormalizer;
use App\Service\EventImporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Re-import behaviour of {@see EventImporter::upsert} for an existing event
 * whose upstream content changed — what survives, what gets refreshed.
 */
final class EventImporterTest extends TestCase
{
    private Source $source;

    /** @var array<string, Category> */
    private array $categories;

    protected function setUp(): void
    {
        $region = new Region('guetersloh', 'Dalketicker', 'Gütersloh', 'dalketicker.de');
        $this->source = new Source('test', 'Test', SourceType::Manual, $region);
        $this->categories = [
            'musik' => new Category('Musik', 'musik'),
            'sonstiges' => new Category('Sonstiges', 'sonstiges'),
        ];
    }

    public function testAiAppliedCategoriesSurviveAContentChangedReimport(): void
    {
        $existing = $this->existingEvent('Alter Text');
        $existing->setCategories([$this->categories['musik']]); // set by the nightly AI pass

        $this->importer($existing, aiDecisions: 1)
            ->upsertOne($this->source, $this->dto('Neuer Text', categorySlug: 'sonstiges'));

        self::assertSame(['musik'], $this->slugsOf($existing), 'the importer guess must not win back over the AI verdict');
        self::assertSame('Neuer Text', $existing->getDescription(), 'everything else still updates');
    }

    public function testImporterCategoriesStillWinWithoutAnAiDecision(): void
    {
        $existing = $this->existingEvent('Alter Text');
        $existing->setCategories([$this->categories['musik']]);

        $this->importer($existing, aiDecisions: 0)
            ->upsertOne($this->source, $this->dto('Neuer Text', categorySlug: 'sonstiges'));

        self::assertSame(['sonstiges'], $this->slugsOf($existing));
    }

    public function testChangedDescriptionDropsTheStaleTeaser(): void
    {
        $existing = $this->existingEvent('Alter Text');
        $existing->setSummary('Teaser zum alten Text.');

        $this->importer($existing)->upsertOne($this->source, $this->dto('Neuer Text'));

        self::assertNull($existing->getSummary());
    }

    public function testUnchangedDescriptionKeepsTheTeaser(): void
    {
        $existing = $this->existingEvent('Gleicher Text');
        $existing->setSummary('Teaser.');

        // Something else changed (the price) — the text the teaser describes did not.
        $this->importer($existing)->upsertOne($this->source, $this->dto('Gleicher Text', price: '12 €'));

        self::assertSame('Teaser.', $existing->getSummary());
    }

    public function testTimestampsAreNormalizedToBerlinBeforeStorage(): void
    {
        $existing = $this->existingEvent('Text');
        // An importer handing over UTC (e.g. an ICS with a foreign TZID):
        // 18:00Z is 20:00 in Berlin — the stored wall-clock must say 20:00.
        $utc = new ImportedEvent(
            title: 'Konzert',
            startsAt: new \DateTimeImmutable('2026-09-10 18:00', new \DateTimeZone('UTC')),
            endsAt: new \DateTimeImmutable('2026-09-10 20:00', new \DateTimeZone('UTC')),
            externalId: 'ext-1',
        );

        $this->importer($existing)->upsertOne($this->source, $utc);

        self::assertSame('2026-09-10 20:00 Europe/Berlin', $existing->getStartsAt()->format('Y-m-d H:i e'));
        self::assertSame('2026-09-10 22:00 Europe/Berlin', $existing->getEndsAt()?->format('Y-m-d H:i e'));
    }

    private function existingEvent(string $description): Event
    {
        $event = new Event('Konzert', $this->start(), $this->source);
        $event->setDescription($description);
        $event->setExternalId('ext-1');

        return $event;
    }

    private function dto(?string $description, ?string $categorySlug = null, ?string $price = null): ImportedEvent
    {
        return new ImportedEvent(
            title: 'Konzert',
            startsAt: $this->start(),
            description: $description,
            categorySlug: $categorySlug,
            price: $price,
            externalId: 'ext-1',
        );
    }

    private function start(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-10 20:00', new \DateTimeZone('Europe/Berlin'));
    }

    private function importer(Event $existing, int $aiDecisions = 0): EventImporter
    {
        $events = $this->createStub(EventRepository::class);
        $events->method('findForUpsert')->willReturn($existing);

        $venues = $this->createStub(VenueRepository::class);
        $venues->method('findOrCreate')->willReturn(null);

        $categories = $this->createStub(CategoryRepository::class);
        $categories->method('findBySlug')->willReturnCallback(fn (string $slug): ?Category => $this->categories[$slug] ?? null);

        $decisions = $this->createStub(EntityRepository::class);
        $decisions->method('count')->willReturn($aiDecisions);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($decisions);
        $em->method('isOpen')->willReturn(true);

        return new EventImporter(
            new \ReflectionClass(ImporterRegistry::class)->newInstanceWithoutConstructor(),
            $this->createStub(ManagerRegistry::class),
            $em,
            $events,
            $venues,
            $categories,
            new AsciiSlugger(),
            new MockClock('2026-09-01 12:00:00'),
            new NullLogger(),
            new CityNormalizer(),
        );
    }

    /** @return list<string> */
    private function slugsOf(Event $event): array
    {
        $slugs = [];
        foreach ($event->getCategories() as $category) {
            $slugs[] = $category->getSlug();
        }

        return $slugs;
    }
}
