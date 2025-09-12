<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use BadMethodCallException;
use CallbackFilterIterator;
use Closure;
use Exception;
use Iterator;
use IteratorAggregate;
use LimitIterator;
use Stringable;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_reduce;
use function array_unique;
use function array_values;
use function fopen;
use function get_defined_constants;
use function is_array;
use function is_int;
use function is_iterable;
use function iterator_to_array;
use function json_encode;
use function json_last_error;
use function json_last_error_msg;
use function preg_match;
use function str_replace;
use function strtolower;
use function substr;
use function ucwords;

use const ARRAY_FILTER_USE_KEY;
use const JSON_ERROR_NONE;

/**
 * @template TMKey
 * @template TMValue of array
 * @template TMReturn
 * @template TKey
 * @template TValue
 * @template TReturn
 *
 * @phpstan-import-type FileDescriptor from Decoder
 *
 * @method self withHexTag() adds the JSON_HEX_TAG flag
 * @method self withoutHexTag() removes the JSON_HEX_TAG flag
 * @method bool useHexTag() tells whether the JSON_HEX_TAG flag is used
 * @method self withHexAmp() adds the JSON_HEX_AMP flag
 * @method self withoutHexAmp() removes the JSON_HEX_AMP flag
 * @method bool useHexAmp() tells whether the JSON_HEX_AMP flag is used
 * @method self withHexApos() adds the JSON_HEX_APOS flag
 * @method self withoutHexApos() removes the JSON_HEX_APOS flag
 * @method bool useHexApos() tells whether the JSON_HEX_APOS flag is used
 * @method self withHexQuot() adds the JSON_HEX_QUOT flag
 * @method self withoutHexQuot() removes the JSON_HEX_QUOT flag
 * @method bool useHexQuot() tells whether the JSON_HEX_QUOT flag is used
 * @method self withForceObject() adds the JSON_FORCE_OBJECT flag
 * @method self withoutForceObject() removes the JSON_FORCE_OBJECT flag
 * @method bool useForceObject() tells whether the JSON_FORCE_OBJECT flag is used
 * @method self withNumericCheck() adds the JSON_NUMERIC_CHECK flag
 * @method self withoutNumericCheck() removes the JSON_NUMERIC_CHECK flag
 * @method bool useNumericCheck() tells whether the JSON_NUMERIC_CHECK flag is used
 * @method self withUnescapedSlashes() adds the JSON_UNESCAPED_SLASHES flag
 * @method self withoutUnescapedSlashes() removes the JSON_UNESCAPED_SLASHES flag
 * @method bool useUnescapedSlashes() tells whether the JSON_UNESCAPED_SLASHES flag is used
 * @method self withoutPrettyPrint() removes the JSON_PRETTY_PRINT flag
 * @method bool usePrettyPrint() tells whether the JSON_PRETTY_PRINT flag is used
 * @method bool withPrettyPrint() adds the JSON_PRETTY_PRINT flag is used
 * @method self withUnescapedUnicode() adds the JSON_UNESCAPE_UNICODE flag
 * @method self withoutUnescapedUnicode() removes the JSON_UNESCAPE_UNICODE flag
 * @method bool useUnescapedUnicode() tells whether the JSON_UNESCAPE_UNICODE flag is used
 * @method self withPartialOutputOnError() adds the JSON_PARTIAL_OUTPUT_ON_ERROR flag
 * @method self withoutPartialOutputOnError() removes the JSON_PARTIAL_OUTPUT_ON_ERROR flag
 * @method bool usePartialOutputOnError() tells whether the JSON_PARTIAL_OUTPUT_ON_ERROR flag is used
 * @method self withPreserveZeroFraction() adds the JSON_PRESERVE_ZERO_FRACTION flag
 * @method self withoutPreserveZeroFraction() removes the JSON_PRESERVE_ZERO_FRACTION flag
 * @method bool usePreserveZeroFraction() tells whether the JSON_PRESERVE_ZERO_FRACTION flag is used
 * @method self withUnescapedLineTerminators() adds the JSON_UNESCAPED_LINE_TERMINATORS flag
 * @method self withoutUnescapedLineTerminators() removes the JSON_UNESCAPED_LINE_TERMINATORS flag
 * @method bool useUnescapedLineTerminators() tells whether the JSON_UNESCAPED_LINE_TERMINATORS flag is used
 * @method self withInvalidUtf8Ignore() adds the JSON_INVALID_UTF8_IGNORE flag
 * @method self withoutInvalidUtf8Ignore() removes the JSON_INVALID_UTF8_IGNORE flag
 * @method bool useInvalidUtf8Ignore() tells whether the JSON_INVALID_UTF8_IGNORE flag is used
 * @method self withInvalidUtf8Substitute() adds the JSON_INVALID_UTF8_SUBSTITUTE flag
 * @method self withoutInvalidUtf8Substitute() removes the JSON_INVALID_UTF8_SUBSTITUTE flag
 * @method bool useInvalidUtf8Substitute() tells whether the JSON_INVALID_UTF8_SUBSTITUTE flag is used
 * @method self withThrowOnError() adds the JSON_THROW_ON_ERROR flag
 * @method self withoutThrowOnError() removes the JSON_THROW_ON_ERROR flag
 * @method bool useThrowOnError() tells whether the JSON_THROW_ON_ERROR flag is used
 * @method self withBigintAsString() adds the JSON_BIGINT_AS_STRING flag
 * @method self withoutBigintAsString() removes the JSON_BIGINT_AS_STRING flag
 * @method bool useBigintAsString() tells whether the JSON_BIGINT_AS_STRING flag is used
 */
