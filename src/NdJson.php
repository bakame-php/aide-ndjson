<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use CallbackFilterIterator;
use Deprecated;
use Exception;
use Iterator;
use League\Csv\JsonConverter;
use League\Csv\JsonFormat;
use League\Csv\MapIterator;
use League\Csv\ResultSet;
use League\Csv\Stream;
use League\Csv\TabularData;
use LimitIterator;
use SplFileInfo;
use SplFileObject;
use Stringable;
use ValueError;

use function array_is_list;
use function array_keys;
use function is_resource;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * @phpstan-type Path SplFileInfo|SplFileObject|Stream|resource|non-empty-string
 */
final class NdJson
{
    /*****************
     * Write Methods
     ****************/

    /**
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
     * @param ?(callable(T): V) $formatter
     * @param int<1, max> $chunkSize
     *
     * @throws NdJsonException
     */
    public static function encode(iterable $data, ?callable $formatter = null, int $flags = 0, int $chunkSize = 1): string
    {
        $encoder = self::encoder($flags, $formatter, $chunkSize);

        try {
            return $encoder->encode($data);
        } catch (Exception $exception) {
            throw new EncodingNdJsonFailed('Failed to encode NDJSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param (callable(T): V)|null $formatter
     * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
     * @param int<1, max> $chunkSize
     *
     * @throws NdJsonException
     *
     * @return Iterator<string>
     */
    public static function chunk(iterable $data, ?callable $formatter = null, int $flags = 0, int $chunkSize = 1): Iterator
    {
        $encoder = self::encoder($flags, $formatter, $chunkSize);
        try {
            return $encoder->convert($data);
        } catch (Exception $exception) {
            throw new EncodingNdJsonFailed('Failed to stream NDJSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param Path $to
     * @param ?(callable(T): V) $formatter
     * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
     * @param ?resource $context
     * @param int<1, max> $chunkSize
     *
     * @throws NdJsonException
     */
    public static function write(iterable $data, mixed $to, ?callable $formatter = null, $context = null, int $flags = 0, int $chunkSize = 1): int
    {
        $encoder = self::encoder($flags, $formatter, $chunkSize);

        try {
            return $encoder->save($data, $to, $context);
        } catch (Exception $exception) {
            throw new EncodingNdJsonFailed('Failed to store NDJSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Sends and makes the NDJSON structure downloadable via HTTP.
     *
     * Returns the number of characters read from the handle and passed through to the output.
     *
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
     * @param ?(callable(T): V) $formatter
     * @param int<1, max> $chunkSize
     *
     * @throws NdJsonException
     */
    public static function download(iterable $data, ?string $filename = null, ?callable $formatter = null, int $flags = 0, int $chunkSize = 1): int
    {
        $encoder = self::encoder($flags, $formatter, $chunkSize);

        try {
            return $encoder->download($data, $filename);
        } catch (Exception $exception) {
            throw new EncodingNdJsonFailed('Failed to download NDJSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @template T
     * @template V
     *
     * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
     * @param ?(callable(T): V) $formatter
     * @param int<1, max> $chunkSize
     *
     * @throws InvalidNdJsonArgument
     *
     * @return JsonConverter<mixed>
     */
    private static function encoder(int $flags, ?callable $formatter, int $chunkSize = 1): JsonConverter
    {
        try {
            return (new JsonConverter(
                flags: $flags,
                formatter: $formatter,
                jsonFormat: JsonFormat::NdJson
            ))->withoutPrettyPrint()
                ->chunkSize($chunkSize);
        } catch (Exception $exception) {
            throw new InvalidNdJsonArgument('Invalid NDJSON encoding options: '.$exception->getMessage(), previous: $exception);
        }
    }

    /*****************
     * Read Methods
     ****************/

    /**
     * @template T
     * @template V of array
     *
     * @param ?(callable(V): T) $mapper
     *
     * @throws InvalidNdJsonArgument
     *
     * @return Iterator<T|V>
     */
    public static function decode(Stringable|string $content, ?callable $mapper = null): Iterator
    {
        return self::read(Stream::createFromString($content), $mapper);
    }

    /**
     * @param list<string> $header
     *
     * @throws InvalidNdJsonArgument
     */
    public static function decodeTabularFromString(Stringable|string $content, array $header = []): TabularData
    {
        return self::readTabularFromPath(Stream::createFromString($content), $header);
    }

    /**
     * @template T
     * @template V of array
     *
     * @param Path $from
     * @param ?(callable(V): T) $mapper
     * @param ?resource $context
     *
     * @throws InvalidNdJsonArgument
     *
     * @return Iterator<T|V>
     */
    public static function read(mixed $from, ?callable $mapper = null, $context = null): Iterator
    {
        $iterator = self::iterator($from, $context);

        return null !== $mapper ? new MapIterator($iterator, $mapper) : $iterator;
    }

    /**
     * @param Path $path
     * @param list<string> $header a list of unique string to use as header
     * @param ?resource $context
     *
     * @throws InvalidNdJsonArgument
     */
    public static function readTabularFromPath(mixed $path, array $header = [], $context = null): TabularData
    {
        $iterator = self::iterator($path, $context);
        if ([] !== $header) {
            return new ResultSet($iterator, $header);
        }

        $it = new LimitIterator($iterator, 0, 1);
        $it->rewind();
        /** @var ?array<mixed> $row */
        $row = $it->current();
        $header = $row ?? [];

        return match (true) {
            [] === $header => new ResultSet($iterator),
            array_is_list($header) => new ResultSet(new LimitIterator($iterator, 1), $header),
            default => new ResultSet($iterator, array_keys($header)),
        };
    }

    /**
     * @param Path $path
     * @param ?resource $context
     *
     * @throws InvalidNdJsonArgument
     *
     * @return MapIterator<array>
     */
    private static function iterator(mixed $path, $context = null): MapIterator
    {
        try {
            $file = match (true) {
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

        $file->setFlags(SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        return new MapIterator(
            new CallbackFilterIterator($file, fn ($current): bool => is_string($current) && '' !== trim($current)),
            fn (string $line): array => (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR)
        );
    }

    /**
     * DEPRECATION WARNING! This method will be removed in the next major point release.
     *
     * @codeCoverageIgnore
     * @deprecated since version 1.1.0
     * @see NdJson::decodeTabularFromString()
     *
     * @param list<string> $header
     *
     * @throws NdJsonException
     */
    #[Deprecated(message:'use Bakame\Aide\NdJson::decodeTabularFromString() instead', since:'bakame/aide-ndjson:1.1.0')]
    public static function readTabularFromString(Stringable|string $content, array $header = []): TabularData
    {
        return self::decodeTabularFromString($content, $header);
    }
}
