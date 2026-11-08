<?php

declare(strict_types=1);

use ImageComparison\Client;
use ImageComparison\Config;
use ImageComparison\DTO\ImagePair;

/**
 * Live test against the real Apify Actor. Costs a fraction of a cent.
 *
 * Runs only with APIFY_LIVE_TEST=1 and a token in APIFY_API_TOKEN or APIFY_TOKEN.
 * Sample: "The Earth seen from Apollo 17" (NASA, public domain) at two sizes, via Wikimedia Commons.
 */
const LIVE_BASELINE_URL = 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/97/'
    .'The_Earth_seen_from_Apollo_17.jpg/500px-The_Earth_seen_from_Apollo_17.jpg';
const LIVE_CANDIDATE_URL = 'https://upload.wikimedia.org/wikipedia/commons/thumb/9/97/'
    .'The_Earth_seen_from_Apollo_17.jpg/960px-The_Earth_seen_from_Apollo_17.jpg';

function liveToken(): ?string
{
    foreach (['APIFY_API_TOKEN', 'APIFY_TOKEN'] as $name) {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function liveEnabled(): bool
{
    return getenv('APIFY_LIVE_TEST') === '1' && liveToken() !== null;
}

/**
 * Fetches a sample image once and keeps it in the system temp directory.
 */
function liveSampleFile(string $url): string
{
    $path = sys_get_temp_dir().'/image-comparison-live-'.md5($url).'.jpg';

    if (! is_file($path) || filesize($path) === 0) {
        $context = stream_context_create(['http' => ['timeout' => 60, 'user_agent' => 'image-comparison-php-tests']]);
        $data = file_get_contents($url, false, $context);

        if ($data === false || $data === '') {
            throw new RuntimeException('Could not fetch the live test sample file');
        }

        file_put_contents($path, $data);
    }

    return $path;
}

test('live: compares a real image pair', function () {
    expect(filesize(liveSampleFile(LIVE_BASELINE_URL)))->toBeGreaterThan(0)
        ->and(filesize(liveSampleFile(LIVE_CANDIDATE_URL)))->toBeGreaterThan(0);

    $token = liveToken();
    assert($token !== null);

    $client = (new Client(new Config(timeout: 600)))->setApifyKey($token);

    $result = $client->compare(
        pairs: [new ImagePair(LIVE_BASELINE_URL, LIVE_CANDIDATE_URL, 'Apollo 17')],
        similarityThreshold: 0.9,
    );

    expect($result)->toHaveCount(1);

    $comparison = $result[0];

    expect($comparison->isFailed())->toBeFalse($comparison->error ?? '')
        ->and($comparison->label)->toBe('Apollo 17')
        ->and($comparison->passed)->toBeTrue()
        ->and($comparison->ssim)->toBeGreaterThan(0.9)
        ->and($comparison->phashDistance)->toBeLessThan(8)
        ->and($comparison->pixelDiffPercent)->not->toBeNull()
        ->and($comparison->baselineSize?->width)->toBeGreaterThan(0)
        ->and($comparison->diffImageUrl)->toBeString()->toStartWith('https://');
})->skip(fn () => ! liveEnabled(), 'Set APIFY_LIVE_TEST=1 and APIFY_API_TOKEN (or APIFY_TOKEN) to run live tests');