final class Codec
{
    /**
     * @param ?Closure(TMValue, TMKey=): TMReturn $mapper
     * @param ?Closure(TValue, TKey=): TReturn $formatter
     * @param int<1, max> $chunkSize
     * @param int<0, max> $flags
     * @param int<1, max> $depth
     *
     * @throws InvalidNdJsonArgument
     */
    public function __construct(
        public readonly ?Closure $mapper = null,
        public readonly ?Closure $formatter = null,
        public readonly int $chunkSize = 1,
        public readonly int $flags = 0,
        public readonly int $depth = 512,
    ) {
        json_encode(null, $this->flags);
        JSON_ERROR_NONE === ($errorCode = json_last_error()) || throw new InvalidNdJsonArgument('The flags are not valid JSON encoding parameters in PHP; '.json_last_error_msg(), $errorCode);
        1 <= $this->depth || throw new InvalidNdJsonArgument('The depth value must be greater than 0.');
        1 <= $this->chunkSize || throw new InvalidNdJsonArgument('The chunk size must be greater or equal to 1.');
    }

    /**
     * Returns the PHP JSON flag associated with its method suffix to ease method lookup.
     *
     * @return int<0, max>
     */
    private static function methodToFlag(string $method, int $prefixSize): int
    {
        static $suffix2Flag;

        if (null === $suffix2Flag) {
            $suffix2Flag = [];
            /** @var array<string, int> $jsonFlags */
            $jsonFlags = get_defined_constants(true)['json'];
            $jsonEncodeFlags = array_filter(
                $jsonFlags,
                fn (string $key) => 1 !== preg_match('/^(JSON_OBJECT_AS_ARRAY|JSON_ERROR_)(.*)?$/', $key),
                ARRAY_FILTER_USE_KEY
            );

            foreach ($jsonEncodeFlags as $name => $value) {
                $suffix2Flag[str_replace('_', '', ucwords(strtolower(substr($name, 5)), '_'))] = $value;
            }
        }

        return $suffix2Flag[substr($method, $prefixSize)]
            ?? throw new BadMethodCallException('The method "'.self::class.'::'.$method.'" does not exist.');
    }

    /**
     * Adds a list of JSON flags.
     *
     * @param int<0, max> ...$flags
     *
     * @throws InvalidNdJsonArgument
     */
    public function addFlags(int ...$flags): self
    {
        /** @var Closure(int, int): int<0, max> $callback */
        $callback = static fn (int $carry, int $flag): int => $carry | $flag;

        return $this->flags(array_reduce($flags, $callback, $this->flags));
    }

    /**
     * Removes a list of JSON flags.
     *
     * @param int<0, max> ...$flags
     *
     * @throws InvalidNdJsonArgument
     */
    public function removeFlags(int ...$flags): self
    {
        /** @var Closure(int, int): int<0, max> $callback */
        $callback = static fn (int $carry, int $flag): int => $carry & ~$flag;

        return $this->flags(array_reduce($flags, $callback, $this->flags));
    }

    /**
     * JSON encoded flags used during encoding.
     *
     * @param int<0, max> $flags JSON encoding flags (JSON_PRETTY_PRINT will always be ignored)
     *
     * @throws InvalidNdJsonArgument
     */
    private function flags(int $flags): self
    {
        return match ($flags) {
            $this->flags => $this,
            default => new self($this->mapper, $this->formatter, $this->chunkSize, $flags, $this->depth),
        };
    }

