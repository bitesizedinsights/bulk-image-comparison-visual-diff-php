<?php

declare(strict_types=1);

namespace ImageComparison;

final readonly class Config
{
    /**
     * @param  int  $timeout  Maximum seconds to wait for a run to finish.
     * @param  int  $requestTimeout  Timeout in seconds of a single HTTP request.
     */
    public function __construct(
        public string $actorId = 'bite-sized-insights/visual-diff-api',
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 600,
        public int $requestTimeout = 90,
    ) {}
}
