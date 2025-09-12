<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use Iterator;
use SplFileInfo;
use SplFileObject;
use Stringable;

/**
 * @template TValue
 *
 * @param iterable<TValue> $value
 * @param int<0, max> $flags
 * @param int<1, max> $depth
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_encode(
    iterable $value,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
): string {
    return (new Codec())
        ->addFlags($flags)
        ->depth($depth)
        ->encode(
            value: $value,
            format: $format,
            headerOrOffset: Format::ListWithHeader === $format ? 0 : [],
        );
}

/**
 * @param int<0, max> $flags
 * @param int<1, max> $depth
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_decode(
    Stringable|string $ndjson,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
): Iterator {
    return (new Codec())
        ->addFlags($flags)
        ->depth($depth)
        ->decode(
            ndjson: $ndjson,
            format: $format,
            headerOrOffset: Format::ListWithHeader === $format ? 0 : []
        );
}

/**
 * @param SplFileInfo|SplFileObject|resource|non-empty-string $from
 * @param int<0, max> $flags
 * @param int<1, max> $depth
 * @param ?resource $context
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_read(
    mixed $from,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
    $context = null,
): Iterator {
    return (new Codec())
        ->addFlags($flags)
        ->depth($depth)
        ->read(
            from: $from,
            format: $format,
            headerOrOffset: Format::ListWithHeader === $format ? 0 : [],
            context: $context,
        );
}

/**
 * @template TValue
 *
 * @param iterable<TValue> $value
 * @param SplFileInfo|SplFileObject|resource|non-empty-string $to
 * @param int<0, max> $flags
 * @param int<1, max> $depth
 * @param ?resource $context
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_write(
    iterable $value,
    mixed $to,
    int $flags = 0,
    int $depth = 512,
    Format $format = Format::Record,
    $context = null
): int {
    return (new Codec())
        ->addFlags($flags)
        ->depth($depth)
        ->write(
            value: $value,
            to: $to,
            format: $format,
            headerOrOffset: Format::ListWithHeader === $format ? 0 : [],
            context: $context
        );
}
