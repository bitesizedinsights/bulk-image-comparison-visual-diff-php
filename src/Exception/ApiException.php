<?php

declare(strict_types=1);

namespace ImageComparison\Exception;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /**
     * @param  string|null  $runId  ID of the Apify run the error belongs to, when known (e.g. a run that hit the timeout).
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $runId = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
