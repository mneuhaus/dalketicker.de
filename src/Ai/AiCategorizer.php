<?php

declare(strict_types=1);

namespace App\Ai;

use App\Entity\Event;
use App\Repository\CategoryRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks an LLM (via OpenRouter, OpenAI-compatible API) to classify events into
 * our fixed category set. Mirrors {@see AiDeduper}: tool calling, the model
 * never writes to the DB — it just returns proposals, so a dry-run is trivial.
 *
 * A batch of events is sent in one request; the model calls classify_event once
 * per event with a primary (and optional secondary) category slug.
 */
final class AiCategorizer
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly CategoryRepository $categories,
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
     * @return array{proposals: array<int, array{slugs: list<string>, reason: string}>, usage: array<string, int>}
     */
    public function classify(array $events): array
    {
        if (!$this->isConfigured() || $events === []) {
            return ['proposals' => [], 'usage' => []];
        }

        // Allowed categories straight from the catalogue (slug => name), so the
        // model can only ever pick a real slug.
        $allowed = [];
        foreach ($this->categories->findAllOrdered() as $category) {
            $allowed[$category->getSlug()] = $category->getName();
        }
        $slugs = array_keys($allowed);

        $catalogue = [];
        foreach ($allowed as $slug => $name) {
            $catalogue[] = "- $slug: $name";
        }

        $lines = [];
        foreach ($events as $e) {
            $desc = $e->getDescription() ? mb_substr(preg_replace('/\s+/', ' ', strip_tags($e->getDescription())) ?? '', 0, 240) : '';
            $lines[] = sprintf(
                'id=%d | "%s" | Ort: %s | Veranstalter: %s%s',
                $e->getId(),
                $e->getTitle(),
                $e->getDisplayLocation() ?: '—',
                $e->getOrganizer() ?: '—',
                $desc !== '' ? ' | '.$desc : '',
            );
        }

        $system = <<<TXT
            Du ordnest Veranstaltungen im Kreis Gütersloh in Kategorien ein.
            Wähle für JEDES Event genau EINE Hauptkategorie (primary), die am besten passt,
            und optional EINE zweite Kategorie (secondary), nur wenn sie klar ebenfalls zutrifft.
            Wähle ausschließlich aus diesen Slugs:
            {$this->bullets($catalogue)}
            Regeln:
            - Nutze "sonstiges" NUR, wenn wirklich keine andere Kategorie passt.
            - Kurse/Seminare/Vorträge → meist "bildung".
            - Floh-/Wochen-/Weihnachtsmärkte, Feste, Kirmes → "markt".
            - Theater/Lesung/Kabarett/Comedy → "buehne"; Konzerte/Live-Musik → "musik".
            - Für JEDES Event genau einen Aufruf von classify_event.
            TXT;

        $payload = [
            'model' => $this->model,
            'max_tokens' => 4096,
            'tool_choice' => 'auto',
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'classify_event',
                    'description' => 'Ordnet ein Event einer Haupt- und optional einer Zweitkategorie zu.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'event_id' => ['type' => 'integer'],
                            'primary' => ['type' => 'string', 'enum' => $slugs, 'description' => 'Wichtigste Kategorie'],
                            'secondary' => ['type' => 'string', 'enum' => $slugs, 'description' => 'Optionale zweite Kategorie'],
                            'reason' => ['type' => 'string', 'description' => 'Kurze Begründung (1 Satz)'],
                        ],
                        'required' => ['event_id', 'primary'],
                    ],
                ],
            ]],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Ordne diese Veranstaltungen ein:\n".implode("\n", $lines)],
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
            $this->logger->error('AI categorize request failed: '.$e->getMessage());

            return ['proposals' => [], 'usage' => []];
        }

        if (isset($data['error'])) {
            $this->logger->error('AI categorize API error', ['error' => $data['error']]);

            return ['proposals' => [], 'usage' => []];
        }

        $proposals = [];
        foreach ($data['choices'][0]['message']['tool_calls'] ?? [] as $call) {
            if (($call['function']['name'] ?? null) !== 'classify_event') {
                continue;
            }
            $args = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
            if (!\is_array($args)) {
                continue;
            }
            $id = (int) ($args['event_id'] ?? 0);
            $primary = \in_array($args['primary'] ?? '', $slugs, true) ? $args['primary'] : null;
            if ($id <= 0 || $primary === null) {
                continue;
            }
            $picked = [$primary];
            $secondary = $args['secondary'] ?? null;
            if (\is_string($secondary) && $secondary !== $primary && \in_array($secondary, $slugs, true)) {
                $picked[] = $secondary;
            }
            $proposals[$id] = ['slugs' => $picked, 'reason' => trim((string) ($args['reason'] ?? ''))];
        }

        return ['proposals' => $proposals, 'usage' => $data['usage'] ?? []];
    }

    /** @param list<string> $items */
    private function bullets(array $items): string
    {
        return implode("\n", $items);
    }
}
