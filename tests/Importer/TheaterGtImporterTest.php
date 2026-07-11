<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\TheaterGtImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TheaterGtImporterTest extends TestCase
{
    public function testTitleLessTeaserIsSkippedInsteadOfAbortingTheRun(): void
    {
        // First teaser carries a date but no `.teaser-detail h3` (promo/notice
        // block) — it must be skipped, not blow up the whole source run.
        $html = <<<HTML
            <html><body>
            <div class="teaser">
              <div class="teaser-date"><span class="date">29.</span><span class="month">Mai</span></div>
              <div class="teaser-info-time">19.30</div>
            </div>
            <div class="teaser">
              <div class="teaser-date"><span class="date">30.</span><span class="month">Mai</span></div>
              <div class="teaser-info-time">20.00</div>
              <div class="teaser-info-location">Theatersaal</div>
              <div class="teaser-detail"><a href="/veranstaltung/hamlet"><h3>Hamlet</h3></a></div>
            </div>
            </body></html>
            HTML;

        $source = new Source('theater_gt', 'Theater Gütersloh', SourceType::Html, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $importer = new TheaterGtImporter(new MockHttpClient([new MockResponse($html)]));

        /** @var list<ImportedEvent> $events */
        $events = iterator_to_array($importer->import($source), false);

        self::assertCount(1, $events);
        self::assertSame('Hamlet', $events[0]->title);
        self::assertSame('https://www.theater-gt.de/veranstaltung/hamlet', $events[0]->sourceUrl);
        self::assertStringStartsWith('theater_gt:hamlet:', (string) $events[0]->externalId);
    }
}
