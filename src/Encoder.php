<?php

/**
 * League.Csv (https://csv.thephpleague.com).
 *
 * (c) Ignace Nyamagana Butera <nyamsprod@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use Closure;
use ErrorException;
use Exception;
use Iterator;
use JsonException;
use League\Csv\Stream;
use League\Csv\TabularData;
use League\Csv\TabularDataProvider;
use League\Csv\UnavailableStream;
use SplFileInfo;
use SplFileObject;
use ValueError;

use function filter_var;
use function header;
use function in_array;
use function is_resource;
use function is_string;
use function json_encode;
use function json_last_error;
use function preg_replace_callback;
use function rawurlencode;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function strtolower;

use const E_USER_WARNING;
use const E_WARNING;
use const FILTER_FLAG_STRIP_HIGH;
use const FILTER_FLAG_STRIP_LOW;
use const FILTER_UNSAFE_RAW;
use const JSON_ERROR_NONE;
use const JSON_FORCE_OBJECT;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

/**
 * @internal Generic NDJSON encoder
 *
 * @template TKey
 * @template TValue
 * @template TReturn
 *
 * @phpstan-import-type Path from Decoder
 */
final class Encoder
{
    /* @var ?Closure(TValue, TKey=): TReturn */
    public readonly ?Closure $formatter;

    /**
     * @param int<0, max> $flags
     * @param int<1, max> $depth
     * @param int<1, max> $chunkSize
     * @param ?callable(TValue, TKey=): TReturn $formatter
     *
     * @throws InvalidNdJsonArgument
     */
    public function __construct(
        public readonly int $flags = 0,
        public readonly int $depth = 512,
        public readonly int $chunkSize = 1,
        ?callable $formatter = null,
    ) {
        json_encode(null, $this->flags & ~JSON_THROW_ON_ERROR);
        JSON_ERROR_NONE === json_last_error() || throw new InvalidNdJsonArgument('The flags options are invalid.');
        1 <= $this->depth || throw new InvalidNdJsonArgument('The depth option is invalid; it must be greater or equal to 0.');
        1 <= $this->chunkSize || throw new InvalidNdJsonArgument('The chunk size option is invalid; it must be greater or equal to 1.');

        if (!$formatter instanceof Closure && null !== $formatter) {
            $formatter = $formatter(...);
        }

        $this->formatter = $formatter;
    }

    /**
     * @param TabularDataProvider|TabularData|iterable<TValue> $data
     *
     * @throws InvalidNdJsonArgument|EncodingNdJsonFailed
     *
     * @return Iterator<string>
     */
    public function convert(TabularDataProvider|TabularData|iterable $data): Iterator
    {
        $iteration = 0;
        $ndjson = '';
        try {
            $asData = false;
            foreach (MapIterator::fromIterable($data, $this->formatter) as $row) {
                $asData = true;
                $ndjson .= json_encode(
                    value: $row,
                    flags: ($this->flags | JSON_THROW_ON_ERROR) & ~(JSON_PRETTY_PRINT | JSON_FORCE_OBJECT),
                    depth: $this->depth
                )."\n";
                ++$iteration;
                if ($iteration === $this->chunkSize) {
                    yield $ndjson;

                    $ndjson = '';
                    $iteration = 0;
                }
            }
        } catch (JsonException $exception) {
            throw new EncodingNdJsonFailed('Unable to encode data: '.$exception->getMessage(), previous: $exception);
        }

        if ('' !== $ndjson) {
            yield $ndjson;
        }

        if (false === $asData) {
            yield '[]';
        }
    }

    /**
     * @param TabularDataProvider|TabularData|iterable<TValue> $data
     * @param Path $to
     * @param ?resource $context
     *
     * @throws EncodingNdJsonFailed
     * @throws InvalidNdJsonArgument
     * @throws UnavailableStream
     * @throws ValueError
     */
    public function write(TabularDataProvider|TabularData|iterable $data, mixed $to, $context = null): int
    {
        try {
            $stream = match (true) {
                $to instanceof Stream,
                $to instanceof SplFileObject => $to,
                $to instanceof SplFileInfo => $to->openFile(mode:'wb', context: $context),
                is_resource($to) => Stream::createFromResource($to),
                is_string($to) => Stream::createFromPath($to, 'wb', $context),
                default => throw new ValueError('The destination path must be a filename, a stream or a SplFileInfo object.'),
            };
        } catch (Exception $exception) {
            throw new InvalidNdJsonArgument('Failed to load the destination path:'.$exception->getMessage(), previous: $exception);
        }

        try {
            $bytes = 0;
            set_error_handler(
                fn (int $errno, string $errstr, string $errfile, int $errline): bool =>
                in_array($errno, [E_WARNING, E_USER_WARNING], true)
                    ? throw new ErrorException($errstr, 0, $errno, $errfile, $errline)
                    : false
            );

            foreach ($this->convert($data) as $line) {
                $bytes += $stream->fwrite($line);
            }

            return $bytes;
        } catch (ErrorException $exception) {
            throw new EncodingNdJsonFailed('Unable to write to the destination path `'.$stream->getPathname().'`.', previous: $exception);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param TabularDataProvider|TabularData|iterable<TValue> $data
     *
     * @throws EncodingNdJsonFailed
     * @throws InvalidNdJsonArgument
     * @throws UnavailableStream
     */
    public function encode(TabularDataProvider|TabularData|iterable $data): string
    {
        $stream = Stream::createFromString();
        $this->write(data: $data, to: $stream);
        $stream->rewind();

        return (string) $stream->getContents();
    }

    /**
     * @param TabularDataProvider|TabularData|iterable<TValue> $data
     *
     * @throws EncodingNdJsonFailed
     * @throws InvalidNdJsonArgument
     * @throws UnavailableStream
     */
    public function download(TabularDataProvider|TabularData|iterable $data, ?string $filename = null): int
    {
        self::downloadHeaders($filename);

        return $this->write($data, new SplFileObject('php://output', 'wb'));
    }

    private static function downloadHeaders(?string $filename): void
    {
        if (null === $filename) {
            return;
        }

        (! str_contains($filename, '/') && ! str_contains($filename, '\\')) || throw new InvalidNdJsonArgument('The filename `'.$filename.'` cannot contain the "/" or "\" characters.');

        /** @var string $filteredName */
        $filteredName = filter_var($filename, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH);
        $fallbackName = str_replace('%', '', $filteredName);
        $disposition = 'attachment;filename="'.str_replace('"', '\\"', $fallbackName).'"';
        if ($filename !== $fallbackName) {
            $disposition .= ";filename*=UTF-8''".preg_replace_callback(
                '/[%"\x00-\x1F\x7F-\xFF]/',
                static fn (array $matches): string => strtolower(rawurlencode($matches[0])),
                $filename
            );
        }

        header('content-type: application/x-ndjson; charset=utf-8');
        header('content-transfer-encoding: binary');
        header('content-description: File Transfer');
        header('content-disposition: '.$disposition);
    }
}
