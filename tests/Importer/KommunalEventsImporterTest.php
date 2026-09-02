<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\KommunalEventsImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KommunalEventsImporterTest extends TestCase
{
    public function testDateOnlyEndOfATimedEventLastsUntilTheEndOfThatDay(): void
    {
        // Used to end at 00:00 of the last day with allDay=false, so the
        // relevance check dropped the event at midnight before its final day.
        $events = $this->import($this->entry('Stadtfest', 'stadtfest', '12.09.2026', '19:00', '14.09.2026'));

        self::assertCount(1, $events);
        self::assertSame('2026-09-12 19:00', $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-09-14 23:59', $events[0]->endsAt?->format('Y-m-d H:i'));
        self::assertFalse($events[0]->allDay);
    }

    public function testSameDayDateOnlyEndOfATimedEventIsDropped(): void
    {
        $events = $this->import($this->entry('Konzert', 'konzert', '12.09.2026', '19:00', '12.09.2026'));

        self::assertCount(1, $events);
        self::assertNull($events[0]->endsAt);
        self::assertFalse($events[0]->allDay);
    }

    public function testAllDayEventKeepsItsEndOfDayEnd(): void
    {
        $events = $this->import($this->entry('Ausstellung', 'ausstellung', '12.09.2026', '', '20.09.2026'));

        self::assertCount(1, $events);
        self::assertTrue($events[0]->allDay);
        self::assertSame('2026-09-20 23:59', $events[0]->endsAt?->format('Y-m-d H:i'));
    }

    private function entry(string $title, string $slug, string $fromDate, string $fromTime, string $toDate): string
    {
        $time = $fromTime !== '' ? sprintf('<span class="timeFrom">%s</span>', $fromTime) : '';

        return <<<HTML
            <html><body><ul>
            <li class="listEntryObject-eventMulti">
              <div class="listEntryDate">
                <span class="dayFrom dayDate">{$fromDate}</span>{$time}
                <span class="dayTo dayDate">{$toDate}</span>
              </div>
              <h3 class="listEntryTitle"><a href="/de/veranstaltungen/{$slug}.php">{$title}</a></h3>
              <div class="listEntryLocation">Rathausplatz</div>
            </li>
            </ul></body></html>
            HTML;
    }

    /** @return list<ImportedEvent> */
    private function import(string $html): array
    {
        $source = new Source('stadt_test', 'Stadt Test', SourceType::Html, new Region('pb', 'Paderticker', 'Kreis Paderborn', 'paderticker.de'));
        $source->setUrl('https://www.example.de/de/veranstaltungen/');
        $importer = new KommunalEventsImporter(new MockHttpClient([new MockResponse($html)]));

        return iterator_to_array($importer->import($source), false);
    }
}
