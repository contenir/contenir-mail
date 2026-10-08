<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Address;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime;
use Contenir\Mail\Protocol;
use Contenir\Mail\SystemClock;
use LogicException;
use Override;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

use function array_unique;
use function array_values;
use function count;
use function preg_grep;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * Sends mail through an SMTP server with Protocol\Smtp, connecting on the first send
 * and reusing the session for the messages after it.
 *
 * ```php
 * $transport = new Smtp(['host' => 'smtp.example.com', 'auth' => ['type' => 'login', 'username' => …, 'password' => …]]);
 * $transport->send($message);
 * ```
 *
 * @mago-expect lint:too-many-methods The laminas-mail transport API: envelope, connection and auto-disconnect accessors.
 * @mago-expect lint:cyclomatic-complexity The laminas-mail transport API: envelope, connection and auto-disconnect accessors.
 */
final class Smtp implements TransportInterface
{
    private SmtpConfig $config;

    private ?Envelope $envelope = null;

    private ?Protocol\Smtp $connection = null;

    private bool $autoDisconnect = true;

    /** When the connection was opened, as a Unix time */
    private ?int $connectedTime = null;

    /**
     * @param SmtpConfig|iterable<mixed, mixed>|null $config A config, or the settings SmtpConfig::fromIterable() reads.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When the settings are invalid.
     */
    public function __construct(
        #[SensitiveParameter]
        SmtpConfig|iterable|null $config = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->config = $config instanceof SmtpConfig ? $config : SmtpConfig::fromIterable($config ?? []);
    }

    /**
     * A transport holds a live connection and its credentials, so it cannot be serialized.
     *
     * @return never
     * @throws LogicException
     */
    public function __serialize(): array
    {
        throw new LogicException(self::class . ' cannot be serialized');
    }

    /**
     * Refuse to unserialize, so that a crafted payload never reaches the destructor.
     *
     * @return never
     * @throws LogicException
     */
    public function __wakeup(): void
    {
        throw new LogicException(self::class . ' cannot be unserialized');
    }

    /**
     * Close the connection, ignoring a server that has already gone.
     */
    public function __destruct()
    {
        $connection = $this->getConnection();
        if (null === $connection) {
            return;
        }

        $this->quitQuietly($connection);

        if ($this->autoDisconnect) {
            $connection->disconnect();
        }
    }

    public function getConfig(): SmtpConfig
    {
        return $this->config;
    }

    /**
     * Use these envelope addresses instead of those taken from each message, or null to stop.
     */
    public function setEnvelope(?Envelope $envelope): void
    {
        $this->envelope = $envelope;
    }

    public function getEnvelope(): ?Envelope
    {
        return $this->envelope;
    }

    /**
     * Whether the destructor closes the connection; turn it off to share one connection.
     */
    public function setAutoDisconnect(bool $flag): void
    {
        $this->autoDisconnect = $flag;
    }

    public function getAutoDisconnect(): bool
    {
        return $this->autoDisconnect;
    }

    /**
     * Use this protocol connection instead of creating one from the config.
     */
    public function setConnection(Protocol\Smtp $connection): void
    {
        $this->connection = $connection;
        $connection->setUseCompleteQuit($this->config->useCompleteQuit && null === $this->config->connectionTimeLimit);
    }

    /**
     * The connection, or null when there is none or it has outlived the connection time limit.
     */
    public function getConnection(): ?Protocol\Smtp
    {
        $timeLimit = $this->config->connectionTimeLimit;
        if (
            null !== $timeLimit
            && null !== $this->connectedTime
            && ($this->clock->now()->getTimestamp() - $this->connectedTime) > $timeLimit
        ) {
            $this->connection = null;
        }

        return $this->connection;
    }

    /**
     * Close the connection, if any.
     */
    public function disconnect(): void
    {
        $connection = $this->getConnection();
        if (null !== $connection) {
            $connection->disconnect();
            $this->connectedTime = null;
        }
    }

    /**
     * Send the message, connecting first if there is no open session.
     *
     * @throws Exception\RuntimeException When the message has no sender or recipient, or a header is unsafe.
     * @throws Protocol\Exception\ExceptionInterface When the server refuses the message.
     * @throws Mime\Exception\RuntimeException When the message body cannot be written.
     */
    #[Override]
    public function send(Message $message): void
    {
        $connection = $this->openSession();

        $from       = $this->prepareFromAddress($message);
        $recipients = $this->prepareRecipients($message);
        $data       = HeaderGuard::check($message->getHeaders()->without('Bcc'))->toString()
        . Headers::EOL
        . $message->getBodyText();

        if (0 === count($recipients)) {
            throw new Exception\RuntimeException(sprintf(
                '%s transport expects at least one recipient if the message has at least one header or body',
                self::class,
            ));
        }

        $connection->envelope(
            $from,
            $recipients,
            strlen($data),
            smtpUtf8: [] !== preg_grep('/[\x80-\xFF]/', $recipients),
            eightBit: 1 === preg_match('/[\x80-\xFF]/', $data),
        );

        $connection->data($data);
    }

    /**
     * @throws Exception\RuntimeException When there is neither an envelope sender, a Sender nor a From address.
     */
    private function prepareFromAddress(Message $message): string
    {
        $envelopeFrom = $this->envelope?->from;
        if (null !== $envelopeFrom) {
            return $envelopeFrom;
        }

        $sender = $message->getSender() ?? $message->getFrom()->first();
        if (! $sender instanceof Address) {
            throw new Exception\RuntimeException(sprintf(
                '%s transport expects either a Sender or at least one From address in the Message; none provided',
                self::class,
            ));
        }

        return $sender->getEmail();
    }

    /**
     * @return list<string>
     */
    private function prepareRecipients(Message $message): array
    {
        $envelopeTo = $this->envelope->to ?? [];
        if ([] !== $envelopeTo) {
            return $envelopeTo;
        }

        $recipients = [];
        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $list) {
            foreach ($list as $address) {
                $recipients[] = $address->getEmail();
            }
        }

        return array_values(array_unique($recipients));
    }

    /**
     * The session to send with: the open one, reset for a new transaction, or a new one.
     *
     * @throws Protocol\Exception\ExceptionInterface When the connection or the session fails.
     */
    private function openSession(): Protocol\Smtp
    {
        $connection = $this->getConnection();
        if (null === $connection || ! $connection->hasSession()) {
            return $this->connect();
        }

        $connection->rset();

        return $connection;
    }

    /**
     * Send QUIT, ignoring a server that has already gone.
     *
     * @mago-expect lint:no-empty-catch-clause There is nothing left to close politely when the server has gone.
     */
    private function quitQuietly(Protocol\Smtp $connection): void
    {
        try {
            $connection->quit();
        } catch (Protocol\Exception\ExceptionInterface) {
        }
    }

    /**
     * Open a connection, creating it from the config unless one was set, and start the session.
     *
     * @throws Protocol\Exception\ExceptionInterface When the connection or the session fails.
     */
    private function connect(): Protocol\Smtp
    {
        $connection = $this->connection;
        if (null === $connection) {
            $connection = new Protocol\Smtp(
                $this->config->connection,
                config: ['allow_insecure_auth' => $this->config->allowInsecureAuth],
                authenticator: $this->config->auth,
            );
            $this->setConnection($connection);
        }

        $connection->connect();
        $this->connectedTime = $this->clock->now()->getTimestamp();
        $connection->helo($this->config->name);

        return $connection;
    }
}
