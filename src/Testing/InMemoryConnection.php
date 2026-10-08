<?php

declare(strict_types=1);

namespace Contenir\Mail\Testing;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\ConnectionInterface;
use Contenir\Mail\Protocol\Exception;
use Contenir\Mail\Protocol\Security;
use LogicException;
use Override;
use SensitiveParameter;

use function array_shift;
use function min;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function var_export;

/**
 * A scripted mail server, for testing code that sends or reads mail.
 *
 * It is a Protocol\ConnectionInterface, so it stands in for the network
 * connection of Protocol\Imap, Protocol\Pop3 and Protocol\Smtp.
 * The script is a list of steps run in order: what the server replies, what
 * the client must send, and where the server stalls, hangs up or negotiates TLS.
 * A stall times out the client's next read, or ends its next wait for data.
 *
 * ```php
 * $server = (new InMemoryConnection())
 *     ->reply("* OK IMAP ready\r\n")
 *     ->expect("TAG1 LOGIN \"user\" \"secret\"\r\n")
 *     ->reply("TAG1 OK logged in\r\n")
 *     ->hangUp();
 * $imap = new Imap(connection: $server);
 * ```
 *
 * A client that writes something other than the next expected bytes, or
 * reads while the script waits for it to write, gets a LogicException: the
 * test is wrong, not the server.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity One builder method per kind of script step, and the Connection methods that play them.
 * @mago-expect lint:kan-defect One builder method per kind of script step, and the Connection methods that play them.
 * @mago-expect lint:too-many-methods One builder method per kind of script step, and the Connection methods that play them.
 */
final class InMemoryConnection implements ConnectionInterface
{
    private const string REPLY = 'reply';

    private const string EXPECT = 'expect';

    private const string STALL = 'stall';

    private const string HANG_UP = 'hang up';

    private const string START_TLS = 'start TLS';

    private const string FAIL_TLS = 'fail TLS';

    private const string REFUSE = 'refuse';

    /** @var list<array{string, string}> */
    private array $steps = [];

    /** Server bytes the client has not read yet */
    private string $buffer = '';

    /** Bytes of the current expect step the client has sent so far */
    private string $partial = '';

    private string $written = '';

    private bool $connected = false;

    private bool $tls = false;

    private ?ConnectionConfig $config = null;

    private ?int $port = null;

    private ?int $timeout = null;

    /** @var list<int> */
    private array $waits = [];

    /**
     * The server sends these bytes.
     */
    public function reply(string $bytes): self
    {
        $this->steps[] = [self::REPLY, $bytes];

        return $this;
    }

    /**
     * The client must send exactly these bytes next.
     */
    public function expect(string $bytes): self
    {
        $this->steps[] = [self::EXPECT, $bytes];

        return $this;
    }

    /**
     * The server sends nothing more: the client's next read times out, or its next wait for data ends empty.
     */
    public function stall(): self
    {
        $this->steps[] = [self::STALL, ''];

        return $this;
    }

    /**
     * The server closes the connection: reads and writes fail from here on.
     */
    public function hangUp(): self
    {
        $this->steps[] = [self::HANG_UP, ''];

        return $this;
    }

    /**
     * The client must enable TLS here, and the server accepts it.
     */
    public function startTls(): self
    {
        $this->steps[] = [self::START_TLS, ''];

        return $this;
    }

    /**
     * The client must enable TLS here, and the handshake fails.
     */
    public function failTls(string $reason = 'handshake failed'): self
    {
        $this->steps[] = [self::FAIL_TLS, $reason];

        return $this;
    }

    /**
     * The server refuses the connection: open() fails. Only valid as the first step.
     */
    public function refuse(string $reason = 'connection refused'): self
    {
        $this->steps[] = [self::REFUSE, $reason];

        return $this;
    }

    /**
     * Everything the client has sent.
     */
    public function written(): string
    {
        return $this->written;
    }

    /**
     * Whether every step of the script has run, apart from a final hang-up,
     * and the client has read every byte the server sent.
     */
    public function isScriptComplete(): bool
    {
        return '' === $this->buffer && ([] === $this->steps || [[self::HANG_UP, '']] === $this->steps);
    }

    /**
     * The configuration the client opened the connection with, if it did.
     */
    public function openedWith(): ?ConnectionConfig
    {
        return $this->config;
    }

    /**
     * The port the client opened the connection on, if it did.
     */
    public function openedPort(): ?int
    {
        return $this->port;
    }

    /**
     * The last timeout the client set, if it set one.
     */
    public function timeout(): ?int
    {
        return $this->timeout;
    }

    /**
     * The seconds the client asked to wait each time it waited for the server, in order.
     *
     * @return list<int>
     */
    public function waits(): array
    {
        return $this->waits;
    }

    public function isTlsEnabled(): bool
    {
        return $this->tls;
    }

    #[Override]
    public function open(ConnectionConfig $config, int $port): void
    {
        $this->config = $config;
        $this->port   = $port;
        $this->tls    = Security::Tls === $config->security;

        $step = $this->steps[0] ?? null;
        if (null !== $step && self::REFUSE === $step[0]) {
            array_shift($this->steps);
            throw new Exception\RuntimeException("Cannot connect to {$config->host}:{$port}: {$step[1]}");
        }

        $this->connected = true;
    }

    #[Override]
    public function isConnected(): bool
    {
        return $this->connected;
    }

