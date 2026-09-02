<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless anti-forgery token for the public contact form.
 *
 * The form used to carry a session-based CSRF token. Rendering that token
 * starts a session, so every visitor of /impressum got a session cookie and
 * the "no cookies on public pages" promise in the privacy notice was false.
 * Now the form carries its render timestamp plus an HMAC over it (keyed with
 * the kernel secret): nothing is stored server-side, a forged pair fails the
 * signature check, and the timestamp doubles as time-trap (a human needs a
 * few seconds to fill the form) and as expiry.
 *
 * Deliberately not bound to the client IP: mobile visitors change addresses
 * between page load and submit, and replay volume is what the rate limiter
 * and the AI classifier are for.
 */
final class ContactFormToken
{
    /** Time-trap: anything submitted faster than this was not typed by a human. */
    public const MIN_AGE_SECONDS = 3;

    /** A tab left open overnight must still work; after a day the form is stale. */
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Token for a form rendered at the given Unix timestamp. */
    public function issue(int $ts): string
    {
        return hash_hmac('sha256', 'contact|'.$ts, $this->secret);
    }

    /**
     * Hidden-field values for a form rendered right now.
     *
     * @return array{ts: int, token: string}
     */
    public function issueNow(): array
    {
        $ts = $this->clock->now()->getTimestamp();

        return ['ts' => $ts, 'token' => $this->issue($ts)];
    }

    /** Submitted before a human could have filled the form (or with a timestamp from the future). */
    public function isTooFresh(int $ts): bool
    {
        return $this->ageOf($ts) < self::MIN_AGE_SECONDS;
    }

    /** Genuine signature and inside the validity window (the time-trap included). */
    public function isValid(int $ts, string $token): bool
    {
        $age = $this->ageOf($ts);
        if ($age < self::MIN_AGE_SECONDS || $age > self::MAX_AGE_SECONDS) {
            return false;
        }

        return hash_equals($this->issue($ts), $token);
    }

    private function ageOf(int $ts): int
    {
        return $this->clock->now()->getTimestamp() - $ts;
    }
}
