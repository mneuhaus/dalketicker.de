<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\StadtGtImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class StadtGtImporterTest extends TestCase
{
    public function testEntryWhoseDetailPageFailsIsSkippedInsteadOfYieldedBare(): void
    {
        // Yielding the entry without venue/description would change its content
        // hash and downgrade the stored row until the next run.
        $list = <<<HTML
            <html><body><ul>
            <li class="listEntry">
              <h3 class="listEntryTitle"><a href="/de/veranstaltungen/stadtfest.php?from=2026-09-12%2019:00:00&amp;to=2026-09-12%2022:00:00">Stadtfest</a></h3>
            </li>
            <li class="listEntry">
              <h3 class="listEntryTitle"><a href="/de/veranstaltungen/sommerkonzert.php?from=2026-09-13%2020:00:00&amp;to=2026-09-13%2022:00:00">Sommerkonzert</a></h3>
            </li>
            </ul></body></html>
            HTML;
        $detail = <<<HTML
            <html><head><meta name="Description" content="Open-Air im Park."></head><body>
            <h1>Sommerkonzert im Stadtpark</h1>
            <div class="elementObjectEventMultiLocation"><p><strong>Stadtpark</strong></p><p>Parkstraße 1, 33330 Gütersloh</p></div>
            </body></html>
            HTML;

        $source = new Source('stadt_gt', 'Stadt Gütersloh', SourceType::Html, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $source->setUrl('https://www.guetersloh.de/de/veranstaltungen/');
        $importer = new StadtGtImporter(new MockHttpClient([
            new MockResponse($list),
            new MockResponse('', ['http_code' => 500]),
            new MockResponse($detail),
        ]));

        /** @var list<ImportedEvent> $events */
        $events = iterator_to_array($importer->import($source), false);

        self::assertCount(1, $events);
        self::assertSame('Sommerkonzert im Stadtpark', $events[0]->title);
        self::assertSame('Stadtpark', $events[0]->venueName);
        self::assertSame('Open-Air im Park.', $events[0]->description);
        self::assertSame('stadt_gt:sommerkonzert:2026-09-13T20:00', $events[0]->externalId);
    }
}
