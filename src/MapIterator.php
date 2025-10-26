<?php

declare(strict_types=1);

namespace Bakame\Aide\NdJson;

use ArrayIterator;
use Exception;
use Iterator;
use IteratorAggregate;
use IteratorIterator;
use Override;
use Traversable;

/**
 * @template TKey
 * @template TValue
 * @template TReturn
 */
final class MapIterator extends IteratorIterator
{
    /** @var ?callable(TValue, TKey=): TReturn */
    private $callable;

    /**
     * @param Traversable<TValue> $iterator
     */
    public function __construct(Traversable $iterator, ?callable $callable = null)
    {
        parent::__construct($iterator);
        $this->callable = $callable;
    }

    #[Override]
    public function current(): mixed
    {
        return null === $this->callable ? parent::current() : ($this->callable)(parent::current(), parent::key());
    }

    /**
     * @param iterable<TKey, TValue> $value
     * @param ?callable(TValue, TKey=): TReturn $callable
     *
     * @throws Exception
     * @return Iterator<TReturn|TValue>
     */
    public static function fromIterable(iterable $value, ?callable $callable = null): Iterator
    {
        return new self(match (true) {
            $value instanceof IteratorAggregate => $value->getIterator(),
            $value instanceof Iterator => $value,
            $value instanceof Traversable => (function () use ($value): Iterator {
                foreach ($value as $offset => $record) {
                    yield $offset => $record;
                }
            })(),
            default => new ArrayIterator($value), /* @phpstan-ignore-line */
        }, $callable);
    }
}
