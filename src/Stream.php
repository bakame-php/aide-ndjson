<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use RuntimeException;
use SeekableIterator;
use SplFileObject;
use Stringable;
use ValueError;

use function fclose;
use function feof;
use function fopen;
use function fseek;
use function fwrite;
use function get_resource_type;
use function gettype;
use function is_resource;
use function rewind;
use function stream_get_meta_data;

use const SEEK_SET;

/**
 * An object-oriented API to handle a PHP stream resource.
 *
 * @internal used internally to iterate over a stream resource
 */
final class Stream implements SeekableIterator
{
    /** @var resource */
    private $stream;
    private bool $isSeekable;
    private bool $shouldCloseStream = false;
    /** @var mixed can be a null, false or a scalar type value. Current iterator value. */
    private mixed $value = null;
    /** Current iterator key. */
    private int $offset = -1;
    /** Flags for the Document. */
    private int $flags = 0;
    private int $maxLength = 0;

    /**
     * @param resource $stream stream type resource
     */
    private function __construct($stream)
    {
        $this->isSeekable = stream_get_meta_data($stream)['seekable'];
        $this->stream = $stream;
    }

    public function __destruct()
    {
        if ($this->shouldCloseStream && is_resource($this->stream)) {
            fclose($this->stream);
        }

        unset($this->stream);
    }

    public function __clone(): void
    {
        throw new StreamException('An object of class '.self::class.' cannot be cloned.');
    }

    public function __debugInfo(): array
    {
        return stream_get_meta_data($this->stream);
    }

    public function ftell(): int|false
    {
        return ftell($this->stream);
    }

    /**
     * Returns a new instance from a file path.
     *
     * @param resource|null $context
     *
     * @throws StreamException if the stream resource cannot be created
     */
    public static function fromPath(
        string $path,
        string $open_mode = 'r',
        $context = null
    ): self {
        $resource = @fopen(filename: $path, mode: $open_mode, context: $context);
        is_resource($resource) || throw new StreamException('`'.$path.'`: failed to open stream: No such file or directory.');

        $instance = new self($resource);
        $instance->shouldCloseStream = true;

        return $instance;
    }

    /**
     * Returns a new instance from a string.
     */
    public static function fromString(Stringable|string $content = ''): self
    {
        /** @var resource $resource */
        $resource = @fopen('php://temp', 'r+');
        fwrite($resource, (string) $content);

        $instance = new self($resource);
        $instance->shouldCloseStream = true;

        return $instance;
    }

    public static function fromResource(mixed $stream): self
    {
        return match (true) {
            !is_resource($stream) => throw new ValueError('Argument passed must be a stream resource, '.gettype($stream).' given.'),
            'stream' !== ($type = get_resource_type($stream)) => throw new ValueError('Argument passed must be a stream resource, '.$type.' resource given'),
            default => new self($stream),
        };
    }

    /**
     * Returns the URI of the underlying stream.
     *
     * @see https://www.php.net/manual/en/splfileinfo.getpathname.php
     */
    public function getPathname(): string
    {
        return stream_get_meta_data($this->stream)['uri'];
    }

    /**
     * Sets CSV stream flags.
     *
     * @see https://www.php.net/manual/en/splfileobject.setflags.php
     */
    public function setFlags(int $flags): void
    {
        $this->flags = $flags;
    }

    /**
     * Gets line number.
     *
     * @see https://www.php.net/manual/en/splfileobject.key.php
     */
    public function key(): int
    {
        return $this->offset;
    }

    /**
     * Reads next line.
     *
     * @see https://www.php.net/manual/en/splfileobject.next.php
     */
    public function next(): void
    {
        $this->value = false;
        $this->offset++;
    }

    /**
     * Rewinds the file to the first line.
     *
     * @see https://www.php.net/manual/en/splfileobject.rewind.php
     *
     * @throw UnavailableStream if the stream resource is not seekable
     * @throws RuntimeException if rewinding the stream fails.
     */
    public function rewind(): void
    {
        $this->isSeekable || throw new StreamException('stream does not support seeking.');
        false !== rewind($this->stream) || throw new RuntimeException('Unable to rewind the document.');

        $this->offset = 0;
        $this->value = false;
        if (SplFileObject::READ_AHEAD === ($this->flags & SplFileObject::READ_AHEAD)) {
            $this->current();
        }
    }

    /**
     * Not at EOF.
     *
     * @see https://www.php.net/manual/en/splfileobject.valid.php
     */
    public function valid(): bool
    {
        return match (true) {
            SplFileObject::READ_AHEAD === ($this->flags & SplFileObject::READ_AHEAD) => false !== $this->current(),
            default => !feof($this->stream),
        };
    }

    /**
     * Retrieves the current line of the file.
     *
     * @see https://www.php.net/manual/en/splfileobject.current.php
     */
    public function current(): mixed
    {
        if (false !== $this->value) {
            return $this->value;
        }

        $this->value = $this->getCurrentLine();

        return $this->value;
    }

    /**
     * Tells whether the end of the file has been reached.
     *
     * @see https://www.php.net/manual/en/splfileobject.eof.php
     */
    public function eof(): bool
    {
        return feof($this->stream);
    }

    /**
     * Retrieves the current line.
     */
    private function getCurrentLine(): string|false
    {
        $isEmptyLine = SplFileObject::SKIP_EMPTY === ($this->flags & SplFileObject::SKIP_EMPTY);
        $dropNewLine = SplFileObject::DROP_NEW_LINE === ($this->flags & SplFileObject::DROP_NEW_LINE);
        $shouldBeIgnored = fn (string|false $line): bool => ($isEmptyLine || $dropNewLine) && (false !== $line && '' === rtrim($line, "\r\n"));
        $arguments = [$this->stream];
        if (0 < $this->maxLength) {
            $arguments[] = $this->maxLength;
        }

        do {
            $line = fgets(...$arguments);
        } while ($shouldBeIgnored($line));

        if ($dropNewLine && false !== $line) {
            return rtrim($line, "\r\n");
        }

        return $line;
    }

    /**
     * Seeks to specified line.
     *
     * @see https://www.php.net/manual/en/splfileobject.seek.php
     *
     * @throw UnavailableStream if the position is negative
     */
    public function seek(int $offset): void
    {
        $offset >= 0 || throw new StreamException(__METHOD__.'() can\'t seek stream to negative line '.$offset);

        $this->rewind();
        while ($this->key() !== $offset && $this->valid()) {
            $this->current();
            $this->next();
        }

        if (0 !== $offset) {
            $this->offset--;
        }

        $this->current();
    }

    /**
     * Seeks to a position.
     *
     * @see https://www.php.net/manual/en/splfileobject.fseek.php
     *
     * @throws StreamException if the stream resource is not seekable
     */
    public function fseek(int $offset, int $whence = SEEK_SET): int
    {
        $this->isSeekable || throw new StreamException('stream does not support seeking.');

        return fseek($this->stream, $offset, $whence);
    }

    /**
     * Write to stream.
     *
     * @see http://php.net/manual/en/SplFileObject.fwrite.php
     */
    public function fwrite(string $str, ?int $length = null): int|false
    {
        null === $length || 0 <= $length || throw new StreamException(__METHOD__.'() can\'t write to stream.');

        return fwrite(stream: $this->stream, data: $str, length: $length);
    }
}
