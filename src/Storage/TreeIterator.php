<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use ArrayIterator;
use Closure;
use Override;
use RecursiveIterator;

/**
 * Walks a tree of parts or folders, for use with RecursiveIteratorIterator.
 *
 * @template K of array-key
 * @template T of object
 * @extends ArrayIterator<K, T>
 * @implements RecursiveIterator<K, T>
 *
 * @internal Returned by Part::getIterator() and Folder::getIterator().
 */
final class TreeIterator extends ArrayIterator implements RecursiveIterator
{
    /** @var Closure(T): array<K, T> */
    private readonly Closure $children;

    /**
     * @param array<K, T> $items
     * @param Closure(T): array<K, T> $children
     */
    public function __construct(array $items, Closure $children)
    {
        parent::__construct($items);
        $this->children = $children;
    }

    #[Override]
    public function hasChildren(): bool
    {
        return [] !== $this->currentChildren();
    }

    /**
     * @return TreeIterator<K, T>
     */
    #[Override]
    public function getChildren(): self
    {
        return new self($this->currentChildren(), $this->children);
    }

    /**
     * @return array<K, T>
     */
    private function currentChildren(): array
    {
        $current = $this->current();

        return null === $current ? [] : ($this->children)($current);
    }
}
