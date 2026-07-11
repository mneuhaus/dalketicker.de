<?php

declare(strict_types=1);

namespace App\Ai;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Final spam gate for the contact form: asks an LLM (via OpenRouter) whether a
 * message that already passed the cheap bot filters is a genuine enquiry or
 * spam/advertising/scam. Conservative — when unsure or unreachable it treats
 * the message as legitimate, so a real person is never silently dropped.
 */
final class ContactClassifier
{
    private const ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    private const SYSTEM = <<<'TXT'
        Du bist der Spam-Filter eines Kontaktformulars von „dalketicker.de", einem
        kostenlosen Veranstaltungskalender für den Kreis Gütersloh.
        Beurteile, ob eine eingegangene Nachricht eine ECHTE Anfrage eines Menschen ist
        (Frage, Hinweis auf eine Veranstaltung/Quelle, Feedback, Korrekturwunsch, Kooperation)
        oder SPAM (SEO-/Backlink-Angebote, Werbung, Krypto/Geld, Phishing/Scam, generische
        Massen-Mails, sinnloser Text). Im Zweifel gilt die Nachricht als ECHT.
        Rufe genau einmal das Tool classify auf.
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

    /**
     * @return array{spam: bool, reason: string}
     */
    public function classify(string $name, string $email, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['spam' => false, 'reason' => ''];
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => 300,
            'tool_choice' => ['type' => 'function', 'function' => ['name' => 'classify']],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'classify',
                    'description' => 'Stuft eine Kontaktnachricht als Spam oder echt ein.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'is_spam' => ['type' => 'boolean', 'description' => 'true = Spam/Werbung/Scam, false = echte Anfrage'],
                            'reason' => ['type' => 'string', 'description' => 'Kurze Begründung (1 Satz)'],
                        ],
                        'required' => ['is_spam', 'reason'],
                    ],
                ],
            ]],
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM],
                ['role' => 'user', 'content' => sprintf("Name: %s\nE-Mail: %s\nNachricht:\n%s", $name, $email, $message)],
            ],
        ];

        try {
            $res = $this->http->request('POST', self::ENDPOINT, [
                'auth_bearer' => $this->apiKey,
                'headers' => ['Content-Type' => 'application/json', 'HTTP-Referer' => 'https://dalketicker.de', 'X-Title' => 'dalketicker'],
                'json' => $payload,
                // Runs synchronously inside the form request: keep the wait
                // short — a timeout just falls through to fail-open below.
                'timeout' => 10,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Contact spam classify failed: '.$e->getMessage());

            return ['spam' => false, 'reason' => ''];
        }

        $call = $data['choices'][0]['message']['tool_calls'][0]['function']['arguments'] ?? null;
        $args = is_string($call) ? json_decode($call, true) : null;
        if (!is_array($args) || !array_key_exists('is_spam', $args)) {
            return ['spam' => false, 'reason' => ''];
        }

        return [
            'spam' => (bool) $args['is_spam'],
            'reason' => mb_substr(trim((string) ($args['reason'] ?? '')), 0, 250),
        ];
    }
}
