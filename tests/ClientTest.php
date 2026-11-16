<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use ImageComparison\Client;
use ImageComparison\Config;
use ImageComparison\DTO\ImagePair;
use ImageComparison\DTO\Region;
use ImageComparison\Exception\ApiException;
use ImageComparison\Exception\MissingApiKeyException;
use ImageComparison\Exception\RateLimitException;
use ImageComparison\Exception\SpendingLimitException;
use ImageComparison\Normalization;
use ImageComparison\ResizeMode;
use Psr\Http\Message\RequestInterface;

/** @return array<string, mixed> */
function sentBody(RequestInterface $request): array
{
    /** @var array<string, mixed> */
    return json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
}

function samplePair(): ImagePair
{
    return new ImagePair('https://example.com/before.png', 'https://example.com/after.png');
}

test('compare runs the actor and maps the dataset', function () {
    $history = [];
    $client = makeClient([
        runResponse(),
        jsonResponse(200, [loadFixture('comparison_changed'), loadFixture('comparison_failed')]),
    ], $history);

    $result = $client->compare([samplePair(), new ImagePair('https://example.com/a.png', 'https://example.com/x.png')]);

    expect($result)->toHaveCount(2)
        ->and($result[0]->isChanged())->toBeTrue()
        ->and($result[1]->isFailed())->toBeTrue()
        ->and($history)->toHaveCount(2);

    $post = $history[0]['request'];
    expect($post->getMethod())->toBe('POST')
        ->and($post->getUri()->getPath())->toBe('/v2/acts/bite-sized-insights~visual-diff-api/runs')
        ->and($post->getHeaderLine('Authorization'))->toBe('Bearer test-token')
        ->and(sentBody($post))->toBe([
            'pairs' => [
                ['baseline' => 'https://example.com/before.png', 'candidate' => 'https://example.com/after.png'],
                ['baseline' => 'https://example.com/a.png', 'candidate' => 'https://example.com/x.png'],
            ],
            'similarityThreshold' => 0.98,
            'pixelTolerance' => 10,
            'resizeMode' => 'candidate-to-baseline',
            'maxDimension' => 1600,
            'normalize' => 'none',
            'createDiffImage' => true,
        ]);

    $get = $history[1]['request'];
    expect($get->getMethod())->toBe('GET')
        ->and($get->getUri()->getPath())->toBe('/v2/datasets/ds1/items')
        ->and($get->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('compare sends all options', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->compare(
        pairs: [new ImagePair('a', 'b', 'Home', [new Region(0, 0, 300, 90)])],
        similarityThreshold: 0.95,
        maxDiffPercent: 5.5,
        pixelTolerance: 20,
        resizeMode: ResizeMode::None,
        maxDimension: 800,
        normalize: Normalization::Grayscale,
        ignoreRegions: [new Region(10, 20, 30, 40)],
        createDiffImage: false,
    );

    expect(sentBody($history[0]['request']))->toBe([
        'pairs' => [[
            'baseline' => 'a',
            'candidate' => 'b',
            'label' => 'Home',
            'ignoreRegions' => [['x' => 0, 'y' => 0, 'width' => 300, 'height' => 90]],
        ]],
        'similarityThreshold' => 0.95,
        'pixelTolerance' => 20,
        'resizeMode' => 'none',
        'maxDimension' => 800,
        'normalize' => 'grayscale',
        'createDiffImage' => false,
        'maxDiffPercent' => 5.5,
        'ignoreRegions' => [['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40]],
    ]);
});

test('optional input keys are omitted when unset', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);

    $client->compare([samplePair()]);

    expect(sentBody($history[0]['request']))->not->toHaveKeys(['maxDiffPercent', 'ignoreRegions']);
});

test('empty dataset gives an empty list', function () {
    $client = makeClient([runResponse(), jsonResponse(200, [])]);

    expect($client->compare([samplePair()]))->toBe([]);
});

test('waits for a running run until it succeeds', function () {
    $history = [];
    $client = makeClient([
        runResponse('RUNNING', 'run42'),
        runResponse('RUNNING', 'run42'),
        runResponse('SUCCEEDED', 'run42'),
        jsonResponse(200, [loadFixture('comparison_passed')]),
    ], $history);

    $result = $client->compare([samplePair()]);

    expect($result)->toHaveCount(1)
        ->and($result[0]->isPassed())->toBeTrue()
        ->and($history)->toHaveCount(4)
        ->and($history[1]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run42')
        ->and($history[1]['request']->getUri()->getQuery())->toStartWith('waitForFinish=');
});

test('throws when the run does not finish in time', function () {
    $client = makeClient([runResponse('RUNNING')], config: new Config(timeout: 0));

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'did not finish within 0 seconds');
});

test('aborts the run when the deadline expires', function () {
    $history = [];
    $client = makeClient([runResponse('RUNNING', 'run42'), jsonResponse(200, [])], $history, new Config(timeout: 0));

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toBe('Actor run did not finish within 0 seconds and was aborted')
            ->and($e->runId)->toBe('run42');
    }

    expect($history)->toHaveCount(2);
    $abort = $history[1]['request'];
    expect($abort->getMethod())->toBe('POST')
        ->and($abort->getUri()->getPath())->toBe('/v2/actor-runs/run42/abort')
        ->and($abort->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('a failing abort still throws the original timeout error', function () {
    $history = [];
    $client = makeClient([
        runResponse('RUNNING', 'run42'),
        jsonResponse(500, []),
    ], $history, new Config(timeout: 0));

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toBe('Actor run did not finish within 0 seconds')
            ->and($e->runId)->toBe('run42');
    }

    expect($history)->toHaveCount(2);
});

test('no abort on success or failed runs', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, new Config(timeout: 0));
    $client->compare([samplePair()]);
    expect($history)->toHaveCount(2);

    $history = [];
    $client = makeClient([runResponse('FAILED')], $history, new Config(timeout: 0));
    expect(fn () => $client->compare([samplePair()]))->toThrow(ApiException::class, 'status FAILED')
        ->and($history)->toHaveCount(1);
});

test('abort() posts to the abort endpoint', function () {
    $history = [];
    $client = makeClient([runResponse('ABORTED', 'run7')], $history);

    $client->abort('run7');

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run7/abort')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('throws on failed run status', function (string $status) {
    $client = makeClient([runResponse($status)]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, "status {$status}");
})->with(['FAILED', 'ABORTED', 'TIMED-OUT']);

/** @param  array<string, mixed>  $extra */
function failedRun(array $extra): Response
{
    return jsonResponse(200, ['data' => ['id' => 'run9', 'status' => 'FAILED'] + $extra]);
}

test('failed run exposes the statusMessage in message and property', function () {
    $client = makeClient([failedRun(['statusMessage' => 'Target site returned 403'])]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toBe('Actor run ended with status FAILED: Target site returned 403')
            ->and($e->statusMessage)->toBe('Target site returned 403')
            ->and($e->runId)->toBe('run9');
    }
});

test('failed run without a usable statusMessage keeps the plain message', function (array $extra) {
    $client = makeClient([failedRun($extra)]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toBe('Actor run ended with status FAILED')
            ->and($e->statusMessage)->toBeNull();
    }
})->with([
    'missing' => [[]],
    'empty string' => [['statusMessage' => '']],
    'blank' => [['statusMessage' => " \n "]],
    'non-string' => [['statusMessage' => ['x']]],
    'number' => [['statusMessage' => 5]],
]);

test('statusMessage is stripped of control characters and capped', function () {
    $client = makeClient([failedRun(['statusMessage' => "a\nb\x00c".str_repeat('x', 3000)])]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->getMessage())->toStartWith('Actor run ended with status FAILED: a b c')
            ->and(mb_strlen($e->getMessage()))->toBe(mb_strlen('Actor run ended with status FAILED: ') + 500)
            ->and(mb_strlen((string) $e->statusMessage))->toBe(2000)
            ->and($e->statusMessage)->not->toMatch('/[\x00-\x1F]/');
    }
});

