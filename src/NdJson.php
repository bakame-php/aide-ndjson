<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use CallbackFilterIterator;
use Exception;
use Iterator;
use JsonException;
use League\Csv\JsonConverter;
use League\Csv\JsonFormat;
use League\Csv\MapIterator;
use League\Csv\ResultSet;
use League\Csv\Stream;
use League\Csv\SyntaxError;
use League\Csv\TabularData;
use League\Csv\UnavailableStream;
use LimitIterator;
use SplFileInfo;
use SplFileObject;
use Stringable;
use ValueError;

use function array_keys;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * @phpstan-type Path SplFileInfo|SplFileObject|Stream|resource|non-empty-string
 */
final class NdJson
{
    /**
     * @template T
     * @template V of array
     *
     * @param ?(callable(V): T) $mapper
     *
     * @throws SyntaxError|UnavailableStream|ValueError
     *
     * @return Iterator<T|V>
     */
    public static function decode(Stringable|string $content, ?callable $mapper = null): Iterator
    {
        return self::read(Stream::createFromString($content), $mapper);
    }

    /**
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param ?(callable(T): V) $formatter
     *
     * @throws JsonException
     */
    public static function encode(iterable $data, ?callable $formatter = null): string
    {
        return self::encoder()->formatter($formatter)->encode($data);
    }

    /**
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param (callable(T): V)|null $formatter
     *
     * @throws JsonException
     *
     * @return Iterator<string>
     */
    public static function chunk(iterable $data, ?callable $formatter = null): Iterator
    {
        return self::encoder()->formatter($formatter)->convert($data);
    }

    /**
     * @template T
     * @template V of array
     *
     * @param Path $from
     * @param ?(callable(V): T) $mapper
     * @param ?resource $context
     *
     * @throws SyntaxError|UnavailableStream|ValueError
     *
     * @return Iterator<T|V>
     */
    public static function read(mixed $from, ?callable $mapper = null, $context = null): Iterator
    {
        $iterator = self::iterator($from, $context);

        return null !== $mapper ? new MapIterator($iterator, $mapper) : $iterator;
    }

    /**
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param Path $to
     * @param ?(callable(T): V) $formatter
     * @param ?resource $context
     *
     * @throws UnavailableStream|ValueError|JsonException
     */
    public static function write(iterable $data, mixed $to, ?callable $formatter = null, $context = null): int
    {
        return self::encoder()->formatter($formatter)->save($data, $to, $context);
    }

    /**
     * Sends and makes the NDJSON structure downloadable via HTTP.
     *.
     * Returns the number of characters read from the handle and passed through to the output.
     *
     * @template T
     * @template V
     *
     * @param iterable<T> $data
     * @param ?(callable(T): V) $formatter
     *
     * @throws Exception|JsonException
     */
    public static function download(iterable $data, ?string $filename = null, ?callable $formatter = null): int
    {
        return self::encoder()->formatter($formatter)->download($data, $filename);
    }

    /**
     * @return JsonConverter<mixed>
     */
    private static function encoder(): JsonConverter
    {
        static $encoder;
        $encoder ??= (new JsonConverter())->format(JsonFormat::NdJson);

        return $encoder;
    }

    /**
     * @param Path $path
     * @param ?resource $context
     *
     * @throws UnavailableStream|SyntaxError|ValueError
     *
     * @return MapIterator<array>
     */
    private static function iterator(mixed $path, $context = null): MapIterator
    {
        $file = match (true) {
            $path instanceof Stream,
            $path instanceof SplFileObject => $path,
            $path instanceof SplFileInfo => $path->openFile(context: $context),
            is_resource($path) => Stream::createFromResource($path),
            is_string($path) => Stream::createFromPath(path: $path, context: $context),
            default => throw new ValueError('The destination path must be a filename, a stream or a SplFileInfo object.'),
        };
        $file->setFlags(SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);

        return new MapIterator(
            new CallbackFilterIterator($file, fn (string|false $line): bool => false !== $line),
            fn (string $line): array => (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR)
        );
    }

    /**
     * @param Path $path
     * @param list<string> $header a list of unique string to use as header
     * @param ?resource $context
     *
     * @throws UnavailableStream|SyntaxError|ValueError
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
        if ([] === $header) {
            return new ResultSet($iterator);
        }

        if (array_is_list($header)) {
            return new ResultSet(new LimitIterator($iterator, 1), $header);
        }

        return new ResultSet($iterator, array_keys($header));
    }

    /**
     * @param list<string> $header
     * @throws SyntaxError|UnavailableStream|ValueError
     */
    public static function readTabularFromString(Stringable|string $content, array $header = []): TabularData
    {
        return self::readTabularFromPath(Stream::createFromString($content), $header);
    }
}
