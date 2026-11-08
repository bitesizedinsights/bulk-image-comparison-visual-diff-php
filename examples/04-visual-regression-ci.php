<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Screenshots of the same pages before and after a deploy, uploaded to public URLs
$pages = ['home', 'pricing', 'checkout'];
$pairs = array_map(
    static fn (string $page): ImagePair => new ImagePair(
        "https://example.com/screenshots/before/{$page}.png",
        "https://example.com/screenshots/after/{$page}.png",
        $page,
    ),
    $pages,
);

$exitCode = 0;

foreach ($client->compare($pairs, similarityThreshold: 0.99) as $result) {
    if ($result->isPassed()) {
        continue;
    }

    $exitCode = 1;
    $largest = $result->getLargestRegion();

    echo "{$result->label}: ".($result->error ?? 'visual change')."\n";

    if ($largest !== null) {
        echo "  largest change at {$largest->x},{$largest->y} ({$largest->width}x{$largest->height})\n";
        echo "  see {$result->diffImageUrl}\n";
    }
}

// A non-zero exit code fails the CI step
exit($exitCode);