test('timeout exception carries the running statusMessage', function () {
    $client = makeClient([
        jsonResponse(201, ['data' => ['id' => 'run9', 'status' => 'RUNNING', 'statusMessage' => 'Comparing 3/10']]),
        jsonResponse(200, []),
    ], config: new Config(timeout: 0));

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e->statusMessage)->toBe('Comparing 3/10')
            ->and($e->runId)->toBe('run9');
    }
});

test('throws on unknown run status', function () {
    $client = makeClient([runResponse('WEIRD')]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'unknown run status');
});

test('throws when a running run has no id', function () {
    $client = makeClient([jsonResponse(201, ['data' => ['status' => 'RUNNING']])]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'missing run ID');
});

test('throws when the run data is missing', function () {
    $client = makeClient([jsonResponse(201, ['foo' => 'bar'])]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'missing run data');
});

test('throws when the dataset id is missing', function () {
    $client = makeClient([runResponse(datasetId: null)]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'missing dataset ID');
});

test('429 maps to RateLimitException with Retry-After', function () {
    $client = makeClient([jsonResponse(429, ['error' => 'slow down'], ['Retry-After' => '12'])]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(12)
            ->and($e)->toBeInstanceOf(ApiException::class);
    }
});

test('429 without Retry-After reports the backoff, not 60 seconds', function () {
    $client = makeClient([jsonResponse(429, ['error' => 'slow down'])]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(1);
    }
});

