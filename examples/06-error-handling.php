<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;
use ImageComparison\Exception\ApiException;
use ImageComparison\Exception\RateLimitException;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

try {
    $results = $client->compare([
        new ImagePair('https://example.com/before.png', 'https://example.com/after.png', 'Good pair'),
        new ImagePair('https://example.com/before.png', 'https://example.com/missing.png', 'Missing candidate'),
    ]);
} catch (RateLimitException $e) {
    // Wait the advised time before retrying
    sleep($e->retryAfter);

    exit("Rate limited, retry later\n");
} catch (ApiException $e) {
    exit("API error: {$e->getMessage()}\n");
}

// A single bad pair does not throw: it comes back with an error message and no scores
foreach ($results as $result) {
    if ($result->isFailed()) {
        echo "#{$result->index} {$result->label}: failed ({$result->error})\n";

        continue;
    }

    echo "#{$result->index} {$result->label}: SSIM {$result->ssim}\n";
}
