<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Mime\Exception\RuntimeException as MimeException;
use Contenir\Mail\Protocol;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\SystemClock;
use Generator;
use Override;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

use function array_map;
use function array_values;
use function intval;
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
        $this->protocol->connect($config->connection);
        $this->open = true;
        $this->signIn($config);

        $this->selectFolder($config->folder);
    }

    /**
     * Sign in with the access token when there is one, and the password otherwise.
     *
     * @throws Exception\RuntimeException When the password is refused.
     * @throws Protocol\Exception\ExceptionInterface When the token is refused or the server cannot be asked.
     */
    private function signIn(ImapConfig $config): void
    {
        if (null !== $config->auth) {
            $this->protocol->authenticate($config->auth);

            return;
        }

        if (! $this->protocol->login($config->user, $config->password)) {
            throw new Exception\RuntimeException('Cannot log in: the user or password is wrong');
        }
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

        try {
            return $this->protocol->searchCount(ImapFlags::toSearch($flags, $this->escape(...)));
        } catch (Protocol\Exception\RuntimeException $e) {
            throw new Exception\RuntimeException('The server refused the search', previous: $e);
        }
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
     */
    #[Override]
    public function getMessage(int $id): Message
    {
        $data = $this->protocol->fetch(['FLAGS', 'RFC822.HEADER'], self::checkNumber($id));

        return $this->buildMessage($id, is_array($data) ? $data : []);
    }

    /**
     * Several messages at once: their flags and headers come in one FETCH, and each
     * body is fetched only when it is read. This is the way to show a page of a
     * large folder, with the numbers from sortMessages() or a range.
     *
     * @return array<int, Message> The messages by number, in the order asked for; a number the
     *     server sends no headers for is left out.
     * @throws Exception\OutOfBoundsException When a number is below 1.
     * @throws Exception\RuntimeException When the headers of a message cannot be read.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    public function getMessages(int ...$numbers): array
    {
        if ([] === $numbers) {
            return [];
        }

        $data = $this->protocol->fetch(
            ['FLAGS', 'RFC822.HEADER'],
            array_values(array_map(self::checkNumber(...), $numbers)),
        );
        $messages = [];
        foreach ($numbers as $number) {
            $item = is_array($data) ? $data[$number] ?? null : null;
            if (! is_array($item) || ! is_string($item['RFC822.HEADER'] ?? null)) {
                continue;
            }

            $messages[$number] = $this->buildMessage($number, $item);
        }

        return $messages;
    }

    /**
     * The numbers of the messages in the current folder, sorted by the server (RFC 5256),
     * such as sortMessages('REVERSE DATE') for the newest first.
     *
     * @param string ...$keys Sort keys: ARRIVAL, CC, DATE, FROM, SIZE, SUBJECT, TO, DISPLAYFROM
     *     or DISPLAYTO, each optionally after REVERSE.
     * @return list<int>
     * @throws Exception\RuntimeException When no folder is selected or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When a key is not valid, the server does not
     *     offer SORT, or it cannot be asked.
     */
    public function sortMessages(string ...$keys): array
    {
        if ('' === $this->currentFolder) {
            throw new Exception\RuntimeException('No folder is selected');
        }

        $numbers = $this->protocol->sort(array_values($keys));
        if (false === $numbers) {
            throw new Exception\RuntimeException('The server refused the sort');
        }

        return array_map(intval(...), $numbers);
    }

    /**
     * Wait for changes to the current folder with IDLE (RFC 2177), for at most $timeout seconds,
     * yielding each as an Idle\EventInterface: Exists when mail arrives, Expunge, Recent and FlagsChanged.
     *
     * ```php
     * foreach ($mail->idle(timeout: 600) as $event) {
     *     if ($event instanceof Idle\Exists && $event->count > $last) {
     *         $new  = $mail->getMessages(...range($last + 1, $event->count));
     *         $last = $event->count;
     *     }
     * }
     * ```
     *
     * Nothing is sent until the first iteration. The loop ends once the timeout passes;
     * RFC 2177 asks clients to end IDLE at least every 29 minutes, the default, so to keep
     * listening, call idle() again. Breaking out of the loop ends IDLE, and so does the next
     * command, so the mailbox can be used again right away. Other untagged responses are skipped.
     *
     * @param int $timeout Seconds to listen for, counted from the first iteration.
     * @param ClockInterface $clock The time the timeout is counted by.
     * @return Generator<int, Idle\EventInterface, mixed, void>
     * @throws Exception\RuntimeException When no folder is selected.
     * @throws Protocol\Exception\ExceptionInterface When the timeout is under one second, or the server
     *     offers neither IDLE nor IMAP4rev2; while iterating, when the server refuses IDLE, says BYE
     *     or cannot be reached.
     */
    public function idle(int $timeout = 1740, ClockInterface $clock = new SystemClock()): Generator
    {
        if ('' === $this->currentFolder) {
            throw new Exception\RuntimeException('No folder is selected');
        }

        return $this->events($this->protocol->idle($timeout, $clock));
    }

    /**
     * Not static, so the mailbox lives, and stays open, while the events are iterated.
     *
     * @param Generator<int, array<mixed>, mixed, void> $responses
     * @return Generator<int, Idle\EventInterface, mixed, void>
     * @throws Protocol\Exception\ExceptionInterface
     */
    private function events(Generator $responses): Generator
    {
        foreach ($responses as $tokens) {
            $event = Idle\EventParser::fromResponse($tokens);
            if (null !== $event) {
                yield $event;
            }
        }
    }

    /**
     * @param array<mixed> $data The FLAGS and RFC822.HEADER items fetched for the message.
     * @throws Exception\RuntimeException When the headers cannot be read.
     *
     * @mago-expect analysis:unhandled-thrown-type The body loader throws to whoever first reads the body.
     */
    private function buildMessage(int $id, array $data): Message
    {
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
     * MOVE when the server offers it (RFC 6851, part of IMAP4rev2), and copy then remove otherwise.
     *
     * @throws Exception\ExceptionInterface When the number or folder is not valid, or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function moveMessage(int $id, Folder|string $folder): void
    {
        if (! $this->protocol->hasCapability('MOVE')) {
            $this->copyMessage($id, $folder);
            $this->removeMessage($id);

            return;
        }

        if (! $this->protocol->move(RemoteFolder::check((string) $folder), self::checkNumber($id))) {
            throw new Exception\RuntimeException('Cannot move the message; does the folder exist?');
        }
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
     * Adds flags to a message, leaving its other flags as they are.
     *
     * Not part of WritableInterface: Maildir has no equivalent operation.
     *
     * @param iterable<Flag|string> $flags
     * @throws Exception\ExceptionInterface When the number or a flag is not valid, or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    public function addFlags(int $id, iterable $flags): void
    {
        if (false === $this->protocol->store(ImapFlags::toStore($flags), self::checkNumber($id), null, '+')) {
            throw new Exception\RuntimeException('Cannot add the flags');
        }
    }

    /**
     * Removes flags from a message, leaving its other flags as they are.
     *
     * Not part of WritableInterface: Maildir has no equivalent operation.
     *
     * @param iterable<Flag|string> $flags
     * @throws Exception\ExceptionInterface When the number or a flag is not valid, or the server refuses.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    public function removeFlags(int $id, iterable $flags): void
    {
        if (false === $this->protocol->store(ImapFlags::toStore($flags), self::checkNumber($id), null, '-')) {
            throw new Exception\RuntimeException('Cannot remove the flags');
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
