<?php

declare(strict_types=1);

use Bakame\Aide\NdJson\Codec;
use Bakame\Aide\NdJson\InvalidNdJsonArgument;
use Bakame\Aide\NdJson\NdJsonException;

/**
 * @template TValue
 *
 * @param iterable<TValue> $data
 * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
 * @param int<1, max> $depth
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_encode(
    iterable $data,
    int $flags = 0,
    int $depth = 512,
): string {
    return (new Codec())->addFlags($flags)->depth($depth)->encode($data);
}

/**
 * @param int<0, max> $flags JSON encoding flags
 * @param int<1, max> $depth
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_decode(
    Stringable|string $data,
    int $flags = 0,
    int $depth = 512,
): Iterator {
    return (new Codec())->addFlags($flags)->depth($depth)->decode($data);
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
    $context = null,
): Iterator {
    return (new Codec())->addFlags($flags)->depth($depth)->read(from: $from, context: $context);
}

/**
 * @template TValue
 *
 * @param iterable<TValue> $data
 * @param SplFileInfo|SplFileObject|resource|non-empty-string $to
 * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
 * @param int<1, max> $depth
 * @param ?resource $context
 *
 * @throws InvalidNdJsonArgument
 * @throws NdJsonException
 */
function ndjson_write(
    iterable $data,
    mixed $to,
    int $flags = 0,
    int $depth = 512,
    $context = null
): int {
    return (new Codec())->addFlags($flags)->depth($depth)->write(data: $data, to: $to, context: $context);
}
