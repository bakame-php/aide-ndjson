# Changelog

All Notable changes to `bakame/aide-ndjon` will be documented in this file.

## [Next](https://github.com/bakame-php/aide-ndjson/releases/tag/1.0.0...main) - TBD

### Added

- `Format` Enum to control the NDJSON shape and format.
- `$headerOrOffset` parameter to control the header usage.
- `$flags` parameter to encoding method to allow configuring JSON flags encoding static methods.
- `$chunkSize` parameter to encoding method to control how many rows are generated per chunk.
- `$depth` parameter to handle `JSON` methods recursion parameter.
- `NdJson::decodeTabularData`, `NdJson::readTabularData`
- Package specific exceptions `NdJsonException`, `DecodingNdJsonFailed`, `EncodingNdJsonFailed` and `InvalidNdJsonArgument`
- `NdJson::encodeTabularData`, `NdJson::writeTabularData` and `NdJson::downloadTabularData`
- `Codec` to ease API usage

### Deprecated

- None

### Fixed

- None

### Remove

- `Ndjson` class

## [1.0.0](https://github.com/bakame-php/aide-ndjson/releases/tag/1.0.0) - 2025-09-11

**Initial release!**