    /**
     * Tells whether the flag is being used by the current JsonConverter.
     */
    public function useFlags(int ...$flags): bool
    {
        foreach ($flags as $flag) {
            if (($this->flags & $flag) !== $flag) {
                return false;
            }
        }

        return [] !== $flags;
    }

    /**
     * @param array<int, mixed> $arguments
     *
     * @throws BadMethodCallException|InvalidNdJsonArgument
     */
    public function __call(string $name, array $arguments): self|bool
    {
        return match (true) {
            str_starts_with($name, 'without') => $this->removeFlags(self::methodToFlag($name, 7)),
            str_starts_with($name, 'with') => $this->addFlags(self::methodToFlag($name, 4)),
            str_starts_with($name, 'use') => $this->useFlags(self::methodToFlag($name, 3)),
            default => throw new BadMethodCallException('The method "'.self::class.'::'.$name.'" does not exist.'),
        };
    }

    /**
     * a callback to modify the JSON decoded array.
     *
     * @param ?callable(TMValue, TMKey=): TMReturn $mapper
     *
     * @throws InvalidNdJsonArgument
     */
    public function mapper(?callable $mapper): self
    {
        if ($mapper === $this->mapper) {
            return $this;
        }

        if (null !== $mapper && !$mapper instanceof Closure) {
            $mapper = $mapper(...);
        }

        return new self($mapper, $this->formatter, $this->chunkSize, $this->flags, $this->depth);
    }

    /**
     * a callback to modify the iterable item before it gets converted using json_encode.
     *
     * @param ?callable(TValue, TKey=): TReturn $formatter
     *
     * @throws InvalidNdJsonArgument
     */
    public function formatter(?callable $formatter): self
    {
        if ($formatter === $this->formatter) {
            return $this;
        }

        if (null !== $formatter && !$formatter instanceof Closure) {
            $formatter = $formatter(...);
        }

        return new self($this->mapper, $formatter, $this->chunkSize, $this->flags, $this->depth);
    }

    /**
     * The number of objects that will be converted sequentially using chunks.
     *
     * @param int<1, max> $chunkSize
     *
     * @throws InvalidNdJsonArgument
     */
    public function chunkSize(int $chunkSize): self
    {
        if ($chunkSize === $this->chunkSize) {
            return $this;
        }

        return new self($this->mapper, $this->formatter, $chunkSize, $this->flags, $this->depth);
    }

    /**
     * Sets the json_encode depth recursion parameter.
     *
     * @param int<1, max> $depth
     *
     * @throws InvalidNdJsonArgument
     */
    public function depth(int $depth): self
    {
        if ($depth === $this->depth) {
            return $this;
        }

        return new self($this->mapper, $this->formatter, $this->chunkSize, $this->flags, $depth);
    }

    /************************
     * Generic Codec Methods
     ***********************/

    /**
     * Encode your iterable structure.
     *
     * Each item from your iterable structure must be encodable using `json_encode`
     *
     * @param iterable<TValue> $value
     * @param array<int, string>|int<0, max> $headerOrOffset
     *
     * @throws NdJsonException
     */
    public function encode(iterable $value, Format $format = Format::Record, array|int $headerOrOffset = []): string
    {
        return self::encoder()->encode($this->prepare($value, $format, $headerOrOffset));
    }

    /**
     * Converts and download the JSON in an HTTP client (i.e., web browser).
     *
     * @param iterable<TValue> $value
     * @param array<int, string>|int<0, max> $headerOrOffset
     *
     * @throws NdJsonException
     */
    public function download(iterable $value, ?string $filename = null, Format $format = Format::Record, array|int $headerOrOffset = []): int
    {
        return self::encoder()->download($this->prepare($value, $format, $headerOrOffset), $filename);
    }

    /**
     * Converts and download the JSON in an HTTP client (i.e., web browser).
     *
     * @param iterable<TValue> $value
     * @param array<int, string>|int<0, max> $headerOrOffset
     *
     * @throws NdJsonException
     *
     * @return Iterator<string>x
     */
    public function chunk(iterable $value, Format $format = Format::Record, array|int $headerOrOffset = []): Iterator
    {
        return self::encoder()->convert($this->prepare($value, $format, $headerOrOffset));
    }

