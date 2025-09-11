# Changelog

All Notable changes to `bakame/aide-ndjon` will be documented in this file.

## [Next](https://github.com/bakame-php/aide-ndjson/releases/tag/1.0.0...main) - TBD

### Added

- `$flags` parameter to encoding method to allow configuring json flags encoding static methods.
- `$chunkSize` parameter to encoding method to control how many rows are generated per chunk.
- `NdJson::decodeTabularFromString`
- Package specific exceptions `NdJsonException`, `EncodingNdJsonFailed` and `InvalidNdJsonArgument`

### Deprecated

- `NdJson::readTabularFromString` use `NdJson::decodeTabularFromString` insteaf

### Fixed

- None

### Remove

- None

## [1.0.0](https://github.com/bakame-php/aide-ndjson/releases/tag/1.0.0) - 2025-09-11

**Initial release!**
