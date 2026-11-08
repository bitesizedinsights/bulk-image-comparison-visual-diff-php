# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-08

### Added

- `Client::compare()` for bulk image comparison with similarity threshold, changed-pixel limit, pixel tolerance, resize mode, normalization, ignored regions and diff overlay options.
- Typed DTOs `ImagePair`, `ComparisonResult`, `Region` and `ImageSize`.
- Enums `ResizeMode` and `Normalization`.
- `ApiException` and `RateLimitException`.
- Injectable Guzzle client for testing.
