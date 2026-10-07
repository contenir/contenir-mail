<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Mime\Exception\RuntimeException as MimeException;
use Contenir\Mail\Protocol;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Override;
use SensitiveParameter;

use function count;
use function is_array;
use function is_iterable;
use function is_scalar;
use function is_string;

use const INF;

/**
 * An IMAP mailbox: messages, flags and folders on the server.
 *
 * Headers and flags are fetched with the message; the body is fetched the
 * first time content or parts are asked for. Message numbers are integers,
 * and flags and folder names are checked before they reach the protocol, so
 * no value can add to an IMAP command.
 *
 * @mago-expect lint:too-many-methods The AbstractStorage, FolderInterface and WritableInterface operations.
 * @mago-expect lint:cyclomatic-complexity Each of the many interface operations checks the server's answer.
 * @mago-expect lint:kan-defect Each of the many interface operations checks the server's answer.
 * @mago-expect analysis:mixed-assignment The protocol returns server data untyped; it is typed here.
 *
 * @api
 */
final class Imap extends AbstractStorage implements Folder\FolderInterface, Writable\WritableInterface
{
    private Protocol\Imap $protocol;

    private string $currentFolder = '';

    private string $delimiter = '';

    /**
     * @param ImapConfig|Protocol\Imap|iterable<mixed, mixed> $config Settings to connect and log in with, or a
     *     protocol already connected and logged in.
     * @param Protocol\Imap|null $protocol A protocol to connect with the settings, such as a subclass; a new one when null.
     * @throws Exception\ExceptionInterface When logging in fails or the folder cannot be selected.
     * @throws Protocol\Exception\ExceptionInterface When the connection fails.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(
        #[SensitiveParameter]
        ImapConfig|Protocol\Imap|iterable $config,
        ?Protocol\Imap $protocol = null,
    ) {
        $this->has['flags']  = true;
        $this->has['create'] = true;
        $this->has['delete'] = true;
        if ($config instanceof Protocol\Imap) {
            $this->protocol = $config;
            $this->open     = true;
            try {
                $this->selectFolder('INBOX');
            } catch (Exception\ExceptionInterface $e) {
                throw new Exception\RuntimeException('Cannot select INBOX; is the protocol logged in?', 0, $e);
            }

            return;
        }

        $config         = is_iterable($config) ? ImapConfig::fromIterable($config) : $config;
        $this->protocol = $protocol ?? new Protocol\Imap();
        $this->protocol->setNoValidateCert(! $config->connection->verifyPeer);
        $this->protocol->connect(
            $config->connection->host,
            $config->connection->port,
            RemoteConnection::legacySsl($config->connection->security),
        );
        $this->open = true;
        if (! $this->protocol->login($config->user, $config->password)) {
            throw new Exception\RuntimeException('Cannot log in: the user or password is wrong');
        }

        $this->selectFolder($config->folder);
    }

    /**
     * @throws Exception\ExceptionInterface When no folder is selected or a flag is not valid.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function countMessages(Flag|string ...$flags): int
    {
        if ('' === $this->currentFolder) {
            throw new Exception\RuntimeException('No folder is selected');
        }

        $ids = $this->protocol->search(ImapFlags::toSearch($flags, $this->escape(...)));
        if (false === $ids) {
            throw new Exception\RuntimeException('The server refused the search');
        }

        return count($ids);
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getSize(int $id): int
    {
        return (int) $this->fetchText('RFC822.SIZE', $id);
    }

    /**
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getSizes(): array
    {
        $sizes = [];
        foreach ($this->fetchAll('RFC822.SIZE') as $id => $size) {
            $sizes[$id] = (int) $size;
        }

        return $sizes;
    }

    /**
     * The body is fetched when first read, and a failed fetch is thrown to that reader.
     *
     * @throws Exception\ExceptionInterface When the number is below 1 or the headers cannot be read.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     *
     * @mago-expect analysis:unhandled-thrown-type The body loader throws to whoever first reads the body.
     */
    #[Override]
    public function getMessage(int $id): Message
    {
        $data  = $this->protocol->fetch(['FLAGS', 'RFC822.HEADER'], self::checkNumber($id));
        $data  = is_array($data) ? $data : [];
        $flags = [];
        foreach (is_array($data['FLAGS'] ?? null) ? $data['FLAGS'] : [] as $flag) {
            $flags[] = Flag::fromImap(is_scalar($flag) ? (string) $flag : '');
        }

        $header = $data['RFC822.HEADER'] ?? '';
        [$headers] = MimeParser::split(Content::fromString(is_string($header) ? $header : ''));
        $body = Content::lazy(fn(): string => $this->fetchText('RFC822.TEXT', $id));

        return new Message(new Part($headers, $body), $flags);
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getRawHeader(int $id): string
    {
        return $this->fetchText('RFC822.HEADER', $id);
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getRawContent(int $id): string
    {
        return $this->fetchText('RFC822.TEXT', $id);
    }

    #[Override]
    public function close(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open          = false;
        $this->currentFolder = '';
        $this->protocol->logout();
    }

    /**
     * @throws Exception\RuntimeException When the server does not answer.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function noop(): void
    {
        if (false === $this->protocol->noop()) {
            throw new Exception\RuntimeException('The server did not answer NOOP');
        }
    }

    /**
     * Flag a message deleted and expunge it.
     *
     * @throws Exception\ExceptionInterface When the number is below 1 or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function removeMessage(int $id): void
    {
        if (false === $this->protocol->store([Flag::Deleted->value], self::checkNumber($id), null, '+')) {
            throw new Exception\RuntimeException('Cannot set the Deleted flag');
        }

        if (false === $this->protocol->expunge()) {
            throw new Exception\RuntimeException('The message is flagged deleted, but could not be expunged');
        }
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getUniqueId(int $id): string
    {
        return $this->fetchText('UID', $id);
    }

    /**
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getUniqueIds(): array
    {
        return $this->fetchAll('UID');
    }

    /**
     * @throws Exception\OutOfBoundsException When no message has that unique ID.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getNumberByUniqueId(string $id): int
    {
        foreach ($this->getUniqueIds() as $number => $uid) {
            if ($uid === $id) {
                return $number;
            }
        }

        throw new Exception\OutOfBoundsException('Unique ID not found');
    }

    /**
     * @throws Exception\ExceptionInterface When the name is not valid or there is no such folder.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getFolders(?string $rootFolder = null): Folder
    {
        $folders = $this->protocol->listMailbox(RemoteFolder::checkOptional((string) $rootFolder));
        if ([] === $folders) {
            throw new Exception\InvalidArgumentException('Folder not found');
        }

        [$root, $delimiter] = ImapFolderTree::build($folders);
        $this->delimiter = $delimiter ?? $this->delimiter;

        return $root;
    }

    /**
     * @throws Exception\ExceptionInterface When the name is not valid or the server cannot select it.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function selectFolder(Folder|string $globalName): void
    {
        $name                = RemoteFolder::check((string) $globalName);
        $this->currentFolder = '';
        if (false === $this->protocol->select($name)) {
            throw new Exception\RuntimeException('Cannot select the folder; it may not exist');
        }

        $this->currentFolder = $name;
    }

    #[Override]
    public function getCurrentFolder(): string
    {
        return $this->currentFolder;
    }

    /**
     * @throws Exception\ExceptionInterface When the name is not valid or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function createFolder(string $name, Folder|string|null $parentFolder = null): void
    {
        $folder = null === $parentFolder ? $name : "{$parentFolder}{$this->delimiter()}{$name}";
        if (! $this->protocol->create(RemoteFolder::check($folder))) {
            throw new Exception\RuntimeException('Cannot create the folder');
        }
    }

    /**
     * @throws Exception\ExceptionInterface When the name is not valid or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function removeFolder(Folder|string $name): void
    {
        if (! $this->protocol->delete(RemoteFolder::check((string) $name))) {
            throw new Exception\RuntimeException('Cannot delete the folder');
        }
    }

    /**
     * @throws Exception\ExceptionInterface When a name is not valid or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function renameFolder(Folder|string $oldName, string $newName): void
    {
        if (! $this->protocol->rename(RemoteFolder::check((string) $oldName), RemoteFolder::check($newName))) {
            throw new Exception\RuntimeException('Cannot rename the folder');
        }
    }

    /**
     * @param string|resource|Message|ComposedMessage $message
     * @param iterable<Flag|string>|null $flags Seen when null.
     * @throws Exception\ExceptionInterface When a flag or the folder is not valid, or the server refuses.
     * @throws MimeException When a composed message cannot be written.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function appendMessage(mixed $message, Folder|string|null $folder = null, ?iterable $flags = null): void
    {
        $folder = RemoteFolder::check((string) ($folder ?? $this->currentFolder));
        $flags  = ImapFlags::toStore($flags ?? [Flag::Seen]);
        if (! $this->protocol->append($folder, RawMessage::toString($message), $flags)) {
            throw new Exception\RuntimeException(
                'Cannot store the message; check that the folder exists and the flags',
            );
        }
    }

    /**
     * @throws Exception\ExceptionInterface When the number or folder is not valid, or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function copyMessage(int $id, Folder|string $folder): void
    {
        if (! $this->protocol->copy(RemoteFolder::check((string) $folder), self::checkNumber($id))) {
            throw new Exception\RuntimeException('Cannot copy the message; does the folder exist?');
        }
    }

    /**
     * Copy, then remove: IMAP4rev1 has no MOVE.
     *
     * @throws Exception\ExceptionInterface When the number or folder is not valid, or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function moveMessage(int $id, Folder|string $folder): void
    {
        $this->copyMessage($id, $folder);
        $this->removeMessage($id);
    }

    /**
     * @param iterable<Flag|string> $flags
     * @throws Exception\ExceptionInterface When the number or a flag is not valid, or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function setFlags(int $id, iterable $flags): void
    {
        if (false === $this->protocol->store(ImapFlags::toStore($flags), self::checkNumber($id))) {
            throw new Exception\RuntimeException('Cannot set the flags');
        }
    }

    /**
     * The server's folder delimiter, asked for once; empty when the server has none.
     *
     * @throws Exception\ExceptionInterface When the folders cannot be listed.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    public function delimiter(): string
    {
        if ('' === $this->delimiter) {
            $this->getFolders();
        }

        return $this->delimiter;
    }

    /**
     * One item of one message, as text.
     *
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    private function fetchText(string $item, int $id): string
    {
        $value = $this->protocol->fetch($item, self::checkNumber($id));

        return is_string($value) ? $value : '';
    }

    /**
     * One item of every message, by message number.
     *
     * @return array<int, string>
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    private function fetchAll(string $item): array
    {
        $values = $this->protocol->fetch($item, 1, INF);
        $result = [];
        foreach (is_array($values) ? $values : [] as $id => $value) {
            $result[(int) $id] = is_scalar($value) ? (string) $value : '';
        }

        return $result;
    }

    /**
     * A keyword as an IMAP string, quoted or as a literal.
     */
    private function escape(string $text): string
    {
        $escaped = $this->protocol->escapeString($text);

        return is_string($escaped) ? $escaped : '';
    }
}
