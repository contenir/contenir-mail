<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Writable;

use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Storage\Exception\ExceptionInterface;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\Message;

/**
 * A storage that can create folders and store messages.
 *
 * @api
 */
interface WritableInterface
{
    /**
     * Create a folder, and its parents where needed.
     *
     * @param string $name The global name, or the local name when a parent is given.
     * @throws ExceptionInterface When the folder exists or the name is not allowed.
     */
    public function createFolder(string $name, Folder|string|null $parentFolder = null): void;

    /**
     * @throws ExceptionInterface When the folder does not exist, has subfolders or cannot be removed.
     */
    public function removeFolder(Folder|string $name): void;

    /**
     * Rename or move a folder; the new name has the same rules as in createFolder().
     *
     * @throws ExceptionInterface When the folder does not exist or cannot be renamed.
     */
    public function renameFolder(Folder|string $oldName, string $newName): void;

    /**
     * Store a message.
     *
     * @param string|resource|Message|ComposedMessage $message The raw message, a stream holding it, or a message.
     * @param Folder|string|null $folder The current folder when null.
     * @param iterable<Flag|string>|null $flags Seen when null.
     * @return int|null The UID of the stored message when the storage reports one, as IMAP servers
     *     with UIDPLUS do (RFC 4315), and null otherwise.
     * @throws ExceptionInterface When the message cannot be stored.
     */
    public function appendMessage(mixed $message, Folder|string|null $folder = null, ?iterable $flags = null): ?int;

    /**
     * @return int|null The UID of the copy when the storage reports one, as IMAP servers with
     *     UIDPLUS do (RFC 4315), and null otherwise.
     * @throws ExceptionInterface When there is no such message or folder.
     */
    public function copyMessage(int $number, Folder|string $folder): ?int;

    /**
     * @return int|null The UID the message has in the destination folder when the storage reports
     *     one, as IMAP servers with UIDPLUS do (RFC 4315), and null otherwise.
     * @throws ExceptionInterface When there is no such message or folder.
     */
    public function moveMessage(int $number, Folder|string $folder): ?int;

    /**
     * Replace a message's flags. Recent cannot be set.
     *
     * @param iterable<Flag|string> $flags
     * @throws ExceptionInterface When there is no such message or a flag cannot be set.
     */
    public function setFlags(int $number, iterable $flags): void;
}
