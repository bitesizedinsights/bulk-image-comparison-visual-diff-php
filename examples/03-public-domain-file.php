<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

// Public domain: "The Earth seen from Apollo 17" (NASA, via Wikimedia Commons), in two sizes
$base = 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/97/The_Earth_seen_from_Apollo_17.jpg/';
$baselineUrl = $base.'500px-The_Earth_seen_from_Apollo_17.jpg';
$candidateUrl = $base.'960px-The_Earth_seen_from_Apollo_17.jpg';

// Fetch each file once and keep it in the temp directory
foreach ([$baselineUrl, $candidateUrl] as $url) {
    $localFile = sys_get_temp_dir().'/image-comparison-example-'.md5($url).'.jpg';

    if (! is_file($localFile) || filesize($localFile) === 0) {
        $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'image-comparison-php-example']]);
        $data = file_get_contents($url, false, $context);

        if ($data === false) {
            exit("Could not download {$url}\n");
        }

        file_put_contents($localFile, $data);
    }

    printf("Local copy: %s (%d KB)\n", $localFile, filesize($localFile) / 1024);
}

// The Actor reads public URLs, so send the source URLs rather than the local files
$client = (new Client)->setApifyKey($apiToken);
[$result] = $client->compare([new ImagePair($baselineUrl, $candidateUrl, 'Apollo 17')], similarityThreshold: 0.9);

if ($result->isFailed()) {
    exit("Failed: {$result->error}\n");
}

printf(
    "Passed: %s, SSIM %.4f, pHash distance %d\n",
    $result->isPassed() ? 'yes' : 'no',
    $result->ssim,
    $result->phashDistance,
);
echo "Diff image: {$result->diffImageUrl}\n";
