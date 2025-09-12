<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use CallbackFilterIterator;
use Closure;
use Exception;
use Iterator;
use JsonException;
use League\Csv\Stream;
use SplFileInfo;
use SplFileObject;
use Stringable;
use ValueError;

use function is_string;
use function json_decode;
use function trim;

use const JSON_FORCE_OBJECT;
use const JSON_OBJECT_AS_ARRAY;
use const JSON_THROW_ON_ERROR;

/**
 * @internal Generic NDJSON decoder
 *
 * @template TKey
 * @template TValue
 * @template TReturn
 *
 * @phpstan-type Path SplFileInfo|SplFileObject|Stream|resource|non-empty-string
 */
final class Decoder
{
    /** @var ?Closure(TValue, TKey): TReturn */
    public readonly ?Closure $mapper;

    /**
     * @param int<0, max> $flags
     * @param int<1, max> $depth
     * @param ?callable(TValue, TKey): TReturn $mapper
     *
     * @throws InvalidNdJsonArgument
     */
    public function __construct(
        public readonly int $flags = 0,
        public readonly int $depth = 512,
        ?callable $mapper = null,
    ) {
        json_encode(null, $this->flags & ~JSON_THROW_ON_ERROR);
        JSON_ERROR_NONE === json_last_error() || throw new InvalidNdJsonArgument('The flags options are invalid.');
        1 <= $this->depth || throw new InvalidNdJsonArgument('The depth option is invalid; it must be greater or equal to 0.');

        if (!$mapper instanceof Closure && null !== $mapper) {
            $mapper = $mapper(...);
        }

        $this->mapper = $mapper;
    }

    /**
     * @param Path $path
     * @param ?resource $context
     *
     * @throws InvalidNdJsonArgument
     */
    private static function toStream(mixed $path, $context = null): Stream|SplFileObject
    {
        try {
            return match (true) {
                $path instanceof Stream,
                $path instanceof SplFileObject => $path,
                $path instanceof SplFileInfo => $path->openFile(context: $context),
                is_resource($path) => Stream::createFromResource($path),
                is_string($path) => Stream::createFromPath(path: $path, context: $context),
                default => throw new ValueError('The destination path must be a filename, a stream or a SplFileInfo object.'),
            };
        } catch (Exception $exception) {
            throw new InvalidNdJsonArgument('Invalid NDJSON decoding options: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return Iterator<array-key, TValue|TReturn>
     */
    private function convert(Stream|SplFileObject $stream): Iterator
    {
        $flags = ($this->flags | JSON_THROW_ON_ERROR) & ~(JSON_OBJECT_AS_ARRAY | JSON_FORCE_OBJECT);
        $decode = fn (string $line) => json_decode($line, true, $this->depth, $flags);
        if (null !== $this->mapper) {
            $decode = fn (string $line, $offset) => ($this->mapper)($decode($line), $offset);
        }

        $stream->setFlags(SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $filter = static fn ($current): bool => is_string($current) && '' !== trim($current);

        return new MapIterator(new CallbackFilterIterator($stream, $filter), $decode);
    }

    /**
     * @throws JsonException|InvalidNdJsonArgument
     *
     * @return Iterator<TKey, TValue|TReturn>
     */
    public function decode(Stringable|string $data): Iterator
    {
        return $this->convert(Stream::createFromString($data));
    }

    /**
     * @param Path $path
     * @param ?resource $context
     *
     * @throws JsonException|InvalidNdJsonArgument
     *
     * @return Iterator<TKey, TValue|TReturn>
     */
    public function read(mixed $path, $context = null): Iterator
    {
        return $this->convert(self::toStream($path, $context));
    }
}
