<?php

declare(strict_types=1);

namespace ImageComparison;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use ImageComparison\DTO\ComparisonResult;
use ImageComparison\DTO\ImagePair;
use ImageComparison\DTO\Region;
use ImageComparison\Exception\ApiException;
use ImageComparison\Exception\RateLimitException;
use InvalidArgumentException;
use JsonException;

final class Client
{
    private const FAILED_STATUSES = ['FAILED', 'ABORTED', 'TIMED-OUT'];

    private const RUNNING_STATUSES = ['READY', 'RUNNING'];

    /** Longest wait Apify allows per request. */
    private const MAX_WAIT_SECONDS = 60;

    private readonly Config $config;

    private readonly ClientInterface $http;

    public function __construct(string $apiToken, ?Config $config = null, ?ClientInterface $http = null)
    {
        $this->config = $config ?? new Config($apiToken);
        $this->http = $http ?? new HttpClient;
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
     * @return list<ComparisonResult>
     *
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
    ): array {
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

        $run = $this->startRun($input);
        $run = $this->awaitRun($run);

        return $this->fetchResults($this->datasetId($run));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<mixed>
     */
    private function startRun(array $input): array
    {
        $actor = str_replace('/', '~', $this->config->actorId);
        $result = $this->request('POST', "/acts/{$actor}/runs", ['json' => $input]);

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
                throw new ApiException("Actor run ended with status {$status}");
            }

            if (! is_string($status) || ! in_array($status, self::RUNNING_STATUSES, true)) {
                throw new ApiException('Invalid API response: missing or unknown run status');
            }

            $remaining = $deadline - time();

            if ($remaining <= 0) {
                throw new ApiException("Actor run did not finish within {$this->config->timeout} seconds");
            }

            $runId = $run['id'] ?? null;

            if (! is_string($runId)) {
                throw new ApiException('Invalid API response: missing run ID');
            }

            $result = $this->request('GET', "/actor-runs/{$runId}", [
                'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
            ]);
            $run = $this->runData($result);
        }
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
     * @param  array<string, mixed>  $options
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->config->apiToken,
            'Accept' => 'application/json',
        ];
        $options['timeout'] = $this->config->requestTimeout;

        try {
            $response = $this->http->request($method, rtrim($this->config->baseUrl, '/').$path, $options);
            $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (BadResponseException $e) {
            $this->handleBadResponse($e);
        } catch (JsonException $e) {
            throw new ApiException('Invalid API response: malformed JSON', 0, $e);
        } catch (GuzzleException $e) {
            throw new ApiException('API request failed: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($decoded)) {
            throw new ApiException('Invalid API response: unexpected JSON');
        }

        return $decoded;
    }

    private function handleBadResponse(BadResponseException $e): never
    {
        $response = $e->getResponse();

        if ($response->getStatusCode() === 429) {
            $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: 60);

            throw new RateLimitException('Rate limit exceeded', $retryAfter);
        }

        throw new ApiException(
            "API request failed with status {$response->getStatusCode()}: ".$e->getMessage(),
            $response->getStatusCode(),
            $e,
        );
    }
}
