<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use ErrorException;
use Exception;
use Iterator;
use JsonException;
use SplFileInfo;
use SplFileObject;

use function filter_var;
use function header;
use function json_encode;
use function json_last_error;
use function preg_replace_callback;
use function rawurlencode;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function strtolower;

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
 * @phpstan-import-type FileDescriptor from Decoder
 */
final class Encoder
{
    /**
     * @param int<0, max> $flags
     * @param int<1, max> $depth
     * @param int<1, max> $chunkSize
     *
     * @throws InvalidNdJsonArgument
     */
    public function __construct(
        public readonly int $flags = 0,
        public readonly int $depth = 512,
        public readonly int $chunkSize = 1,
    ) {
        json_encode(null, $this->flags & ~JSON_THROW_ON_ERROR);
        JSON_ERROR_NONE === json_last_error() || throw new InvalidNdJsonArgument('The flags options are invalid.');
        1 <= $this->depth || throw new InvalidNdJsonArgument('The depth option is invalid; it must be greater or equal to 0.');
        1 <= $this->chunkSize || throw new InvalidNdJsonArgument('The chunk size option is invalid; it must be greater or equal to 1.');
    }

    /**
     * @param iterable<TValue> $data
     *
     * @throws InvalidNdJsonArgument|EncodingNdJsonFailed
     *
     * @return Iterator<string>
     */
    public function convert(iterable $data): Iterator
    {
        $iteration = 0;
        $ndjson = '';
        $flags = ($this->flags | JSON_THROW_ON_ERROR) & ~(JSON_PRETTY_PRINT | JSON_FORCE_OBJECT);
        $asData = false;
        foreach ($data as $offset => $row) {
            $asData = true;
            try {
                $ndjson .= json_encode($row, $flags, $this->depth)."\n";
            } catch (JsonException $exception) {
                throw new EncodingNdJsonFailed(
                    message: 'Unable to encode data: '.$exception->getMessage(),
                    value: $row,
                    offset: $offset,
                    previous:  $exception
                );
            }

            ++$iteration;
            if ($iteration === $this->chunkSize) {
                yield $ndjson;

                $ndjson = '';
                $iteration = 0;
            }
        }

        if ('' !== $ndjson) {
            yield $ndjson;
        }

        if (false === $asData) {
            yield '[]';
        }
    }

    /**
     * @param iterable<TValue> $value
     * @param FileDescriptor $to
     * @param ?resource $context
     *
     * @throws EncodingNdJsonFailed
     * @throws InvalidNdJsonArgument
     * @throws StreamException
     */
    public function write(iterable $value, mixed $to, $context = null): int
    {
        $stream = match (true) {
            $to instanceof SplFileObject => $to,
            $to instanceof SplFileInfo => $to->openFile(mode:'wb', context: $context),
            default => Stream::from($to, 'wb', $context),
        };

        try {
            $line = null;
            $offset = -1;
            $bytes = 0;
            set_error_handler(
                fn (int $errno, string $errstr, string $errfile, int $errline): bool =>
                E_WARNING === $errno
                    ? throw new ErrorException($errstr, 0, $errno, $errfile, $errline)
                    : false
            );

            foreach ($this->convert($value) as $offset => $line) { /* @phpstan-ignore-line */
                $bytes += $stream->fwrite($line);  /* @phpstan-ignore-line */
            }

            return $bytes;
        } catch (Exception $exception) {
            if ($exception instanceof EncodingNdJsonFailed) {
                throw $exception;
            }

            throw new EncodingNdJsonFailed(
                message: 'Unable to write to the destination path `'.$stream->getPathname().'`.',
                value: $line,
                offset: $offset,
                previous: $exception
            );
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param iterable<TValue> $value
     *
     * @throws EncodingNdJsonFailed
     * @throws InvalidNdJsonArgument
     * @throws StreamException
     */
    public function encode(iterable $value): string
    {
        /** @var resource $file */
        $file = @fopen('php://temp', 'r+');
        $this->write(value: $value, to: $file);
        rewind($file);

        return (string) stream_get_contents($file);
    }

    /**
     * @param iterable<TValue> $value
     *
     * @throws EncodingNdJsonFailed
     * @throws InvalidNdJsonArgument
     * @throws StreamException
     */
    public function download(iterable $value, ?string $filename = null): int
    {
        self::downloadHeaders($filename);

        return $this->write($value, new SplFileObject('php://output', 'wb'));
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
