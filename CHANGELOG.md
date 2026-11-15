# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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
