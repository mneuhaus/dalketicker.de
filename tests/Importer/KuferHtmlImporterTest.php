<?php

declare(strict_types=1);

namespace App\Tests\Importer;

use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use App\Importer\ImportedEvent;
use App\Importer\KuferHtmlImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class KuferHtmlImporterTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function whenTexts(): iterable
    {
        // The year group used to be "\d{2}|\d{4}": PCRE took the first
        // alternative, read "2026" as "20" and landed the course in 2020.
        yield 'four-digit year' => ['Mi. 02.09.2026, 17.45 Uhr', '2026-09-02 17:45'];
        yield 'two-digit year' => ['Fr. 05.03.26, 19.30 Uhr', '2026-03-05 19:30'];
        yield 'colon time separator' => ['Di. 14.10.2026, 18:00 Uhr', '2026-10-14 18:00'];
        yield 'date without time' => ['Sa. 21.11.2026', '2026-11-21 00:00'];
    }

    #[DataProvider('whenTexts')]
    public function testCourseDateKeepsTheListedYear(string $when, string $expected): void
    {
        $html = <<<HTML
            <html><body>
            <div class="row kw-table-row">
              <a class="kw-kurstitel" href="/kurssuche/kurs/Yoga-fuer-Anfaenger/261-1234">
                <div>Yoga für Anfänger</div>
                <div class="kw-table-label">Wann:</div><div>{$when}</div>
                <div class="kw-table-label">Wo:</div><div>VHS-Haus</div>
                <div class="kw-table-label">Nr.:</div><div>261-1234</div>
              </a>
            </div>
            </body></html>
            HTML;

        $events = $this->import($html);

        self::assertCount(1, $events);
        self::assertSame('Yoga für Anfänger', $events[0]->title);
        self::assertSame($expected, $events[0]->startsAt->format('Y-m-d H:i'));
        self::assertSame('VHS-Haus', $events[0]->venueName);
        self::assertSame('261-1234', $events[0]->externalId);
        self::assertTrue($events[0]->isCourse);
    }

    /** @return list<ImportedEvent> */
    private function import(string $html): array
    {
        $source = new Source('vhs_re', 'VHS Reckenberg-Ems', SourceType::Html, new Region('gt', 'Dalketicker', 'Kreis Gütersloh', 'dalketicker.de'));
        $source->setUrl('https://www.vhs-re.de/programm');
        $importer = new KuferHtmlImporter(new MockHttpClient([new MockResponse($html)]));

        return iterator_to_array($importer->import($source), false);
    }
}
