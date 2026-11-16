<?php

declare(strict_types=1);

namespace ImageComparison;

final readonly class Config
{
    /**
     * @param  int  $timeout  Maximum seconds to wait for a run to finish.
     * @param  int|null  $runTimeout  Apify's own time limit of the run in seconds, sent as the `timeout` query of the
     *                                run start. Null = same as `$timeout`, so the platform stops a run once the client
     *                                has given up. 0 sends nothing and keeps the Actor's own limit. Negative values
     *                                throw an `InvalidArgumentException` on `compare()`.
     * @param  int  $requestTimeout  Timeout in seconds of a single HTTP request.
     * @param  int  $maxRetries  How often a failed request is repeated (0 turns retrying off). Rate limits (429), server
     *                           errors (5xx) and connection failures are retried; see the README for the exact rules.
     * @param  float  $retryBaseDelay  Seconds before the first retry; doubles with each further retry, with jitter.
     * @param  float  $retryMaxDelay  Longest wait before a retry. A `Retry-After` above this is not waited out.
     */
    public function __construct(
        public string $actorId = 'bite-sized-insights/visual-diff-api',
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 600,
        public int $requestTimeout = 90,
        public int $maxRetries = 3,
        public float $retryBaseDelay = 1.0,
        public float $retryMaxDelay = 30.0,
        public ?int $runTimeout = null,
    ) {}
}
