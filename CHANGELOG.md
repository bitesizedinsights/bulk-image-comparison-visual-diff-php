# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- `Retry-After` is parsed in both its seconds and HTTP-date form. Without the header, `RateLimitException::$retryAfter` is the current backoff delay instead of 60.
- Breaking: the Apify API key is no longer a `Client` constructor argument or a `Config` property. Use `(new Client())->setApifyKey($apiToken)`. Without it the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment.

### Added

- Automatic retrying with exponential backoff and jitter (`Config::$maxRetries`, `$retryBaseDelay`, `$retryMaxDelay`): rate limits honour `Retry-After`, 5xx and connection failures are retried, and starting a run is not repeated after a 5xx or timeout.
- `Client::setApifyKey()` and `MissingApiKeyException`, thrown on the first API call when no key is available.

## [0.1.0] - 2026-11-08

### Added

- `Client::compare()` for bulk image comparison with similarity threshold, changed-pixel limit, pixel tolerance, resize mode, normalization, ignored regions and diff overlay options.
- Typed DTOs `ImagePair`, `ComparisonResult`, `Region` and `ImageSize`.
- Enums `ResizeMode` and `Normalization`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
