<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks an LLM (via OpenRouter) for a short 1–2 sentence teaser per event,
 * derived from its original description — shown in the list view. Mirrors
 * {@see AiCategorizer}: tool calling, never writes the DB, batch per request.
 */
final class AiSummarizer
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    private const SYSTEM = <<<'TXT'
        Du schreibst für einen Veranstaltungskalender (Kreis Gütersloh) eine sehr kurze,
        sachliche Vorschau aus dem Originaltext einer Veranstaltung.
        Regeln:
        - 1 bis maximal 2 Sätze, höchstens ~220 Zeichen.
        - Sachlich, neutral, deutsch; keine Werbe-Floskeln, keine Ausrufezeichen-Ketten.
        - Nur Inhalte aus dem Text – nichts erfinden, keine Wiederholung des reinen Titels.
        - Keine Datums-/Uhrzeit-/Preis-/Ortsangaben (stehen schon woanders).
        Rufe für JEDES Event genau einmal das Tool summarize auf.
        TXT;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(OPENROUTER_API_KEY)%')] private readonly string $apiKey,
        #[Autowire('%env(DEDUP_AI_MODEL)%')] private readonly string $model,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * @param Event[] $events
     *
     * @return array{summaries: array<int, string>, usage: array<string, int>}
     */
    public function summarize(array $events): array
    {
        if (!$this->isConfigured() || $events === []) {
            return ['summaries' => [], 'usage' => []];
        }

        $lines = [];
        foreach ($events as $e) {
            $text = mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) $e->getDescription())) ?? '', 0, 1200);
            $lines[] = sprintf('id=%d | Titel: "%s" | Text: %s', $e->getId(), $e->getTitle(), $text);
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'tool_choice' => 'auto',
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'summarize',
                    'description' => 'Kurze 1–2-Satz-Vorschau für ein Event.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'integer'],
                            'summary' => ['type' => 'string', 'description' => '1–2 Sätze, max ~220 Zeichen'],
                        ],
                        'required' => ['event_id', 'summary'],
                    ],
                ],
            ]],
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => "Fasse diese Veranstaltungen kurz zusammen:\n".implode("\n", $lines)],
            ],
        ];

        try {
            $res = $this->http->request('POST', self::ENDPOINT, [
                'auth_bearer' => $this->apiKey,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => 'https://dalketicker.de',
                    'X-Title' => 'dalketicker',
                ],
                'json' => $payload,
                'timeout' => 90,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('AI summarize request failed: '.$e->getMessage());

            return ['summaries' => [], 'usage' => []];
        }

        if (isset($data['error'])) {
            $this->logger->error('AI summarize API error', ['error' => $data['error']]);

            return ['summaries' => [], 'usage' => []];
        }

        $summaries = [];
        foreach ($data['choices'][0]['message']['tool_calls'] ?? [] as $call) {
            if (($call['function']['name'] ?? null) !== 'summarize') {
                continue;
            }
            $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            if (!\is_array($args)) {
                continue;
            }
            $id = (int) ($args['event_id'] ?? 0);
            $summary = trim((string) ($args['summary'] ?? ''));
            if ($id > 0 && $summary !== '') {
                $summaries[$id] = mb_substr($summary, 0, 400);
            }
        }

        return ['summaries' => $summaries, 'usage' => $data['usage'] ?? []];
    }
}
