<?php

declare(strict_types=1);

namespace ImageComparison;

use Closure;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use ImageComparison\DTO\ComparisonResult;
use ImageComparison\DTO\ImagePair;
use ImageComparison\DTO\Region;
use ImageComparison\Exception\ApiException;
use ImageComparison\Exception\MissingApiKeyException;
use ImageComparison\Exception\RateLimitException;
use InvalidArgumentException;
use JsonException;
use Throwable;

final class Client
{
    private const MIN_PAIRS = 1;

    private const MAX_PAIRS = 500;

    private const MAX_IGNORE_REGIONS = 100;

    /** Largest x, y, width or height the Actor accepts for an ignore region. */
    private const MAX_REGION_VALUE = 100_000;

    private const FAILED_STATUSES = ['FAILED', 'ABORTED', 'TIMED-OUT'];

    private const RUNNING_STATUSES = ['READY', 'RUNNING'];

    /** Longest wait Apify allows per request. */
    private const MAX_WAIT_SECONDS = 60;

    private readonly Config $config;

    private readonly ClientInterface $http;

    private ?string $apiKey = null;

    /** @var Closure(float): void */
    private readonly Closure $sleeper;

    /**
     * @param  (Closure(float): void)|null  $sleeper  Waits the given seconds between retries; replaced in tests.
     */
    public function __construct(?Config $config = null, ?ClientInterface $http = null, ?Closure $sleeper = null)
    {
        $this->config = $config ?? new Config;
        $this->http = $http ?? new HttpClient;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * Set the Apify API key. Without it, `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment is used.
     */
    public function setApifyKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Compare image pairs and return one result per pair, in input order.
     *
     * @param  list<ImagePair>  $pairs  1 to 500 baseline and candidate pairs.
     * @param  float  $similarityThreshold  A pair passes at this SSIM score or higher, 0 to 1.
     * @param  float|null  $maxDiffPercent  Also fail when more than this percentage of pixels changed, 0 to 100.
     * @param  int  $pixelTolerance  Per-channel colour difference still treated as unchanged, 0 to 255.
     * @param  ResizeMode  $resizeMode  Scale the candidate to the baseline size or report a size mismatch.
     * @param  int  $maxDimension  Larger images are scaled down to fit, 16 to 4000 pixels.
     * @param  Normalization  $normalize  Optionally ignore colour or contrast differences.
     * @param  list<Region>  $ignoreRegions  Areas in baseline pixels skipped for every pair, max 100.
     * @param  bool  $createDiffImage  Also return a public link to a PNG overlay of the changes.
     * @param  int|null  $runTimeout  Apify run time limit in seconds for this call: overrides `Config::$runTimeout`
     *                                (default: `Config::$timeout`); 0 keeps the Actor's own limit.
     * @return list<ComparisonResult>
     *
     * @throws InvalidArgumentException When an argument violates the Actor's input limits, before any request.
     * @throws ApiException
     * @throws RateLimitException
     */
    public function compare(
        array $pairs,
        float $similarityThreshold = 0.98,
        ?float $maxDiffPercent = null,
        int $pixelTolerance = 10,
        ResizeMode $resizeMode = ResizeMode::CandidateToBaseline,
        int $maxDimension = 1600,
        Normalization $normalize = Normalization::None,
        array $ignoreRegions = [],
        bool $createDiffImage = true,
        ?int $runTimeout = null,
    ): array {
        $runTimeout ??= $this->config->runTimeout;

        if ($runTimeout !== null && $runTimeout < 0) {
            throw new InvalidArgumentException(
                "runTimeout must be 0 or a positive number of seconds, got {$runTimeout}."
            );
        }

        $this->validate($pairs, $similarityThreshold, $maxDiffPercent, $pixelTolerance, $maxDimension, $ignoreRegions);

        $input = [
            'pairs' => array_map(static fn (ImagePair $pair): array => $pair->toArray(), $pairs),
            'similarityThreshold' => $similarityThreshold,
            'pixelTolerance' => $pixelTolerance,
            'resizeMode' => $resizeMode->value,
            'maxDimension' => $maxDimension,
            'normalize' => $normalize->value,
            'createDiffImage' => $createDiffImage,
        ];

        if ($maxDiffPercent !== null) {
            $input['maxDiffPercent'] = $maxDiffPercent;
        }

        if ($ignoreRegions !== []) {
            $input['ignoreRegions'] = array_map(static fn (Region $r): array => $r->toArray(), $ignoreRegions);
        }

        $run = $this->startRun($input, $runTimeout ?? $this->config->timeout);
        $run = $this->awaitRun($run);

        return $this->fetchResults($this->datasetId($run));
    }

    /**
     * Mirror the Actor's input schema limits so mistakes surface before a run is started.
     *
     * @param  array<mixed>  $pairs
     * @param  array<mixed>  $ignoreRegions
     *
     * @throws InvalidArgumentException
     */
    private function validate(
        array $pairs,
        float $similarityThreshold,
        ?float $maxDiffPercent,
        int $pixelTolerance,
        int $maxDimension,
        array $ignoreRegions,
    ): void {
        $count = count($pairs);

        if ($count < self::MIN_PAIRS || $count > self::MAX_PAIRS) {
            throw new InvalidArgumentException(
                'pairs must contain between '.self::MIN_PAIRS.' and '.self::MAX_PAIRS." image pairs, got {$count}."
            );
        }

        if (! is_finite($similarityThreshold) || $similarityThreshold < 0 || $similarityThreshold > 1) {
            throw new InvalidArgumentException(
                "similarityThreshold must be between 0 and 1, got {$similarityThreshold}."
            );
        }

        if ($maxDiffPercent !== null
            && (! is_finite($maxDiffPercent) || $maxDiffPercent < 0 || $maxDiffPercent > 100)) {
            throw new InvalidArgumentException("maxDiffPercent must be between 0 and 100, got {$maxDiffPercent}.");
        }

        if ($pixelTolerance < 0 || $pixelTolerance > 255) {
            throw new InvalidArgumentException("pixelTolerance must be between 0 and 255, got {$pixelTolerance}.");
        }

        if ($maxDimension < 16 || $maxDimension > 4000) {
            throw new InvalidArgumentException("maxDimension must be between 16 and 4000, got {$maxDimension}.");
        }

        $this->validateRegions($ignoreRegions, 'ignoreRegions');
        $runRegions = count($ignoreRegions);

        foreach (array_values($pairs) as $i => $pair) {
            if (! $pair instanceof ImagePair) {
                throw new InvalidArgumentException("pairs[{$i}] must be an ImagePair.");
            }

            $this->validateRegions($pair->ignoreRegions, "pairs[{$i}].ignoreRegions");
            $total = $runRegions + count($pair->ignoreRegions);

            if ($total > self::MAX_IGNORE_REGIONS) {
                throw new InvalidArgumentException(
                    "pairs[{$i}] has {$total} ignore regions (run level plus pair level), "
                    .'at most '.self::MAX_IGNORE_REGIONS.' are allowed.'
                );
            }
        }
    }

    /**
     * @param  array<mixed>  $regions
     *
     * @throws InvalidArgumentException
     */
    private function validateRegions(array $regions, string $name): void
    {
        if (count($regions) > self::MAX_IGNORE_REGIONS) {
            throw new InvalidArgumentException(
                "{$name} may contain at most ".self::MAX_IGNORE_REGIONS.' regions, got '.count($regions).'.'
            );
        }

        foreach (array_values($regions) as $i => $region) {
            if (! $region instanceof Region) {
                throw new InvalidArgumentException("{$name}[{$i}] must be a Region.");
            }

            $values = ['x' => $region->x, 'y' => $region->y, 'width' => $region->width, 'height' => $region->height];

            foreach ($values as $key => $value) {
                if ($value < 0 || $value > self::MAX_REGION_VALUE) {
                    throw new InvalidArgumentException(
                        "{$name}[{$i}].{$key} must be between 0 and ".self::MAX_REGION_VALUE.", got {$value}."
                    );
                }
            }
        }
    }

    /**
     * Aborts an Apify run (POST /actor-runs/{id}/abort). Safe to call for a run that already finished.
     *
     * @throws ApiException
     * @throws RateLimitException
     * @throws MissingApiKeyException
     */
    public function abort(string $runId): void
    {
        $this->request('POST', '/actor-runs/'.rawurlencode($runId).'/abort');
    }

    /**
     * Best-effort abort after our own deadline expired: one short request, no retries, never throws.
     */
    private function tryAbort(string $runId): bool
    {
        try {
            $this->http->request(
                'POST',
                rtrim($this->config->baseUrl, '/').'/actor-runs/'.rawurlencode($runId).'/abort',
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->resolveApiKey(),
                        'Accept' => 'application/json',
                    ],
                    'timeout' => min(10, $this->config->requestTimeout),
                ],
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<mixed>
     */
    private function startRun(array $input, int $runTimeout): array
    {
        $actor = str_replace('/', '~', $this->config->actorId);
        $options = ['json' => $input];

        if ($runTimeout > 0) {
            $options['query'] = ['timeout' => $runTimeout];
        }

        $result = $this->request('POST', "/acts/{$actor}/runs", $options);

        return $this->runData($result);
    }

    /**
     * @param  array<mixed>  $run
     * @return array<mixed>
     */
    private function awaitRun(array $run): array
    {
        $deadline = time() + $this->config->timeout;

        while (true) {
            $status = $run['status'] ?? null;

            if ($status === 'SUCCEEDED') {
                return $run;
            }

            if (is_string($status) && in_array($status, self::FAILED_STATUSES, true)) {
                $detail = $this->statusMessage($run);
                $message = "Actor run ended with status {$status}";

                throw new ApiException(
                    $detail === null ? $message : "{$message}: ".$this->shorten($detail, 500),
                    runId: is_string($run['id'] ?? null) ? $run['id'] : null,
                    statusMessage: $detail,
                );
            }

            if (! is_string($status) || ! in_array($status, self::RUNNING_STATUSES, true)) {
                throw new ApiException('Invalid API response: missing or unknown run status');
            }

            $remaining = $deadline - time();

            $runId = $run['id'] ?? null;

            if (! is_string($runId)) {
                throw new ApiException('Invalid API response: missing run ID');
            }

            if ($remaining <= 0) {
                $message = "Actor run did not finish within {$this->config->timeout} seconds";

                throw new ApiException(
                    $this->tryAbort($runId) ? "{$message} and was aborted" : $message,
                    runId: $runId,
                    statusMessage: $this->statusMessage($run),
                );
            }

            $result = $this->request('GET', "/actor-runs/{$runId}", [
                'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
            ]);
            $run = $this->runData($result);
        }
    }

    /**
     * The run's statusMessage without control characters, capped at 2000 characters; null if absent or empty.
     *
     * @param  array<mixed>  $run
     */
    private function statusMessage(array $run): ?string
    {
        $message = $run['statusMessage'] ?? null;

        if (! is_string($message)) {
            return null;
        }

        $message = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message));

        return $message === '' ? null : $this->shorten($message, 2000);
    }

    private function shorten(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }

    /**
     * @param  array<mixed>  $run
     */
    private function datasetId(array $run): string
    {
        $datasetId = $run['defaultDatasetId'] ?? null;

        if (! is_string($datasetId) || $datasetId === '') {
            throw new ApiException('Invalid API response: missing dataset ID');
        }

        return $datasetId;
    }

    /**
     * @return list<ComparisonResult>
     */
    private function fetchResults(string $datasetId): array
    {
        $items = $this->request('GET', "/datasets/{$datasetId}/items", ['query' => ['format' => 'json']]);

        $results = [];

        foreach (array_values($items) as $index => $item) {
            try {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Item must be an object');
                }

                $results[] = ComparisonResult::fromArray($item);
            } catch (InvalidArgumentException $e) {
                throw new ApiException("Invalid dataset item at index {$index}: ".$e->getMessage(), 0, $e);
            }
        }

        return $results;
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>
     */
    private function runData(array $result): array
    {
        $data = $result['data'] ?? null;

        if (! is_array($data)) {
            throw new ApiException('Invalid API response: missing run data');
        }

        return $data;
    }

    /**
     * @throws MissingApiKeyException
     */
    private function resolveApiKey(): string
    {
        $key = $this->apiKey ?: (getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN'));

        if (! is_string($key) || $key === '') {
            throw new MissingApiKeyException;
        }

        return $key;
    }

    /**
     * Sends a request and retries what is safe to repeat: rate limits (429, the request was not processed), server
     * errors (5xx) and connection failures. Starting a run (POST) is only repeated after a 429 or a failed
     * connection, because after a 5xx or a timeout the run may already exist and a repeat would bill it twice.
     *
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
        $options['timeout'] = $this->config->requestTimeout;

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->http->request($method, rtrim($this->config->baseUrl, '/').$path, $options);
                $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
            } catch (BadResponseException $e) {
                $status = $e->getResponse()->getStatusCode();
                $retryable = $status === 429 || ($status >= 500 && $method !== 'POST');
                $delay = $retryable && $attempt < $this->config->maxRetries
                    ? $this->retryDelay($attempt, $status === 429 ? $this->retryAfter($e) : null)
                    : null;

                if ($delay === null) {
                    $this->handleBadResponse($e, $attempt);
                }

                ($this->sleeper)($delay);

                continue;
            } catch (JsonException $e) {
                throw new ApiException('Invalid API response: malformed JSON', 0, $e);
            } catch (GuzzleException $e) {
                // A failed connection never reached the server, so it is safe to repeat even for POST.
                $retryable = $e instanceof ConnectException || $method !== 'POST';

                if ($retryable && $attempt < $this->config->maxRetries) {
                    ($this->sleeper)($this->retryDelay($attempt, null) ?? 0.0);

                    continue;
                }

                throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
            }

            if (! is_array($decoded)) {
                throw new ApiException('Invalid API response: unexpected JSON');
            }

            return $decoded;
        }
    }

    /**
     * Seconds to wait before the next retry: exponential from the base delay with 50 to 100 percent jitter, at least
     * the server's `Retry-After`. Null when the server asks for a longer wait than the configured maximum.
     */
    private function retryDelay(int $attempt, ?int $retryAfter): ?float
    {
        if ($retryAfter !== null && $retryAfter > $this->config->retryMaxDelay) {
            return null;
        }

        $jittered = $this->backoff($attempt) * (random_int(500, 1000) / 1000);

        return max($jittered, (float) ($retryAfter ?? 0));
    }

    private function backoff(int $attempt): float
    {
        return min($this->config->retryMaxDelay, $this->config->retryBaseDelay * (2 ** $attempt));
    }

    /**
     * The `Retry-After` header in whole seconds, from its delay-seconds or HTTP-date form. Null when missing or invalid.
     */
    private function retryAfter(BadResponseException $e): ?int
    {
        $value = trim($e->getResponse()->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    private function handleBadResponse(BadResponseException $e, int $attempt): never
    {
        $response = $e->getResponse();

        if ($response->getStatusCode() === 429) {
            $retryAfter = $this->retryAfter($e) ?? (int) ceil($this->backoff($attempt));

            throw new RateLimitException('Rate limit exceeded', $retryAfter);
        }

        throw new ApiException(
            "API request failed with status {$response->getStatusCode()}: ".$e->getMessage(),
            $response->getStatusCode(),
            $e,
        );
    }
}
