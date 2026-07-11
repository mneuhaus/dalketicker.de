<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\KlosterDalheimImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KlosterDalheimImporterTest extends TestCase
{
    public function testLinkLessCardIsToleratedInsteadOfAbortingTheRun(): void
    {
        $html = <<<HTML
            <html><body>
            <div class="event-element">
              <div class="event-title">Hinweis ohne Link</div>
              <div class="event-date">05.09.2026</div>
            </div>
            <div class="event-element">
              <div class="event-title">Konzert im Kloster</div>
              <div class="event-date">06.09.2026</div>
              <div class="event-time">15:00 Uhr</div>
              <a href="/veranstaltung?id=42">mehr</a>
            </div>
            </body></html>
            HTML;

        $source = new Source('kloster_dalheim', 'Kloster Dalheim', SourceType::Html, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $source->setUrl('https://www.kloster-dalheim.lwl.org/de/kalender/');
        $source->setConfig(['fetchDetails' => false]);
        $importer = new KlosterDalheimImporter(new MockHttpClient([new MockResponse($html)]));

        /** @var list<ImportedEvent> $events */
        $events = iterator_to_array($importer->import($source), false);

        self::assertCount(2, $events);
        self::assertSame('Hinweis ohne Link', $events[0]->title);
        self::assertNull($events[0]->sourceUrl);
        self::assertSame('Konzert im Kloster', $events[1]->title);
        self::assertSame('https://www.kloster-dalheim.lwl.org/veranstaltung?id=42', $events[1]->sourceUrl);
    }
}