test('429 with an HTTP-date Retry-After is parsed', function () {
    $date = gmdate('D, d M Y H:i:s', time() + 20).' GMT';
    $client = makeClient([jsonResponse(429, [], ['Retry-After' => $date])]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected RateLimitException');
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBeBetween(18, 20);
    }
});

test('500 maps to ApiException with the status code', function () {
    $client = makeClient([new Response(500, [], 'boom')]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected ApiException');
    } catch (ApiException $e) {
        expect($e)->not->toBeInstanceOf(RateLimitException::class)
            ->and($e->getCode())->toBe(500)
            ->and($e->getMessage())->toContain('status 500');
    }
});

test('connection errors map to ApiException', function () {
    $client = makeClient([new ConnectException('no route', new Request('POST', 'https://api.apify.com'))]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'API request failed: no route');
});

test('malformed JSON maps to ApiException', function () {
    $client = makeClient([new Response(200, [], '{not json')]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'malformed JSON');
});

test('non-object JSON maps to ApiException', function () {
    $client = makeClient([new Response(200, [], '"text"')]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'unexpected JSON');
});

test('invalid dataset item names its index', function () {
    $broken = [...loadFixture('comparison_passed'), 'passed' => 'maybe'];
    $client = makeClient([runResponse(), jsonResponse(200, [loadFixture('comparison_passed'), $broken])]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'Invalid dataset item at index 1');
});

test('non-object dataset item names its index', function () {
    $client = makeClient([runResponse(), jsonResponse(200, ['text'])]);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'Invalid dataset item at index 0');
});

test('custom config changes actor, base url and request timeout', function () {
    $history = [];
    $config = new Config('acme/custom', 'https://proxy.example.com/v2/', requestTimeout: 5);
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, $config);

    $client->compare([samplePair()]);

    $request = $history[0]['request'];
    expect($request->getUri()->getHost())->toBe('proxy.example.com')
        ->and($request->getUri()->getPath())->toBe('/v2/acts/acme~custom/runs')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('client works with its default HTTP client', function () {
    expect(new Client)->toBeInstanceOf(Client::class);
});

test('setApifyKey is fluent and sets the bearer token', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, apiKey: null);

    expect($client->setApifyKey('my-key'))->toBe($client);

    $client->compare([samplePair()]);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer my-key');
});

test('falls back to the APIFY_API_TOKEN and APIFY_TOKEN environment variables', function () {
    $previous = [getenv('APIFY_API_TOKEN'), getenv('APIFY_TOKEN')];

    try {
        putenv('APIFY_API_TOKEN');
        putenv('APIFY_TOKEN=env-token-2');
        $history = [];
        makeClient([runResponse(), jsonResponse(200, [])], $history, apiKey: null)->compare([samplePair()]);
        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer env-token-2');

        putenv('APIFY_API_TOKEN=env-token-1');
        $history = [];
        makeClient([runResponse(), jsonResponse(200, [])], $history, apiKey: null)->compare([samplePair()]);
        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer env-token-1');
    } finally {
        putenv($previous[0] === false ? 'APIFY_API_TOKEN' : 'APIFY_API_TOKEN='.$previous[0]);
        putenv($previous[1] === false ? 'APIFY_TOKEN' : 'APIFY_TOKEN='.$previous[1]);
    }
});

test('throws MissingApiKeyException when no key is set anywhere', function () {
    $previous = [getenv('APIFY_API_TOKEN'), getenv('APIFY_TOKEN')];

    try {
        putenv('APIFY_API_TOKEN');
        putenv('APIFY_TOKEN');
        $history = [];
        $client = makeClient([], $history, apiKey: null);

        expect(fn () => $client->compare([samplePair()]))->toThrow(MissingApiKeyException::class)
            ->and($history)->toBeEmpty();
    } finally {
        putenv($previous[0] === false ? 'APIFY_API_TOKEN' : 'APIFY_API_TOKEN='.$previous[0]);
        putenv($previous[1] === false ? 'APIFY_TOKEN' : 'APIFY_TOKEN='.$previous[1]);
    }
});

