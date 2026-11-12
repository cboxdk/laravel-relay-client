<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * One page of a cursor-paginated list. Pass `$nextCursor` back as `cursor:` for the next
 * page; it is null on the last one. `new Page` is a valid, empty last page.
 *
 * @template-covariant T
 *
 * @implements IteratorAggregate<int, T>
 */
readonly class Page implements Countable, IteratorAggregate
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items = [],
        public ?string $nextCursor = null,
    ) {}

    /**
     * @template TItem
     *
     * @param  array<array-key, mixed>  $data  a `{"data": […], "next_cursor": …}` body
     * @param  callable(array<array-key, mixed>): TItem  $item
     * @return self<TItem>
     */
    public static function fromArray(array $data, callable $item): self
    {
        return new self(
            array_map($item, Payload::maps($data, 'data')),
            Payload::nullableString($data, 'next_cursor'),
        );
    }

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
