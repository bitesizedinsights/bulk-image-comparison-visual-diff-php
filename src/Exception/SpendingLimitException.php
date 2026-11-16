<?php

declare(strict_types=1);

namespace ImageComparison\Exception;

use Throwable;

/**
 * Thrown on HTTP 402: the Apify account reached its monthly usage or spending limit. Never retried.
 */
final class SpendingLimitException extends ApiException
{
    /**
     * @param  string|null  $errorType  The Apify error `type` from the response body, null when it could not be read.
     */
    public function __construct(
        string $message = 'Apify usage or spending limit reached',
        int $code = 402,
        ?Throwable $previous = null,
        ?string $runId = null,
        ?string $statusMessage = null,
        public readonly ?string $errorType = null,
    ) {
        parent::__construct($message, $code, $previous, $runId, $statusMessage);
    }
}