test('429 is retried after the Retry-After wait and then succeeds', function () {
    $history = [];
    $sleeps = [];
    $client = makeClient([
        jsonResponse(429, ['error' => 'slow'], ['Retry-After' => '5']),
        runResponse(),
        jsonResponse(200, []),
    ], $history, new Config, sleeps: $sleeps);

    expect($client->compare([samplePair()]))->toBe([])
        ->and($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeGreaterThanOrEqual(5.0)
        ->and($history)->toHaveCount(3);
});

test('429 without Retry-After is retried with the backoff delay', function () {
    $sleeps = [];
    $client = makeClient([
        jsonResponse(429, []),
        runResponse(),
        jsonResponse(200, []),
    ], config: new Config, sleeps: $sleeps);

    expect($client->compare([samplePair()]))->toBe([])
        ->and($sleeps)->toHaveCount(1)
        ->and($sleeps[0])->toBeBetween(0.5, 1.0);
});

test('429 with an HTTP-date Retry-After is waited out', function () {
    $sleeps = [];
    $date = gmdate('D, d M Y H:i:s', time() + 10).' GMT';
    $client = makeClient([
        jsonResponse(429, [], ['Retry-After' => $date]),
        runResponse(),
        jsonResponse(200, []),
    ], config: new Config, sleeps: $sleeps);

    expect($client->compare([samplePair()]))->toBe([])
        ->and($sleeps[0])->toBeBetween(8.0, 10.0);
});

test('429 with a Retry-After above the maximum delay is not waited out', function () {
    $sleeps = [];
    $client = makeClient([jsonResponse(429, [], ['Retry-After' => '120'])], config: new Config, sleeps: $sleeps);

    expect(fn () => $client->compare([samplePair()]))->toThrow(RateLimitException::class)
        ->and($sleeps)->toBe([]);
});

test('GET requests retry on 5xx with growing backoff, then give up', function () {
    $sleeps = [];
    $client = makeClient([
        runResponse('RUNNING'),
        new Response(503, [], 'a'),
        new Response(502, [], 'b'),
        new Response(500, [], 'c'),
        new Response(500, [], 'd'),
    ], config: new Config(maxRetries: 3), sleeps: $sleeps);

    expect(fn () => $client->compare([samplePair()]))
        ->toThrow(ApiException::class, 'status 500')
        ->and($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBeBetween(0.5, 1.0)
        ->and($sleeps[1])->toBeBetween(1.0, 2.0)
        ->and($sleeps[2])->toBeBetween(2.0, 4.0);
});

test('starting a run is not repeated after a 5xx', function () {
    $history = [];
    $sleeps = [];
    $client = makeClient([new Response(500, [], 'boom'), runResponse()], $history, new Config, sleeps: $sleeps);

    expect(fn () => $client->compare([samplePair()]))->toThrow(ApiException::class, 'status 500')
        ->and($history)->toHaveCount(1)
        ->and($sleeps)->toBe([]);
});

test('starting a run is not repeated after a timeout', function () {
    $history = [];
    $timeout = new RequestException(
        'cURL error 28: Operation timed out',
        new Request('POST', 'https://api.apify.com'),
    );
    $client = makeClient([$timeout, runResponse()], $history, new Config);

    expect(fn () => $client->compare([samplePair()]))->toThrow(ApiException::class)
        ->and($history)->toHaveCount(1);
});

test('starting a run is not repeated after a Guzzle 8 network timeout, which is not a ConnectException', function () {
    $history = [];
    $request = new Request('POST', 'https://api.apify.com');
    $timeout = new NetworkTimeoutException('cURL error 28: Operation timed out', $request);
    $client = makeClient([$timeout, runResponse()], $history, new Config);

    expect(fn () => $client->compare([samplePair()]))->toThrow(ApiException::class)
        ->and($history)->toHaveCount(1);
})->skip(! class_exists(NetworkTimeoutException::class), 'Guzzle 8 only');

test('a failed connection is retried, also when starting a run', function () {
    $sleeps = [];
    $client = makeClient([
        new ConnectException('no route', new Request('POST', 'https://api.apify.com')),
        runResponse(),
        jsonResponse(200, []),
    ], config: new Config, sleeps: $sleeps);

    expect($client->compare([samplePair()]))->toBe([])
        ->and($sleeps)->toHaveCount(1);
});

test('maxRetries 0 turns retrying off', function () {
    $history = [];
    $client = makeClient([jsonResponse(429, [], ['Retry-After' => '1']), runResponse()], $history);

    expect(fn () => $client->compare([samplePair()]))->toThrow(RateLimitException::class)
        ->and($history)->toHaveCount(1);
});

test('4xx other than 429 is never retried', function () {
    $history = [];
    $client = makeClient([new Response(401, [], 'no'), runResponse()], $history, new Config);

    expect(fn () => $client->compare([samplePair()]))->toThrow(ApiException::class, 'status 401')
        ->and($history)->toHaveCount(1);
});

test('compare rejects invalid input before any request', function (array $args, string $message) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->compare(...$args))->toThrow(InvalidArgumentException::class, $message);
    expect($history)->toBe([]);
})->with([
    'no pairs' => [['pairs' => []], 'between 1 and 500'],
    'too many pairs' => [['pairs' => array_fill(0, 501, new ImagePair('a', 'b'))], 'got 501'],
    'non pair' => [['pairs' => ['x']], 'pairs[0] must be an ImagePair'],
    'threshold high' => [['pairs' => [new ImagePair('a', 'b')], 'similarityThreshold' => 1.5], 'similarityThreshold'],
    'threshold low' => [['pairs' => [new ImagePair('a', 'b')], 'similarityThreshold' => -0.1], 'similarityThreshold'],
    'diff percent' => [['pairs' => [new ImagePair('a', 'b')], 'maxDiffPercent' => 100.5], 'maxDiffPercent'],
    'tolerance' => [['pairs' => [new ImagePair('a', 'b')], 'pixelTolerance' => 256], 'pixelTolerance'],
    'dimension low' => [['pairs' => [new ImagePair('a', 'b')], 'maxDimension' => 15], 'maxDimension'],
    'dimension high' => [['pairs' => [new ImagePair('a', 'b')], 'maxDimension' => 4001], 'maxDimension'],
    'run regions' => [
        ['pairs' => [new ImagePair('a', 'b')], 'ignoreRegions' => array_fill(0, 101, new Region(0, 0, 1, 1))],
        'ignoreRegions may contain at most 100',
    ],
    'pair regions' => [
        ['pairs' => [new ImagePair('a', 'b', null, array_fill(0, 101, new Region(0, 0, 1, 1)))]],
        'pairs[0].ignoreRegions may contain at most 100',
    ],
    'combined regions' => [
        [
            'pairs' => [
                new ImagePair('a', 'b'),
                new ImagePair('a', 'b', null, array_fill(0, 51, new Region(0, 0, 1, 1))),
            ],
            'ignoreRegions' => array_fill(0, 50, new Region(0, 0, 1, 1)),
        ],
        'pairs[1] has 101 ignore regions',
    ],
    'negative region' => [
        ['pairs' => [new ImagePair('a', 'b', null, [new Region(-1, 0, 1, 1)])]],
        'pairs[0].ignoreRegions[0].x must be between 0 and 100000',
    ],
    'huge region' => [
        ['pairs' => [new ImagePair('a', 'b')], 'ignoreRegions' => [new Region(0, 0, 1, 100_001)]],
        'ignoreRegions[0].height',
    ],
]);

