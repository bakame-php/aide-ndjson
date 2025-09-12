<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use SeekableIterator;
use SplFileObject;
use ValueError;

use function fclose;
use function feof;
use function fopen;
use function fseek;
use function fwrite;
use function get_resource_type;
use function gettype;
use function is_resource;
use function is_string;
use function rewind;
use function stream_get_meta_data;
use function trim;

use const SEEK_SET;

/**
 * An object-oriented API to handle a PHP stream resource.
 *
 * @internal used internally to iterate over a stream resource
 */
final class Stream implements SeekableIterator
{
    /**
     * @param resource $stream stream type resource
     */
    private function __construct(
        private $stream,
        private readonly bool $closeStreamOnDestruct,
        private readonly bool $isSeekable,
        private mixed $value = false,
        private int $offset = -1,
        private int $flags = 0,
    ) {
    }

    /**
     * Returns a new instance from a file descriptor.
     *
     * @param resource|non-empty-string $filename
     * @param non-empty-string $mode
     * @param resource|null $context
     */
    public static function from(mixed $filename, string $mode = 'r', $context = null): self
    {
        $closeStreamOnDestruct = false;
        if (is_string($filename)) {
            '' !== trim($filename) || throw new ValueError('The path cannot be empty.');
            $resource = @fopen(filename: $filename, mode: $mode, context: $context);
            is_resource($resource) || throw new ValueError('`'.$filename.'`: failed to open stream: No such file or directory.');
            $closeStreamOnDestruct = true;
            $filename = $resource;
        }

        is_resource($filename) || throw new ValueError('Argument passed must be a stream resource, '.gettype($filename).' given.');
        'stream' === ($type = get_resource_type($filename)) || throw new ValueError('Argument passed must be a stream resource, '.$type.' resource given');

        return new self($filename, $closeStreamOnDestruct, stream_get_meta_data($filename)['seekable']);
    }

    public function __destruct()
    {
        if ($this->closeStreamOnDestruct && is_resource($this->stream)) {
            fclose($this->stream);
        }

        unset($this->stream);
    }

    public function __clone(): void
    {
        throw new StreamException('An object of class '.self::class.' cannot be cloned.');
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
     * Sets stream flags.
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
        if (-1 === $this->offset) {
            $this->rewind();
        }

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
     * @throws StreamException if the stream resource is not seekable or if rewinding, the stream fails.
     */
    public function rewind(): void
    {
        $this->isSeekable || throw new StreamException('stream does not support seeking.');
        false !== rewind($this->stream) || throw new StreamException('Unable to rewind the document.');

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
        if (-1 === $this->offset) {
            $this->rewind();
        }

        if (false === $this->value) {
            $this->value = $this->getCurrentLine();
        }

        return $this->value;
    }

    /**
     * Retrieves the current line.
     */
    private function getCurrentLine(): string|false
    {
        $isEmptyLine = SplFileObject::SKIP_EMPTY === ($this->flags & SplFileObject::SKIP_EMPTY);
        $dropNewLine = SplFileObject::DROP_NEW_LINE === ($this->flags & SplFileObject::DROP_NEW_LINE);
        $shouldBeIgnored = fn (string|false $line): bool => ($isEmptyLine || $dropNewLine) && (false !== $line && '' === rtrim($line, "\r\n"));

        do {
            $line = fgets($this->stream);
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
        $data = @fwrite(stream: $this->stream, data: $str, length: $length);
        false !== $data || throw new StreamException(__METHOD__.'() can\'t write to stream.');

        return $data;
    }
}
