<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiDeduper;
use App\Ai\AiUnavailableException;
use App\Entity\Event;
use App\Entity\Region;
use App\Entity\Source;
use App\Enum\SourceType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiDeduperTest extends TestCase
{
    public function testHarvestsProposalsFromToolCalls(): void
    {
        $deduper = $this->deduper($this->response('tool_calls', [[
            'function' => ['name' => 'mark_duplicate', 'arguments' => json_encode([
                'duplicate_event_id' => 2, 'canonical_event_id' => 1, 'reason' => 'Gleiches Event.',
            ])],
        ]]));

        $proposals = $deduper->findDuplicates(new \DateTimeImmutable('2026-08-01'), [$this->event(1, 'Repair-Café'), $this->event(2, 'Reparatur-Café')]);

        self::assertSame([['duplicate' => 2, 'canonical' => 1, 'reason' => 'Gleiches Event.']], $proposals);
    }

    public function testNoToolCallsMeansNoDuplicatesAndDoesNotThrow(): void
    {
        $deduper = $this->deduper($this->response('stop', []));

        $proposals = $deduper->findDuplicates(new \DateTimeImmutable('2026-08-01'), [$this->event(1, 'A'), $this->event(2, 'B')]);

        self::assertSame([], $proposals);
    }

    public function testTruncatedResponseThrowsSoTheDayIsNotFingerprinted(): void
    {
        $deduper = $this->deduper($this->response('length', []));

        $this->expectException(AiUnavailableException::class);
        $deduper->findDuplicates(new \DateTimeImmutable('2026-08-01'), [$this->event(1, 'A'), $this->event(2, 'B')]);
    }

    private function deduper(MockResponse $response): AiDeduper
    {
        return new AiDeduper(new MockHttpClient([$response]), new NullLogger(), 'test-key', 'test-model');
    }

    /** @param list<array{function: array{name: string, arguments: string|false}}> $toolCalls */
    private function response(string $finishReason, array $toolCalls): MockResponse
    {
        return new MockResponse((string) json_encode([
            'choices' => [[
                'finish_reason' => $finishReason,
                'message' => ['tool_calls' => $toolCalls],
            ]],
        ]), ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']]);
    }

    private function event(int $id, string $title): Event
    {
        $region = new Region('guetersloh', 'Dalketicker', 'Gütersloh', 'dalketicker.de');
        $source = new Source('test', 'Test', SourceType::Manual, $region);
        $event = new Event($title, new \DateTimeImmutable('2026-08-01 20:00'), $source);
        new \ReflectionProperty(Event::class, 'id')->setValue($event, $id);

        return $event;
    }
}