    /**
     * Store your iterable structure in a file.
     *
     * @param iterable<TValue> $value
     * @param FileDescriptor $to
     * @param array<int, string>|int<0, max> $headerOrOffset
     * @param ?resource $context
     *
     * @throws NdJsonException
     */
    public function write(
        iterable $value,
        mixed $to,
        Format $format = Format::Record,
        array|int $headerOrOffset = [],
        $context = null,
    ): int {
        return self::encoder()->write($this->prepare($value, $format, $headerOrOffset), $to, $context);
    }

    /************************
     * Tabular Data Methods
     ***********************/

    /**
     * Returns an Iterator representing the NDJSON string as an Iterator object.
     *
     * @param array<int, string>|int<0, max> $headerOrOffset
     *
     * @throws NdJsonException
     */
    public function decode(Stringable|string $ndjson, Format $format = Format::Record, array|int $headerOrOffset = []): Iterator
    {
        /** @var resource $tmp */
        $tmp = fopen('php://temp', 'r+');
        fwrite($tmp, (string) $ndjson);
        rewind($tmp);

        return $this->read(from: $tmp, format: $format, headerOrOffset: $headerOrOffset);
    }

    /**
     * @param FileDescriptor $from
     * @param array<int, string>|int<0, max> $headerOrOffset
     * @param ?resource $context
     *
     * @throws NdJsonException
     */
    public function read(mixed $from, Format $format = Format::Record, array|int $headerOrOffset = [], $context = null): Iterator
    {
        $iterator = self::decoder()($from, $this->flags, $this->depth, $context);
        [$header, $headerOffsetFound] = is_array($headerOrOffset) ? self::resolveHeader(data: $iterator, header: $headerOrOffset) : self::resolveHeader(data: $iterator, header:[], offset: $headerOrOffset);

        null !== $headerOffsetFound
        || [] !== $header
        || Format::ListWithHeader !== $format
        || throw new InvalidNdJsonArgument('a valid header or headerOffset must be provided with the `Format::ListWithHeader` format.');

        if ((null !== $headerOffsetFound) && Format::ListWithHeader === $format) {
            $iterator = new CallbackFilterIterator($iterator, fn ($record, $offset) => $offset !== $headerOrOffset || !array_is_list($record));
        }

        if ([] === $header) {
            if (Format::List === $format) {
                $iterator = MapIterator::fromIterable($iterator, fn (mixed $record): array => is_array($record) ? array_values($record) : throw new InvalidNdJsonArgument('The record is expected to be an array or an iterable; '.gettype($record).' given.'));
            }

            if (null === $this->mapper) {
                return $iterator;
            }

            $mapper = $this->mapper;
            return new MapIterator($iterator, function ($value, $offset) use ($mapper) {
                try {
                    return $mapper($value, $offset);
                } catch (Exception $exception) {
                    if ($exception instanceof DecodingNdJsonFailed) {
                        throw $exception;
                    }

                    throw new DecodingNdJsonFailed(
                        message: 'Unable to map the record: '.$exception->getMessage(),
                        value: $value,
                        offset: $offset,
                        previous: $exception
                    );
                }
            });
        }

        [] === array_filter($header, fn ($value) => !is_scalar($value)) || throw new InvalidNdJsonArgument('The header mapper must only contain scalar values.'); /* @phpstan-ignore-line */
        array_unique($header) === $header || throw new InvalidNdJsonArgument('The header must contain unique values.');
        [] === array_filter(array_keys($header), fn ($value) => $value < 0) || throw new InvalidNdJsonArgument('The header mapper indexes should only contain positive integer or 0.');

        return new MapIterator($iterator, function (array $record, $offset) use ($header, $format): mixed {
            $assocRecord = [];
            $row = array_values($record);
            foreach ($header as $index => $headerName) {
                $assocRecord[$headerName] = $row[$index] ?? null;
            }

            if (Format::List === $format) {
                $assocRecord = array_values($assocRecord);
            }

            if (null === $this->mapper) {
                return $assocRecord;
            }

            try {
                return ($this->mapper)($assocRecord, $offset);
            } catch (Exception $exception) {
                throw new DecodingNdJsonFailed(
                    message: 'Unable to map the json record: '.$exception->getMessage(),
                    value: $record,
                    offset: $offset,
                    previous: $exception
                );
            }
        });
    }

    private function encoder(): Encoder
    {
        return new Encoder($this->flags, $this->depth, $this->chunkSize);
    }

