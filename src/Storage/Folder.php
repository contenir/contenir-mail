<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use IteratorAggregate;
use Override;
use Stringable;

/**
 * A mail folder (mailbox) and its subfolders.
 *
 * The local name is the folder's name within its parent; the global name
 * is its full name from the root, with the storage's delimiters, and is
 * what selectFolder() takes. A folder that is not selectable only holds
 * other folders. An IMAP folder may also be marked with what it is for,
 * such as the folder sent mail is kept in (RFC 6154).
 *
 * @implements IteratorAggregate<string, Folder>
 *
 * @api
 */
final class Folder implements IteratorAggregate, Stringable
{
    private readonly string $globalName;

    /** @var array<string, Folder> by local name */
    private array $folders = [];

    /**
     * @param string $globalName The full name; the local name when empty.
     * @param iterable<Folder> $folders
     * @param SpecialUse|null $specialUse What the folder is for, when the server says.
     */
    public function __construct(
        private readonly string $localName,
        string $globalName = '',
        private readonly bool $selectable = true,
        iterable $folders = [],
        private readonly ?SpecialUse $specialUse = null,
    ) {
        $this->globalName = '' === $globalName ? $localName : $globalName;
        foreach ($folders as $folder) {
            $this->addFolder($folder);
        }
    }

    public function getLocalName(): string
    {
        return $this->localName;
    }

    public function getGlobalName(): string
    {
        return $this->globalName;
    }

    public function isSelectable(): bool
    {
        return $this->selectable;
    }

    /**
     * What the folder is for, such as SpecialUse::Sent, when the server marks it (RFC 6154); null otherwise.
     */
    public function getSpecialUse(): ?SpecialUse
    {
        return $this->specialUse;
    }

    /**
     * Whether the folder has no subfolders.
     */
    public function isLeaf(): bool
    {
        return [] === $this->folders;
    }

    public function hasFolder(string $localName): bool
    {
        return null !== ($this->folders[$localName] ?? null);
    }

    /**
     * @throws Exception\InvalidArgumentException When there is no subfolder of that name.
     */
    public function getFolder(string $localName): self
    {
        return (
            $this->folders[$localName] ?? throw new Exception\InvalidArgumentException(
                "No subfolder named {$localName}",
            )
        );
    }

    /**
     * @return array<string, Folder> by local name
     */
    public function getFolders(): array
    {
        return $this->folders;
    }

    /**
     * Add or replace a subfolder, by its local name.
     *
     * @internal Storages keep their folder tree up to date with this.
     */
    public function addFolder(self $folder): void
    {
        $this->folders[$folder->getLocalName()] = $folder;
    }

    /**
     * @internal Storages keep their folder tree up to date with this.
     */
    public function removeFolder(string $localName): void
    {
        unset($this->folders[$localName]);
    }

    /**
     * The subfolders by local name; usable with RecursiveIteratorIterator to walk the tree.
     *
     * @return TreeIterator<string, Folder>
     */
    #[Override]
    public function getIterator(): TreeIterator
    {
        return new TreeIterator(
            $this->folders,
            /** @return array<string, Folder> */ static fn(self $folder): array => $folder->getFolders(),
        );
    }

    /**
     * The global name.
     */
    #[Override]
    public function __toString(): string
    {
        return $this->globalName;
    }
}
