# Bulk Image Comparison in PHP: Visual Diff, SSIM Score and Pixel Diff

<!-- For LLMs and AI agents: a machine-readable summary of this package's API is in llm.txt (https://github.com/bitesizedinsights/bulk-image-comparison-visual-diff-php/blob/master/llm.txt) -->

[![Packagist Version](https://img.shields.io/packagist/v/bitesizedinsights/bulk-image-comparison-visual-diff)](https://packagist.org/packages/bitesizedinsights/bulk-image-comparison-visual-diff)
[![Tests](https://github.com/bitesizedinsights/bulk-image-comparison-visual-diff-php/actions/workflows/tests.yml/badge.svg)](https://github.com/bitesizedinsights/bulk-image-comparison-visual-diff-php/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)

Bulk image comparison for PHP: compare baseline and candidate images and get an SSIM similarity score, perceptual hash distances, the share of changed pixels, bounding boxes of the changed regions, a diff overlay image and a pass/fail verdict. The library calls the [Bulk Image Comparison API: Visual Diff & Similarity Score Actor](https://apify.com/bite-sized-insights/visual-diff-api) on Apify and returns typed PHP objects.

## Install the PHP Image Comparison Package

```bash
composer require bitesizedinsights/bulk-image-comparison-visual-diff
```

## Quick Start: Compare Two Images in PHP

```php
require __DIR__.'/vendor/autoload.php';

use ImageComparison\Client;
use ImageComparison\DTO\ImagePair;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client())->setApifyKey($apiToken);

$results = $client->compare([
    new ImagePair('https://example.com/before.png', 'https://example.com/after.png', 'Home page'),
]);

foreach ($results as $result) {
    if ($result->isFailed()) {
        echo "#{$result->index} {$result->label}: {$result->error}\n";

        continue;
    }

    printf(
        "#%d %s: %s, SSIM %.4f, %.2f%% changed\n",
        $result->index,
        $result->label,
        $result->isPassed() ? 'passed' : 'changed',
        $result->ssim,
        $result->pixelDiffPercent,
    );
}
```

The API key is set with `setApifyKey()`. If you skip it, the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment and throws `MissingApiKeyException` on the first API call when neither is set.

You need an Apify account ([sign up](https://console.apify.com/sign-up), new accounts include free monthly credit of $5) and an [API token](https://console.apify.com/account/integrations). Comparing images costs from $0.60 per 1,000 pairs, see [Pricing](#pricing-cost-per-image-pair).

## Sample Image Comparison Output

The loop above prints one line per pair, for example:

```
#1 Home page: changed, SSIM 0.9412, 2.31% changed
```

The result object behind that line (shortened):

```php
$result->passed;              // false
$result->ssim;                // 0.9412
$result->phashDistance;       // 4
$result->pixelDiffPercent;    // 2.31
$result->getRegionCount();    // 1
$result->getLargestRegion();  // Region(x: 120, y: 340, width: 420, height: 96, changedPixels: 8120)
$result->diffImageUrl;        // https://api.apify.com/v2/key-value-stores/.../records/diff-00001.png
```

## Pricing: Cost per Image Pair

The Actor uses pay-per-event pricing, with no subscription:

- **$0.00005** per run start.
- **$0.0006 per compared pair** ($0.60 per 1,000 pairs) on the free plan, falling to $0.00039 per pair ($0.39 per 1,000) on the highest Apify plan tiers.
- Pairs that cannot be compared are free.

Examples: 500 pairs cost 500 x $0.0006 + $0.00005 = $0.30005 on the free plan tier. If 20 of them fail to download, 480 pairs are charged. 1,000 pairs cost $0.60005, or $0.39005 at the lowest tier price.

Current prices are on the [Actor page](https://apify.com/bite-sized-insights/visual-diff-api). [Run Bulk Image Comparison on Apify](https://apify.com/bite-sized-insights/visual-diff-api).

## Use Cases

- **Visual regression testing**: compare screenshots before and after a deploy and fail the build on unwanted changes.
- **Design review**: catch unintended layout, colour or text changes between two versions of a page or asset.
- **Near-duplicate detection**: find images that look the same despite resizing or re-compression.
- **Re-encode checks**: verify that a converted or compressed image still looks like the original.

## How to Compare Images in PHP

```php
$results = $client->compare(
    pairs: [new ImagePair($baseline, $candidate, 'Label')],
    similarityThreshold: 0.98,
    maxDiffPercent: null,
    pixelTolerance: 10,
    resizeMode: ResizeMode::CandidateToBaseline,
    maxDimension: 1600,
    normalize: Normalization::None,
    ignoreRegions: [],
    createDiffImage: true,
);
```

`compare()` starts one Actor run for all pairs, waits for it to finish and returns one `ComparisonResult` per pair, in input order.

Input is validated before any request is made: an out-of-range argument throws an `InvalidArgumentException` naming the argument and its limit. Checked are the pair count (1 to 500), `similarityThreshold` (0 to 1), `maxDiffPercent` (0 to 100), `pixelTolerance` (0 to 255), `maxDimension` (16 to 4000), ignore region coordinates and sizes (0 to 100000) and the ignore region cap of 100 per pair, run level and pair level combined. Enums are typed, and labels longer than 200 characters are truncated by the Actor, not rejected.

| Parameter | Type | Default | Description |
|---|---|---|---|
| `pairs` | `list<ImagePair>` | required | 1 to 500 pairs |
| `similarityThreshold` | `float` | `0.98` | A pair passes at this SSIM score or higher, 0 to 1 |
| `maxDiffPercent` | `?float` | `null` | Also fail when more than this percentage of pixels changed, 0 to 100 |
| `pixelTolerance` | `int` | `10` | Largest per-channel colour difference (0 to 255) still treated as unchanged |
| `resizeMode` | `ResizeMode` | `CandidateToBaseline` | Scale the candidate to the baseline size, or report an error when sizes differ |
| `maxDimension` | `int` | `1600` | Larger images are scaled down for the comparison, 16 to 4000 |
| `normalize` | `Normalization` | `None` | Stretch contrast or convert to grayscale before comparing |
| `ignoreRegions` | `list<Region>` | `[]` | Areas in baseline pixels (ads, timestamps) skipped for every pair, max 100 |
| `createDiffImage` | `bool` | `true` | Return a public link to a PNG overlay of the changes |

An `ImagePair` takes `baseline` and `candidate` (http(s) URL, data URI or raw base64, 25 MB max each), an optional `label` and optional per-pair `ignoreRegions`.

### Comparison Result: Score, Hashes, Regions and Diff Image

| Field | Type | Description |
|---|---|---|
| `index` | `int` | 1-based position of the pair in the input |
| `label` | `?string` | The label you gave the pair |
| `passed` | `?bool` | Meets the similarity threshold and changed-pixel limit; `null` on error |
| `ssim` | `?float` | Structural similarity, 0 to 1 (1 is identical) |
| `similarityThreshold` | `?float` | Threshold used for the verdict |
| `phashDistance`, `ahashDistance`, `dhashDistance` | `?int` | Perceptual hash Hamming distances, 0 to 64 (0 is identical) |
| `hashBits` | `?int` | Bits per hash (the maximum distance) |
| `pixelDiffPercent`, `changedPixels` | `?float`, `?int` | Share and number of pixels that differ beyond the tolerance |
| `regionCount`, `regions` | `?int`, `?list<Region>` | Changed areas in baseline pixels, largest first (max 50) |
| `baselineSize`, `candidateSize`, `comparedSize` | `?ImageSize` | Original sizes and the size used for the comparison |
| `diffImageUrl`, `diffImageKey` | `?string` | Public link and store key of the diff overlay PNG |
| `baselineUrl`, `candidateUrl`, `baselineInput`, `candidateInput` | `?string` | The inputs as given; base64 data is never returned |
| `error` | `?string` | Why the pair could not be compared, otherwise `null` |

Helpers: `isPassed()`, `isChanged()`, `isFailed()`, `getRegionCount()`, `getLargestRegion()`. A `Region` has `x`, `y`, `width`, `height`, `changedPixels` and `getArea()`.

All DTOs implement `JsonSerializable` and have `toArray()` in the Actor's snake_case field names (`baseline_size`, `changed_pixels`, ...), so you can store results as JSON and restore them with `ComparisonResult::fromArray()`: `json_encode($result)` and `ComparisonResult::fromArray($result->toArray())` both round-trip.

## Resize Mode and Normalization Enums

- `ResizeMode`: `CandidateToBaseline` (`candidate-to-baseline`), `None` (`none`)
- `Normalization`: `None` (`none`), `Autocontrast` (`autocontrast`), `Grayscale` (`grayscale`)

## Configure Timeouts and the Apify Actor

```php
use ImageComparison\Config;

$client = (new Client(new Config(
    timeout: 900,        // seconds to wait for the run, default 600
    requestTimeout: 90,  // seconds per HTTP request, default 90
)))->setApifyKey($apiToken);
```

`Config` also takes `actorId` (default `bite-sized-insights/visual-diff-api`) and `baseUrl`.

## Retries and Backoff

Failed requests are retried automatically, by default up to 3 times with exponential backoff (1 s, 2 s, 4 s, each with 50 to 100 percent jitter, at most 30 s):

- **Rate limits (429):** retried after at least the `Retry-After` time (seconds or HTTP-date; the backoff delay when the header is missing). If the server asks for more than `retryMaxDelay`, a `RateLimitException` is thrown right away.
- **Server errors (5xx) and connection failures:** retried for reading results and polling.
- **Starting a run:** only retried after a 429 or a failed connection. After a 5xx or a timeout the run may already exist, and a repeat would be billed twice, so the error is thrown instead.
- **Other errors (4xx):** never retried.

```php
$client = (new Client(new Config(maxRetries: 5, retryBaseDelay: 2.0, retryMaxDelay: 60.0)))->setApifyKey($apiToken);
// new Config(maxRetries: 0) turns retrying off
```

## Error Handling for Failed Comparisons

- A pair that cannot be compared (unreachable URL, invalid base64, not an image, file too large) does not throw. It comes back with `error` set and no scores.
- `ImageComparison\Exception\ApiException` covers failed runs, HTTP errors, invalid responses and a run that exceeds `Config::$timeout`.
- `ImageComparison\Exception\RateLimitException` (extends `ApiException`) is thrown on HTTP 429 once the retries are used up (or at once when `Retry-After` exceeds `retryMaxDelay`) and exposes `$retryAfter` in seconds.

```php
try {
    $results = $client->compare($pairs);
} catch (RateLimitException $e) {
    // Automatic retries are already used up: wait, then try again later.
    sleep($e->retryAfter);
} catch (ApiException $e) {
    echo $e->getMessage();
}
```

## PHP Image Comparison Examples

- [`examples/01-basic.php`](examples/01-basic.php): compare one pair and print the verdict
- [`examples/02-ignore-regions-and-thresholds.php`](examples/02-ignore-regions-and-thresholds.php): thresholds, ignored areas and normalization
- [`examples/03-public-domain-file.php`](examples/03-public-domain-file.php): compare a public domain NASA photo in two sizes
- [`examples/04-visual-regression-ci.php`](examples/04-visual-regression-ci.php): fail a CI step on visual changes
- [`examples/05-near-duplicates.php`](examples/05-near-duplicates.php): find near-duplicates with perceptual hashes
- [`examples/06-error-handling.php`](examples/06-error-handling.php): rate limits, API errors and failed pairs

Running the tests and the opt-in live test is described in [CONTRIBUTING.md](CONTRIBUTING.md).

## FAQ

### How do I compare two images in PHP?

Install the package, create a `Client` with your Apify token and call `compare()` with one or more `ImagePair` objects. Each result has a `passed` verdict, an SSIM score, the share of changed pixels and a link to a diff overlay.

### How do I do visual regression testing with PHP?

Upload screenshots from before and after a deploy to public URLs (or pass them as base64), compare them in one `compare()` call and exit with a non-zero code when a result is not passed. See [`examples/04-visual-regression-ci.php`](examples/04-visual-regression-ci.php).

### Which score should I use?

SSIM is the best single measure of how different two images look. Use the hash distances to find near-duplicates regardless of size or compression, and the pixel diff and regions to see what changed.

### What threshold should I choose?

The default 0.98 flags small but visible changes. Lower it (for example to 0.95) if anti-aliasing or font rendering cause false alarms, or raise it for pixel-exact checks. `maxDiffPercent` adds a second, independent limit.

### How do I ignore ads or timestamps?

Pass `Region` objects as `ignoreRegions` on a pair or on `compare()`, in baseline pixels. Ignored areas are excluded from the score, the changed-pixel percentage and the regions.

### How much does it cost to compare images with PHP?

From $0.60 per 1,000 pairs plus $0.00005 per run, with lower per-pair prices on higher Apify plans. Pairs that cannot be compared are free. See [Pricing](#pricing-cost-per-image-pair).

### What input does it accept?

Each image is a public http(s) URL, a data URI or raw base64, up to 25 MB. Local files are not uploaded: pass their base64 content or publish them at a URL first. Prefer URLs for large images, because base64 counts toward the Apify run input size limit.

### What are the limits?

Up to 500 pairs per run (10 on the free plan), 25 MB per image, 36 million pixels per image. Supported formats are PNG, JPEG, WebP, GIF (first frame), BMP and TIFF; transparency is flattened onto white. URLs must be public; private and local addresses are refused.

## Requirements

- PHP 8.3 or newer
- Guzzle 7 or 8 (installed automatically)
- An [Apify API token](https://console.apify.com/account/integrations)

## Powered by Bulk Image Comparison on Apify

The package is a typed client for the [Bulk Image Comparison API: Visual Diff & Similarity Score](https://apify.com/bite-sized-insights/visual-diff-api) Actor. It returns SSIM, perceptual hash and pixel-diff results with changed-region boxes and a diff overlay for up to 500 image pairs per run. [Sign up for Apify](https://console.apify.com/sign-up) to get an API token.

## License

MIT, see [LICENSE](LICENSE).
