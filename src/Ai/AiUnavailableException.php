<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Thrown when the AI API is unreachable or answers with an error (transport
 * failure, timeout, HTTP >= 400, error payload). Callers abort the current run
 * WITHOUT marking the batch as processed — already stored batches stay saved,
 * the rest is retried on the next run. Distinct from "API answered fine but
 * gave no proposal", which is recorded as skipped/done by the commands.
 */
final class AiUnavailableException extends \RuntimeException
{
}
