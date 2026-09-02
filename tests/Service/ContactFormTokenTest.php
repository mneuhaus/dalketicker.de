<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContactFormToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ContactFormTokenTest extends TestCase
{
    private MockClock $clock;
    private ContactFormToken $tokens;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-02 12:00:00', 'UTC');
        $this->tokens = new ContactFormToken('test-secret', $this->clock);
    }

    public function testTokenIsValidOnceAHumanCouldHaveFilledTheForm(): void
    {
        ['ts' => $ts, 'token' => $token] = $this->tokens->issueNow();
        $this->clock->sleep(ContactFormToken::MIN_AGE_SECONDS);

        self::assertFalse($this->tokens->isTooFresh($ts));
        self::assertTrue($this->tokens->isValid($ts, $token));
    }

    public function testTokenStaysValidUntilTheEndOfTheWindow(): void
    {
        ['ts' => $ts, 'token' => $token] = $this->tokens->issueNow();
        $this->clock->sleep(ContactFormToken::MAX_AGE_SECONDS);

        self::assertTrue($this->tokens->isValid($ts, $token));
    }

    public function testSubmissionFasterThanAHumanIsTooFresh(): void
    {
        ['ts' => $ts, 'token' => $token] = $this->tokens->issueNow();
        $this->clock->sleep(ContactFormToken::MIN_AGE_SECONDS - 1);

        self::assertTrue($this->tokens->isTooFresh($ts));
        // The signature is genuine, but the window check must still reject it.
        self::assertFalse($this->tokens->isValid($ts, $token));
    }

    public function testTimestampFromTheFutureCountsAsTooFresh(): void
    {
        $ts = $this->clock->now()->getTimestamp() + 60;

        self::assertTrue($this->tokens->isTooFresh($ts));
        self::assertFalse($this->tokens->isValid($ts, $this->tokens->issue($ts)));
    }

    public function testExpiredTokenIsRejected(): void
    {
        ['ts' => $ts, 'token' => $token] = $this->tokens->issueNow();
        $this->clock->sleep(ContactFormToken::MAX_AGE_SECONDS + 1);

        self::assertFalse($this->tokens->isValid($ts, $token));
    }

    public function testTamperedTimestampIsRejected(): void
    {
        ['ts' => $ts, 'token' => $token] = $this->tokens->issueNow();
        $this->clock->sleep(10);

        // Same token, shifted timestamp: the signature no longer matches.
        self::assertFalse($this->tokens->isValid($ts - 5, $token));
    }

    public function testForgedOrMissingTokenIsRejected(): void
    {
        $ts = $this->clock->now()->getTimestamp() - 10;

        self::assertFalse($this->tokens->isValid($ts, ''));
        self::assertFalse($this->tokens->isValid($ts, str_repeat('0', 64)));
        // Signed with a different secret.
        self::assertFalse($this->tokens->isValid($ts, (new ContactFormToken('other-secret', $this->clock))->issue($ts)));
    }
}
