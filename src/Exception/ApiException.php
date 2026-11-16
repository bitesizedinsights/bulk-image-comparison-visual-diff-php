<?php

declare(strict_types=1);

namespace ImageComparison\Exception;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /**
     * @param  string|null  $runId  ID of the Apify run the error belongs to, when known (e.g. a run that hit the timeout).
     * @param  string|null  $statusMessage  The run's own statusMessage (the Actor's reason), capped at 2000 characters.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $runId = null,
        public readonly ?string $statusMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
