<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Protocol;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Override;
use SensitiveParameter;

use function array_search;
use function is_array;
use function is_int;
use function is_iterable;
use function is_string;

/**
 * A POP3 mailbox: one folder of messages on the server.
 *
 * Headers are fetched with TOP, and the body with RETR the first time
 * content or parts are asked for; a server without TOP sends the whole
 * message at once. Unique IDs come from UIDL when the server has it, and
 * are the message numbers otherwise. POP3 keeps no flags.
 *
 * @mago-expect lint:too-many-methods The AbstractStorage operations.
 * @mago-expect lint:cyclomatic-complexity Each AbstractStorage operation checks the server's untyped answer.
 *
 * @api
 */
final class Pop3 extends AbstractStorage
{
    private Protocol\Pop3 $protocol;

    /** Whether the server has UIDL; null until asked */
    private ?bool $uniqueIds = null;

    /**
     * @param Pop3Config|Protocol\Pop3|iterable<mixed, mixed> $config Settings to connect and log in with, or a
     *     protocol already connected and logged in.
     * @param Protocol\Pop3|null $protocol A protocol to connect with the settings, such as a subclass; a new one when null.
     * @throws Protocol\Exception\ExceptionInterface When the connection or login fails.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(
        #[SensitiveParameter]
        Pop3Config|Protocol\Pop3|iterable $config,
        ?Protocol\Pop3 $protocol = null,
    ) {
        $this->has[Capability::FetchPart->value] = false;
        $this->has[Capability::Top->value]       = null;
        $this->has[Capability::UniqueId->value]  = null;
        $this->has[Capability::Delete->value]    = true;
        if ($config instanceof Protocol\Pop3) {
            $this->protocol = $config;
            $this->open     = true;

            return;
        }

        $config         = is_iterable($config) ? Pop3Config::fromIterable($config) : $config;
        $this->protocol = $protocol ?? new Protocol\Pop3();
        $this->protocol->connect($config->connection);
        $this->open = true;
        if (null !== $config->auth) {
            $this->protocol->authenticate($config->auth);

            return;
        }

        $this->protocol->login($config->user, $config->password);
    }

    /**
     * @throws Exception\RuntimeException When flags are given: POP3 keeps none.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function countMessages(Flag|string ...$flags): int
    {
        if ([] !== $flags) {
            throw new Exception\RuntimeException('POP3 keeps no flags to count by');
        }

        $count  = 0;
        $octets = $count;
        $this->protocol->status($count, $octets);

        return (int) $count;
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getSize(int $number): int
    {
        $size = $this->protocol->getList(self::checkNumber($number));

        return is_int($size) ? $size : 0;
    }

    /**
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getSizes(): array
    {
        $sizes = $this->protocol->getList();

        return is_array($sizes) ? $sizes : [];
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
    public function getMessage(int $number): Message
    {
        $number = self::checkNumber($number);
        [$headers, $body] = MimeParser::split(Content::fromString($this->protocol->top($number, 0, true)));
        if (0 === $body->length()) {
            $body = Content::lazy(fn(): string => $this->retrieveBody($number));
        }

        return new Message(new Part($headers, $body));
    }

    /**
     * @throws Exception\ExceptionInterface When the number is below 1 or the headers cannot be read.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getRawHeader(int $number): string
    {
        $raw = Content::fromString($this->protocol->top(self::checkNumber($number), 0, true));
        [, $body] = MimeParser::split($raw);

        return $raw->slice(0, $raw->length() - $body->length())->read();
    }

    /**
     * @throws Exception\ExceptionInterface When the number is below 1 or the message cannot be read.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getRawContent(int $number): string
    {
        return $this->retrieveBody(self::checkNumber($number));
    }

    /**
     * The capabilities, with "uniqueid" and "top" asked of the server on the first call.
     * POP3 servers do not reliably list TOP in CAPA, so TOP is tried on the first message;
     * it stays unknown while the mailbox is empty.
     *
     * @return array<string, bool|null> Feature name to true, false, or null when not yet known.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getCapabilities(): array
    {
        $this->supportsUniqueIds();
        if (null === $this->protocol->hasTop && $this->countMessages() > 0) {
            $this->probeTop();
        }

        $this->has[Capability::Top->value] = $this->protocol->hasTop;

        return parent::getCapabilities();
    }

    #[Override]
    public function close(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;
        $this->protocol->logout();
    }

    /**
     * @throws Protocol\Exception\ExceptionInterface When the server does not answer.
     */
    #[Override]
    public function noop(): void
    {
        $this->protocol->noop();
    }

    /**
     * Mark a message for deletion when the session ends.
     *
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server refuses.
     */
    #[Override]
    public function removeMessage(int $number): void
    {
        $this->protocol->delete(self::checkNumber($number));
    }

    /**
     * @throws Exception\OutOfBoundsException When the number is below 1.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getUniqueId(int $number): string
    {
        $number = self::checkNumber($number);
        if (! $this->supportsUniqueIds()) {
            return (string) $number;
        }

        $uid = $this->protocol->uniqueid($number);

        return is_string($uid) ? $uid : '';
    }

    /**
     * @throws Exception\ExceptionInterface When the messages cannot be counted.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getUniqueIds(): array
    {
        $ids = [];
        if (! $this->supportsUniqueIds()) {
            for ($number = 1, $count = $this->countMessages(); $number <= $count; ++$number) {
                $ids[$number] = (string) $number;
            }

            return $ids;
        }

        $uids = $this->protocol->uniqueid();

        return is_array($uids) ? $uids : [];
    }

    /**
     * @throws Exception\ExceptionInterface When no message has that unique ID.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    #[Override]
    public function getNumberByUniqueId(string $uniqueId): int
    {
        $number = array_search($uniqueId, $this->getUniqueIds(), strict: true);

        return false === $number ? throw new Exception\OutOfBoundsException('Unique ID not found') : $number;
    }

    /**
     * @throws Exception\RuntimeException When the message cannot be read.
     * @throws Protocol\Exception\ExceptionInterface When the server cannot be asked.
     */
    private function retrieveBody(int $number): string
    {
        return MimeParser::split(Content::fromString($this->protocol->retrieve($number)))[1]->read();
    }

    /**
     * Whether the server has UIDL, asked for once.
     */
    private function supportsUniqueIds(): bool
    {
        if (null === $this->uniqueIds) {
            $this->uniqueIds                        = $this->probeUniqueIds();
            $this->has[Capability::UniqueId->value] = $this->uniqueIds;
        }

        return $this->uniqueIds;
    }

    /**
     * Try TOP on the first message, which records in the protocol whether the server has it.
     *
     * @mago-expect lint:no-empty-catch-clause A refused TOP is the answer: the protocol has recorded it.
     */
    private function probeTop(): void
    {
        try {
            $this->protocol->top(1, 0, fallback: false);
        } catch (Protocol\Exception\ExceptionInterface) {
        }
    }

    private function probeUniqueIds(): bool
    {
        try {
            $this->protocol->uniqueid();

            return true;
        } catch (Protocol\Exception\ExceptionInterface) {
            return false;
        }
    }
}
