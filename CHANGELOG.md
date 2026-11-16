# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- The `ApiException` message of a FAILED, ABORTED or TIMED-OUT run now ends with `: <statusMessage>` when the run reports a non-empty string `statusMessage` (control characters stripped, capped at 500 characters). Without one the message is unchanged.
- Behavior change: the run start now sends Apify's run time limit (`timeout` query, seconds) equal to `Config::$timeout` by default, so a run can no longer outlive the client wait. Set `Config::$runTimeout = 0` to keep the Actor's own limit.

### Added

- `ResultSet` with `missingIndexes(int $inputCount)`, `unprocessed(array $inputs)` and `isComplete(int $inputCount)` to find pairs without a result row. `compare()` returns one result per processed pair; fewer rows than pairs come back on the free plan, in TEST runs, after the spending limit, after a shutdown and for duplicates. A row with an error (including `time_limit`) counts as processed. `compare()` itself is unchanged.
- `SpendingLimitException` (extends `ApiException`, code 402, `?string $errorType` with the Apify error type) is thrown on HTTP 402 (monthly usage or spending limit reached) instead of a generic `ApiException`. It is never retried.
- `ApiException::$statusMessage` (`?string`, capped at 2000 characters) carries the run's own status message on failed runs and client-side timeouts; `$runId` is now also set on failed runs.
- `Config::$runTimeout` (`?int` seconds; null = `Config::$timeout`, 0 = send nothing) and a `runTimeout` argument on `Client::compare()` (wins over the Config) control the Apify run time limit. Negative values throw an `InvalidArgumentException`.

- When `Config::$timeout` expires while waiting for a run, the client now aborts that run (best effort, one short request, errors ignored) so it does not keep running and billing. The `ApiException` says "and was aborted" only if the abort succeeded.
- `ApiException::$runId` carries the run ID of a timed-out run.
- `Client::abort(string $runId)` aborts a run explicitly.

## [0.2.0] - 2026-11-15

### Changed

- Guzzle 8 supported, Guzzle 7 still allowed (`guzzlehttp/guzzle` `^7.0 || ^8.0`).
- `Retry-After` is parsed in both its seconds and HTTP-date form. Without the header, `RateLimitException::$retryAfter` is the current backoff delay instead of 60.
- Breaking: the Apify API key is no longer a `Client` constructor argument or a `Config` property. Use `(new Client())->setApifyKey($apiToken)`. Without it the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment.

### Added

- Serialization: `ComparisonResult`, `Region`, `ImageSize` and `ImagePair` implement `JsonSerializable` and have `toArray()` in the Actor's field names, so `ComparisonResult::fromArray($result->toArray())` round-trips. `Region::toArray()` now includes `changed_pixels` when set.
- Client-side input validation: `Client::compare()` throws an `InvalidArgumentException` with a clear message, before starting a run, for a pair count outside 1 to 500, out-of-range `similarityThreshold`, `maxDiffPercent`, `pixelTolerance` or `maxDimension`, invalid ignore regions and more than 100 ignore regions per pair (run level plus pair level combined).
- Automatic retrying with exponential backoff and jitter (`Config::$maxRetries`, `$retryBaseDelay`, `$retryMaxDelay`): rate limits honour `Retry-After`, 5xx and connection failures are retried, and starting a run is not repeated after a 5xx or timeout.
- `Client::setApifyKey()` and `MissingApiKeyException`, thrown on the first API call when no key is available.

## [0.1.0] - 2026-11-08

### Added

- `Client::compare()` for bulk image comparison with similarity threshold, changed-pixel limit, pixel tolerance, resize mode, normalization, ignored regions and diff overlay options.
- Typed DTOs `ImagePair`, `ComparisonResult`, `Region` and `ImageSize`.
- Enums `ResizeMode` and `Normalization`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
