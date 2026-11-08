<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;
use ImageComparison\Normalization;
use ImageComparison\ResizeMode;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

$results = $client->compare(
    pairs: [
        new ImagePair('https://example.com/photo-original.jpg', 'https://example.com/photo-resized.jpg', 'Resized'),
        new ImagePair('https://example.com/photo-original.jpg', 'https://example.com/other-photo.jpg', 'Different'),
    ],
    // Scale the candidate to the baseline size, then compare local structure
    resizeMode: ResizeMode::CandidateToBaseline,
    normalize: Normalization::Autocontrast,
    // The overlay is not needed to find duplicates
    createDiffImage: false,
);

foreach ($results as $result) {
    if ($result->isFailed()) {
        continue;
    }

    // Hash distances range from 0 (identical) to 64 and tolerate resizing and re-compression
    $duplicate = $result->phashDistance !== null && $result->phashDistance <= 5;

    printf("%s: pHash %d, aHash %d, dHash %d -> %s\n", $result->label, $result->phashDistance, $result->ahashDistance,
        $result->dhashDistance, $duplicate ? 'near-duplicate' : 'different');
}
