<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;
use ImageComparison\DTO\Region;
use ImageComparison\Normalization;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

$results = $client->compare(
    pairs: [
        // Skip the ad banner at the top of this page only
        new ImagePair(
            'https://example.com/before.png',
            'https://example.com/after.png',
            'Home page',
            ignoreRegions: [new Region(x: 0, y: 0, width: 300, height: 90)],
        ),
        new ImagePair('https://example.com/pricing-before.png', 'https://example.com/pricing-after.png', 'Pricing'),
    ],
    // Pass at SSIM 0.95 and fail anyway when more than 1% of the pixels changed
    similarityThreshold: 0.95,
    maxDiffPercent: 1.0,
    // Ignore colour and contrast shifts
    normalize: Normalization::Grayscale,
    // Skip a cookie banner on every pair
    ignoreRegions: [new Region(x: 0, y: 700, width: 1280, height: 100)],
);

foreach ($results as $result) {
    echo "{$result->label}: ".($result->isPassed() ? 'passed' : 'not passed')."\n";
}