test('compare accepts the documented limits', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history);
    $pairs = array_fill(0, 500, new ImagePair('a', 'b', null, array_fill(0, 50, new Region(0, 0, 100_000, 0))));

    $client->compare(
        pairs: $pairs,
        similarityThreshold: 1.0,
        maxDiffPercent: 0.0,
        pixelTolerance: 255,
        maxDimension: 16,
        ignoreRegions: array_fill(0, 50, new Region(0, 0, 1, 1)),
    );

    expect($history)->toHaveCount(2);
});

test('the run timeout defaults to the client wait and is sent on the run start only', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(200, [])], $history, new Config(timeout: 420, maxRetries: 0));

    $client->compare([samplePair()]);

    expect($history[0]['request']->getUri()->getQuery())->toBe('timeout=420')
        ->and($history[1]['request']->getUri()->getQuery())->not->toContain('timeout=420');
});

test('the run timeout of the Config wins over the client wait', function () {
    $history = [];
    $config = new Config(timeout: 420, maxRetries: 0, runTimeout: 120);
    makeClient([runResponse(), jsonResponse(200, [])], $history, $config)->compare([samplePair()]);

    expect($history[0]['request']->getUri()->getQuery())->toBe('timeout=120');
});

test('the run timeout of the call wins over the Config', function () {
    $history = [];
    $config = new Config(timeout: 420, maxRetries: 0, runTimeout: 120);
    makeClient([runResponse(), jsonResponse(200, [])], $history, $config)->compare([samplePair()], runTimeout: 60);

    expect($history[0]['request']->getUri()->getQuery())->toBe('timeout=60');
});