    private static function decoder(): Decoder
    {
        static $decoder;

        $decoder ??= new Decoder();

        return $decoder;
    }

    /**
     * @param iterable<TValue> $data
     * @param array<int, string>|int<0, max> $headerOrOffset
     *
     * @throws InvalidNdJsonArgument
     *
     * @return iterable<TValue|TReturn>
     */
    private function prepare(
        iterable $data,
        Format $format,
        array|int $headerOrOffset,
    ): iterable {
        $formatter = null;
        if (null !== $this->formatter) {
            $callback = $this->formatter;
            $formatter = function ($value, $offset) use ($callback) {
                try {
                    return $callback($value, $offset);
                } catch (Exception $exception) {
                    if ($exception instanceof EncodingNdJsonFailed) {
                        throw $exception;
                    }

                    throw new EncodingNdJsonFailed(
                        'Unable to format data: '.$exception->getMessage(),
                        value: $value,
                        offset: $offset,
                        previous: $exception
                    );
                }
            };
        }

        $records = MapIterator::fromIterable($data, $formatter); /* @phpstan-ignore-line */
        if (Format::Record === $format) {
            return $records;
        }

        if (Format::List === $format) {
            return MapIterator::fromIterable($records, fn (mixed $record): array => match (true) {
                is_array($record) => array_values($record),
                is_iterable($record) => iterator_to_array($record, false),
                default => throw new EncodingNdJsonFailed('Unable to convert record into an array.'),
            });
        }

        $header = self::header($records, $format, $headerOrOffset);
        [] !== $header || throw new InvalidNdJsonArgument('A non empty header must be provided when using `Format::ArrayWithHeader`.');

        $data = (function () use ($header, $records) {
            yield $header;

            yield from MapIterator::fromIterable($records, fn (mixed $record): array => match (true) {
                is_array($record) => array_values($record),
                is_iterable($record) => iterator_to_array($record, false),
                default => throw new EncodingNdJsonFailed('Unable to convert record into an array.'),
            });
        })();

        return $data;
    }

    /**
     * @param iterable<TValue> $data
     * @param list<string>|int $header
     *
     * @throws InvalidNdJsonArgument
     *
     * @return list<string>
     */
    private static function header(iterable $data, Format $format, array|int $header): array
    {
        $headerOffset = null;
        if (is_int($header)) {
            $headerOffset = $header;
            $header = [];
        }

        [$foundHeader] = self::resolveHeader($data, $header, $headerOffset);
        if ([] !== $foundHeader) {
            return $foundHeader;
        }

        [] !== $header
        || Format::ListWithHeader !== $format
        || throw new InvalidNdJsonArgument('A non empty header must be provided when using the `Format::ArrayWithHeader` format.');

        return $header;
    }

    /**
     * @param iterable<TValue> $data
     * @param array<string|int|float|bool> $header
     *
     * @throws InvalidNdJsonArgument
     *
     * @return array{0: list<string>, 1: int|null}
     */
    private static function resolveHeader(iterable $data, array $header, ?int $offset = null): array
    {
        if ([] !== $header) {
            $header === array_filter($header, is_string(...)) || throw new InvalidNdJsonArgument('The header must contain scalar values only.');

            return [$header, $offset];
        }

        if (null === $offset) {
            return [$header, $offset];
        }

        0 <= $offset || throw new InvalidNdJsonArgument('Invalid header option, header must be an integer greater or equals to `0`.');

        $row = self::extractHeader($data, $offset);
        if ([] === $row) {
            return [$row, $offset];
        }

        if (array_is_list($row)) {
            return [$row, $offset];
        }

        return [array_keys($row), $offset];
    }

    /**
     * @param iterable<TValue> $data
     * @param int<0, max> $header
     *
     * @return array<mixed>
     */
    private static function extractHeader(iterable $data, int $header): array
    {
        if (is_array($data)) {
            $row = $data[$header] ?? [];
            if (!is_array($row)) {
                return [];
            }

            return $row;
        }

        if ($data instanceof IteratorAggregate) {
            $data = $data->getIterator();
        }

        $data instanceof Iterator || throw new InvalidNdJsonArgument('Can not determine the header from a Traversable object.');

        $it = new LimitIterator($data, $header, 1);
        $it->rewind();
        /** @var ?array<mixed> $row */
        $row = $it->current();

        if (!is_array($row)) {
            return [];
        }

        return $row;
    }
}