    #[Override]
    public function write(#[SensitiveParameter] string $data): void
    {
        $this->assertOpen();

        $step = $this->steps[0] ?? null;
        if (null !== $step && self::HANG_UP === $step[0]) {
            throw new Exception\RuntimeException('Cannot write to the server: the connection is closed');
        }

        $this->written .= $data;
        while ('' !== $data) {
            $step = $this->steps[0] ?? null;
            if (null === $step || self::EXPECT !== $step[0]) {
                throw $this->scriptBroken(sprintf(
                    'The client sent %s, but the script expects %s',
                    var_export($data, return: true),
                    self::describe($step),
                ));
            }

            $wanted = substr($step[1], strlen($this->partial));
            $taken  = substr($data, offset: 0, length: strlen($wanted));
            if (! str_starts_with($wanted, $taken)) {
                throw $this->scriptBroken(sprintf(
                    'The client sent %s, but the script expects %s',
                    var_export($this->partial . $data, return: true),
                    self::describe($step),
                ));
            }

            $this->partial .= $taken;
            $data          = substr($data, strlen($taken));
            if ($this->partial === $step[1]) {
                array_shift($this->steps);
                $this->partial = '';
            }
        }
    }

    #[Override]
    public function readLine(int $maxLength): string
    {
        $this->assertOpen();
        $this->fill();

        $end = strpos($this->buffer, needle: "\n");

        return $this->take(min($maxLength, false === $end ? strlen($this->buffer) : $end + 1));
    }

    /**
     * True when the server has replied or hung up. A stall is the quiet period: it is
     * passed and reported as false, whatever the number of seconds.
     *
     * @throws Exception\RuntimeException When the connection is not open.
     * @throws LogicException When the script expects the client to write or to enable TLS.
     */
    #[Override]
    public function waitForData(int $seconds): bool
    {
        $this->assertOpen();
        $this->waits[] = $seconds;
        if ('' !== $this->buffer) {
            return true;
        }

        $step = $this->steps[0] ?? [self::HANG_UP, ''];
        if (self::STALL === $step[0]) {
            array_shift($this->steps);

            return false;
        }

        if (self::REPLY !== $step[0] && self::HANG_UP !== $step[0]) {
            throw $this->scriptBroken(
                'The client waits for the server, but the script expects ' . self::describe($step),
            );
        }

        return true;
    }

    #[Override]
    public function read(int $length): string
    {
        $this->assertOpen();
        if ($length < 1) {
            return '';
        }

        $this->fill();
        if (strlen($this->buffer) < $length) {
            $this->buffer = '';
            $this->fill();
        }

        return $this->take($length);
    }

    #[Override]
    public function enableTls(): void
    {
        $this->assertOpen();

        if ('' !== $this->buffer) {
            throw new Exception\RuntimeException(
                'The server sent data before TLS was negotiated; refusing to continue',
            );
        }

        $step = array_shift($this->steps);
        if (null === $step || (self::START_TLS !== $step[0] && self::FAIL_TLS !== $step[0])) {
            throw $this->scriptBroken('The client enabled TLS, but the script expects ' . self::describe($step));
        }

        if (self::FAIL_TLS === $step[0]) {
            throw new Exception\RuntimeException("Cannot enable TLS: {$step[1]}");
        }

        $this->tls = true;
    }

    #[Override]
    public function setTimeout(int $seconds): void
    {
        $this->timeout = $seconds;
    }

    #[Override]
    public function close(): void
    {
        $this->connected = false;
    }

    /**
     * Move the server's next replies into the buffer, when it is empty.
     *
     * @throws Exception\TimeoutException When the script stalls here.
     * @throws Exception\RuntimeException When the script hangs up here.
     */
    private function fill(): void
    {
        while ('' === $this->buffer) {
            $step = $this->steps[0] ?? [self::HANG_UP, ''];
            if (self::STALL === $step[0]) {
                array_shift($this->steps);
                throw new Exception\TimeoutException('The server has timed out');
            }

            if (self::HANG_UP === $step[0]) {
                throw new Exception\RuntimeException('Cannot read from the server: the connection is closed');
            }

            if (self::REPLY !== $step[0]) {
                throw $this->scriptBroken('The client reads, but the script expects ' . self::describe($step));
            }

            array_shift($this->steps);
            $this->buffer = $step[1];
            $next         = $this->steps[0] ?? null;
            while (null !== $next && self::REPLY === $next[0]) {
                array_shift($this->steps);
                $this->buffer .= $next[1];
                $next         = $this->steps[0] ?? null;
            }
        }
    }

    /**
     * @param array{string, string}|null $step
     */
    private static function describe(?array $step): string
    {
        if (null === $step) {
            return 'nothing more';
        }

        return match ($step[0]) {
            self::EXPECT => 'the client to send ' . var_export($step[1], return: true),
            self::START_TLS, self::FAIL_TLS => 'the client to enable TLS',
            default => "the server to {$step[0]}",
        };
    }

    private function take(int $length): string
    {
        $bytes        = substr($this->buffer, offset: 0, length: $length);
        $this->buffer = substr($this->buffer, $length);

        return $bytes;
    }

    /**
     * Close the connection as the script breaks, so later calls, such as a
     * destructor logging out, get an ordinary closed-connection error and only
     * the first mismatch is reported.
     */
    private function scriptBroken(string $message): LogicException
    {
        $this->connected = false;

        return new LogicException($message);
    }

    /**
     * @throws Exception\RuntimeException
     */
    private function assertOpen(): void
    {
        if (! $this->connected) {
            throw new Exception\RuntimeException('No connection has been established');
        }
    }
}