test('a run timeout of 0 sends no timeout and keeps the Actor limit', function () {
    $history = [];
    $config = new Config(timeout: 420, maxRetries: 0, runTimeout: 0);
    makeClient([runResponse(), jsonResponse(200, [])], $history, $config)->compare([samplePair()]);

    expect($history[0]['request']->getUri()->getQuery())->toBe('');

    $history = [];
    makeClient([runResponse(), jsonResponse(200, [])], $history)->compare([samplePair()], runTimeout: 0);

    expect($history[0]['request']->getUri()->getQuery())->toBe('');
});

test('a negative run timeout is rejected before any request', function () {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->compare([samplePair()], runTimeout: -1))
        ->toThrow(InvalidArgumentException::class, 'runTimeout must be 0 or a positive number of seconds, got -1.')
        ->and($history)->toBeEmpty();

    $client = makeClient([], $history, new Config(maxRetries: 0, runTimeout: -5));

    expect(fn () => $client->compare([samplePair()]))->toThrow(InvalidArgumentException::class, 'runTimeout');
});

function spendingBody(): array
{
    return ['error' => ['type' => 'not-enough-usage-to-run-paid-actor', 'message' => "Monthly limit\x01 reached"]];
}

test('402 on run start maps to SpendingLimitException and is not retried', function () {
    $history = [];
    $client = makeClient([jsonResponse(402, spendingBody()), runResponse()], $history, new Config(maxRetries: 3));

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected SpendingLimitException');
    } catch (SpendingLimitException $e) {
        expect($e)->toBeInstanceOf(ApiException::class)
            ->and($e->getCode())->toBe(402)
            ->and($e->errorType)->toBe('not-enough-usage-to-run-paid-actor')
            ->and($e->getMessage())->toContain('Monthly limit  reached')
            ->and($e->getMessage())->not->toContain("\x01")
            ->and($history)->toHaveCount(1);
    }
});

test('402 while polling maps to SpendingLimitException', function () {
    $history = [];
    $client = makeClient(
        [runResponse('RUNNING'), jsonResponse(402, spendingBody()), runResponse()],
        $history,
        new Config(maxRetries: 3),
    );

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected SpendingLimitException');
    } catch (SpendingLimitException $e) {
        expect($e->getCode())->toBe(402)
            ->and($e->errorType)->toBe('not-enough-usage-to-run-paid-actor')
            ->and($history)->toHaveCount(2);
    }
});

test('402 on the dataset fetch maps to SpendingLimitException', function () {
    $history = [];
    $client = makeClient([runResponse(), jsonResponse(402, spendingBody()), jsonResponse(200, [])], $history);

    expect(fn () => $client->compare([samplePair()]))->toThrow(SpendingLimitException::class)
        ->and($history)->toHaveCount(2);
});

test('402 on abort maps to SpendingLimitException', function () {
    $client = makeClient([jsonResponse(402, spendingBody())]);

    expect(fn () => $client->abort('run1'))->toThrow(SpendingLimitException::class);
});

test('402 with an unparseable body has no errorType and a generic message', function () {
    $client = makeClient([new Response(402, [], 'not json')]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected SpendingLimitException');
    } catch (SpendingLimitException $e) {
        expect($e->errorType)->toBeNull()
            ->and($e->getCode())->toBe(402)
            ->and($e->getMessage())->toBe('Apify usage or spending limit reached (HTTP 402)');
    }
});

test('402 message parts are capped at 500 characters', function () {
    $client = makeClient([jsonResponse(402, ['error' => ['type' => 't', 'message' => str_repeat('x', 900)]])]);

    try {
        $client->compare([samplePair()]);
        $this->fail('Expected SpendingLimitException');
    } catch (SpendingLimitException $e) {
        expect(mb_strlen($e->getMessage()))->toBeLessThan(600);
    }
});
