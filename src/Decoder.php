<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use CallbackFilterIterator;
use Iterator;
use JsonException;
use SplFileInfo;
use SplFileObject;
use ValueError;

use function is_string;
use function json_decode;
use function json_last_error;
use function trim;

use const JSON_ERROR_NONE;
use const JSON_FORCE_OBJECT;
use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

/**
 * @internal Generic NDJSON decoder
 *
 * @phpstan-type FileDescriptor SplFileInfo|SplFileObject|resource|non-empty-string
 */
final class Decoder
{
    /**
     * @param FileDescriptor $path
     * @param int<0, max> $flags
     * @param int<1, max> $depth
     * @param ?resource $context
     *
     * @throws DecodingNdJsonFailed
     *
     * @return Iterator<array-key, mixed>
     */
    public function __invoke(mixed $path, int $flags = 0, int $depth = 512, $context = null): Iterator
    {
        json_decode(json: '1', flags: $flags & ~JSON_THROW_ON_ERROR);
        JSON_ERROR_NONE === json_last_error() || throw new ValueError('The flags options are invalid.');
        1 <= $depth || throw new ValueError('The depth option is invalid; it must be greater or equal to 0.');

        $stream = match (true) {
            $path instanceof SplFileObject => $path,
            $path instanceof SplFileInfo => $path->openFile(context: $context),
            default => Stream::from(filename: $path, context: $context),
        };

        $stream->setFlags(SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $filter = static fn ($current): bool => is_string($current) && '' !== trim($current);
        $flags = ($flags | JSON_THROW_ON_ERROR) & ~(JSON_OBJECT_AS_ARRAY | JSON_FORCE_OBJECT);
        $decode = function (string $line, $offset) use ($flags, $depth): array {
            try {
                /** @var array<mixed> $record */
                $record = json_decode(trim($line), true, $depth, $flags);

                return $record;
            } catch (JsonException $exception) {
                throw new DecodingNdJsonFailed(
                    message: 'Unable to decode the json line: '.$exception->getMessage(),
                    value: $line,
                    offset: $offset,
                    previous: $exception,
                );
            }
        };

        return new MapIterator(new CallbackFilterIterator($stream, $filter), $decode);
    }
}
