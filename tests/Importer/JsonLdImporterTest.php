<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Event;
use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\JsonLdImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JsonLdImporterTest extends TestCase
{
    private function source(array $config = []): Source
    {
        $source = new Source('test_jsonld', 'Test JSON-LD', SourceType::Html, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $source->setUrl('https://example.com/programm/');
        $source->setConfig($config);

        return $source;
    }

    /** @return list<ImportedEvent> */
    private function import(string $html, array $config = []): array
    {
        $importer = new JsonLdImporter(new MockHttpClient([new MockResponse($html)]));

        return iterator_to_array($importer->import($this->source($config)), false);
    }

    public function testOccurrencesSharingOneUrlGetDistinctExternalIds(): void
    {
        $jsonLd = json_encode([
            [
                '@type' => 'TheaterEvent',
                'name' => 'Stulle, Schnaps und Patersbier',
                'startDate' => '2026-07-10T20:00:00+02:00',
                'url' => 'https://example.com/events/stulle/',
            ],
            [
                '@type' => 'TheaterEvent',
                'name' => 'Stulle, Schnaps und Patersbier',
                'startDate' => '2026-07-31T20:00:00+02:00',
                'url' => 'https://example.com/events/stulle/',
            ],
            [
                '@type' => 'MusicEvent',
                'name' => 'Konzert im Park',
                'startDate' => '2026-08-01T19:00:00+02:00',
                'url' => 'https://example.com/events/konzert/',
            ],
        ], \JSON_THROW_ON_ERROR);
        $html = '<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body></body></html>';

        $events = $this->import($html);

        self::assertCount(3, $events);
        $ids = array_map(static fn (ImportedEvent $e): ?string => $e->externalId, $events);
        self::assertSame([
            'https://example.com/events/stulle/#2026-07-10-2000',
            'https://example.com/events/stulle/#2026-07-31-2000',
            'https://example.com/events/konzert/',
        ], $ids);
    }

    public function testOverlongCollidingUrlsStayDistinctAfterExternalIdTruncation(): void
    {
        // The stored externalId is capped at Event::EXTERNAL_ID_MAX_LENGTH (191)
        // — the disambiguation suffix must survive that cap, and collisions
        // must be detected on the truncated identity.
        $url = 'https://example.com/events/'.str_repeat('x', 200).'/';
        $jsonLd = json_encode([
            [
                '@type' => 'TheaterEvent',
                'name' => 'Marathonstück',
                'startDate' => '2026-07-10T20:00:00+02:00',
                'url' => $url,
            ],
            [
                '@type' => 'TheaterEvent',
                'name' => 'Marathonstück',
                'startDate' => '2026-07-31T20:00:00+02:00',
                'url' => $url,
            ],
        ], \JSON_THROW_ON_ERROR);
        $html = '<html><head><script type="application/ld+json">'.$jsonLd.'</script></head><body></body></html>';

        $events = $this->import($html);

        self::assertCount(2, $events);
        $stored = array_map(static fn (ImportedEvent $e): ?string => Event::truncateExternalId($e->externalId), $events);
        self::assertCount(2, array_unique($stored));
        self::assertStringEndsWith('#2026-07-10-2000', (string) $stored[0]);
        self::assertStringEndsWith('#2026-07-31-2000', (string) $stored[1]);
    }

    public function testSingleOccurrenceWithoutOwnUrlKeepsDateQualifiedFallbackId(): void
    {
        $jsonLd = json_encode([
            [
                '@type' => 'Event',
                'name' => 'Lesung im Kloster',
                'startDate' => '2026-09-01T18:00:00+02:00',
            ],
        ], \JSON_THROW_ON_ERROR);
        $html = '<html><body><script type="application/ld+json">'.$jsonLd.'</script></body></html>';

        $events = $this->import($html);

        self::assertCount(1, $events);
        self::assertSame('https://example.com/programm/#2026-09-01', $events[0]->externalId);
    }

    public function testInvalidLinkPatternFailsTheRunInsteadOfMatchingNothing(): void
    {
        $html = '<html><body><a href="/events/some-event">Details</a></body></html>';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('linkPattern');

        $this->import($html, ['linkPattern' => '~[']);
    }
}
