<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = new Client($apiToken);

$results = $client->compare([
    new ImagePair('https://example.com/before.png', 'https://example.com/after.png', 'Home page'),
]);

foreach ($results as $result) {
    if ($result->isFailed()) {
        echo "#{$result->index} {$result->label}: failed ({$result->error})\n";

        continue;
    }

    printf(
        "#%d %s: %s, SSIM %.4f, %.2f%% of pixels changed, %d region(s)\n",
        $result->index,
        $result->label,
        $result->isPassed() ? 'passed' : 'changed',
        $result->ssim,
        $result->pixelDiffPercent,
        $result->getRegionCount(),
    );
}
