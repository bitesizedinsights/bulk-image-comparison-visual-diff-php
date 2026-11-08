# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Breaking: the Apify API key is no longer a `Client` constructor argument or a `Config` property. Use `(new Client())->setApifyKey($apiToken)`. Without it the client reads `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment.

### Added

- `Client::setApifyKey()` and `MissingApiKeyException`, thrown on the first API call when no key is available.

## [0.1.0] - 2026-11-08

### Added

- `Client::compare()` for bulk image comparison with similarity threshold, changed-pixel limit, pixel tolerance, resize mode, normalization, ignored regions and diff overlay options.
- Typed DTOs `ImagePair`, `ComparisonResult`, `Region` and `ImageSize`.
- Enums `ResizeMode` and `Normalization`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
