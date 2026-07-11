<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\IcsImporter;
use App\Importer\ImportedEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IcsImporterTest extends TestCase
{
    /** @return list<ImportedEvent> */
    private function import(string $ics): array
    {
        $source = new Source('test_ics', 'Test ICS', SourceType::Ics, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $source->setUrl('https://example.com/events.ics');
        $importer = new IcsImporter(new MockHttpClient([new MockResponse($ics)]));

        return iterator_to_array($importer->import($source), false);
    }

    public function testValarmPropertiesDoNotOverwriteEventProperties(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:ev-1',
            'SUMMARY:Sommerfest',
            'DESCRIPTION:Das echte Fest',
            'DTSTART:20260820T190000',
            'DTEND:20260820T220000',
            'BEGIN:VALARM',
            'ACTION:EMAIL',
            'TRIGGER:-PT15M',
            'SUMMARY:Erinnerung Alarm-Titel',
            'DESCRIPTION:Reminder text',
            'END:VALARM',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->import($ics);

        self::assertCount(1, $events);
        self::assertSame('Sommerfest', $events[0]->title);
        self::assertSame('Das echte Fest', $events[0]->description);
        self::assertSame('ev-1', $events[0]->externalId);
        self::assertArrayNotHasKey('TRIGGER', $events[0]->raw);
    }

    public function testEventPropertiesAfterAnAlarmBlockAreStillPickedUp(): void
    {
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:ev-2',
            'SUMMARY:Lesung',
            'DTSTART:20260901T180000',
            'BEGIN:VALARM',
            'ACTION:DISPLAY',
            'DESCRIPTION:Erinnerung',
            'END:VALARM',
            'LOCATION:Stadtbibliothek',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);

        $events = $this->import($ics);

        self::assertCount(1, $events);
        self::assertSame('Lesung', $events[0]->title);
        self::assertNull($events[0]->description);
        self::assertSame('Stadtbibliothek', $events[0]->venueName);
    }
}
