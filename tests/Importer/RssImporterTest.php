<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\RssImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RssImporterTest extends TestCase
{
    private function source(): Source
    {
        $source = new Source('test_rss', 'Test RSS', SourceType::Rss, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $source->setUrl('https://example.com/feed.xml');

        return $source;
    }

    /** @return list<ImportedEvent> */
    private function import(string $body): array
    {
        $importer = new RssImporter(new MockHttpClient([new MockResponse($body)]));

        return iterator_to_array($importer->import($this->source()), false);
    }

    public function testUnparseableFeedThrowsInsteadOfYieldingNothing(): void
    {
        $html = "<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body><p>Wartungsmodus<br></body></html>";

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be parsed');

        $this->import($html);
    }

    public function testValidFeedYieldsEventWithExplicitDate(): void
    {
        $feed = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Test</title>
            <item>
              <title>Sommerkonzert</title>
              <link>https://example.com/sommerkonzert</link>
              <description>Datum: 20.08.2026, 19:30 Uhr</description>
            </item>
            </channel></rss>
            XML;

        $events = $this->import($feed);

        self::assertCount(1, $events);
        self::assertSame('Sommerkonzert', $events[0]->title);
        self::assertSame('2026-08-20 19:30', $events[0]->startsAt->format('Y-m-d H:i'));
    }

    public function testOffsetLessPubDateIsInterpretedInBerlinTimeRegardlessOfDefaultTimezone(): void
    {
        $feed = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel><title>Test</title>
            <item>
              <title>Vortrag ohne Terminangabe</title>
              <link>https://example.com/vortrag</link>
              <description>Kein Termin im Text</description>
              <pubDate>2026-08-20T19:30:00</pubDate>
            </item>
            </channel></rss>
            XML;

        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $events = $this->import($feed);
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertCount(1, $events);
        self::assertSame('Europe/Berlin', $events[0]->startsAt->getTimezone()->getName());
        self::assertSame('2026-08-20 19:30', $events[0]->startsAt->format('Y-m-d H:i'));
    }
}
