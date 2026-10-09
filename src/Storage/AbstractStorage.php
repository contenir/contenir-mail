<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Countable;
use Generator;
use IteratorAggregate;
use Override;

use function sprintf;

/**
 * A mailbox: count, list, read and remove its messages, numbered from 1.
 *
 * Message numbers can change when messages are removed; unique IDs do not,
 * so use getUniqueId() and getNumberByUniqueId() to refer to a message
 * across requests.
 *
 * Storages hold open files or connections, so they cannot be serialized.
 *
 * @mago-expect lint:too-many-methods The laminas-mail storage operations every mailbox shares.
 * @implements IteratorAggregate<int, Message>
 *
 * @api
 */
abstract class AbstractStorage implements Countable, IteratorAggregate
{
    /** Whether close() still has something to release; false for an object never constructed */
    protected bool $open = false;

    /**
     * Features the storage supports, keyed by Capability value: true, false, or null when not yet known.
     *
     * @var array<string, bool|null>
     */
    protected array $has = [
        Capability::UniqueId->value  => true,
        Capability::Delete->value    => false,
        Capability::Create->value    => false,
        Capability::Top->value       => false,
        Capability::FetchPart->value => true,
        Capability::Flags->value     => false,
    ];

    /**
     * The number of messages, or of those with every flag given.
     *
     * @throws Exception\ExceptionInterface When the count cannot be had, or flags are given to a storage without flags.
     */
    abstract public function countMessages(Flag|string ...$flags): int;

    /**
     * The size of a message in bytes.
     *
     * @throws Exception\ExceptionInterface When there is no such message.
     */
    abstract public function getSize(int $number): int;

    /**
     * The size of every message in bytes, by message number.
     *
     * @return array<int, int>
     * @throws Exception\ExceptionInterface
     */
    abstract public function getSizes(): array;

    /**
     * @throws Exception\ExceptionInterface When there is no such message, or it cannot be read.
     */
    abstract public function getMessage(int $number): Message;

    /**
     * The header block of a message, as stored.
     *
     * @throws Exception\ExceptionInterface When there is no such message.
     */
    abstract public function getRawHeader(int $number): string;

    /**
     * The body of a message, as stored.
     *
     * @throws Exception\ExceptionInterface When there is no such message.
     */
    abstract public function getRawContent(int $number): string;

    /**
     * Release the file or connection; the destructor calls it. Messages read from
     * a server cannot fetch their bodies afterwards, while those read from a
     * local file keep it open until they are gone.
     */
    abstract public function close(): void;

    /**
     * Keep the connection alive.
     *
     * @throws Exception\ExceptionInterface
     */
    abstract public function noop(): void;

    /**
     * @throws Exception\ExceptionInterface When there is no such message, or the storage is read-only.
     */
    abstract public function removeMessage(int $number): void;

    /**
     * A message's unique ID; its number when the storage has none.
     *
     * @throws Exception\ExceptionInterface When there is no such message.
     */
    abstract public function getUniqueId(int $number): string;

    /**
     * Every message's unique ID, by message number.
     *
     * @return array<int, string>
     * @throws Exception\ExceptionInterface
     */
    abstract public function getUniqueIds(): array;

    /**
     * @throws Exception\ExceptionInterface When no message has that unique ID.
     */
    abstract public function getNumberByUniqueId(string $uniqueId): int;

    /**
     * Every feature, keyed by its Capability value; supports() asks about one by its case.
     *
     * @return array<string, bool|null> Feature name to true, false, or null when not yet known.
     */
    public function getCapabilities(): array
    {
        return $this->has;
    }

    /**
     * Whether the storage supports a feature: null when it is not yet known, as whether a
     * POP3 server has TOP is until a message has been read, or when the storage does not say.
     *
     * @throws Exception\ExceptionInterface When the storage must ask the server and cannot.
     */
    public function supports(Capability $capability): ?bool
    {
        return $this->getCapabilities()[$capability->value] ?? null;
    }

    /**
     * @throws Exception\ExceptionInterface When the count cannot be had.
     */
    #[Override]
    public function count(): int
    {
        return $this->countMessages();
    }

    /**
     * Every message, by number. The count is taken when iteration starts.
     *
     * @return Generator<int, Message>
     * @throws Exception\ExceptionInterface
     */
    #[Override]
    public function getIterator(): Generator
    {
        $count = $this->countMessages();
        for ($number = 1; $number <= $count; ++$number) {
            yield $number => $this->getMessage($number);
        }
    }

    public function __destruct()
    {
        if ($this->open) {
            $this->close();
        }
    }

    /**
     * @return never
     * @throws Exception\RuntimeException Always: storages hold open files or connections.
     */
    public function __serialize(): array
    {
        throw new Exception\RuntimeException(sprintf('%s cannot be serialized', static::class));
    }

    /**
     * Refused, so unserialize() can never build a storage that its destructor would act on.
     *
     * @param array<mixed> $data
     * @throws Exception\RuntimeException Always.
     *
     * @mago-expect analysis:unused-parameter The signature is PHP's; the data is refused unread.
     */
    public function __unserialize(array $data): void
    {
        throw new Exception\RuntimeException(sprintf('%s cannot be unserialized', static::class));
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1 or, given a count, above it.
     */
    protected static function checkNumber(int $number, ?int $count = null): int
    {
        if ($number < 1 || (null !== $count && $number > $count)) {
            throw new Exception\OutOfBoundsException("There is no message {$number}");
        }

        return $number;
    }
}
