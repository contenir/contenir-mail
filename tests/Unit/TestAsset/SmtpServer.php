<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\ConnectionInterface;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Override;

use function array_key_exists;
use function array_key_last;
use function array_shift;
use function base64_encode;
use function count;
use function explode;
use function str_ends_with;
use function str_starts_with;
use function strtoupper;
use function substr;

/**
 * An in-memory SMTP server that answers whatever Protocol\Smtp sends.
 *
 * Unlike Testing\InMemoryConnection it needs no script: it answers each command with a
 * plausible reply: a greeting, an EHLO list of the capabilities set with setCapabilities(),
 * 334 and 235 for AUTH, 354 for DATA, 221 for QUIT and 250 otherwise. reply() scripts a
 * different answer for the next command that starts with a prefix.
 *
 * @mago-expect lint:too-many-properties A fake server keeps its script and what it was sent.
 * @mago-expect lint:kan-defect A fake server answers every SMTP command.
 * @mago-expect lint:cyclomatic-complexity A fake server answers every SMTP command.
 */
final class SmtpServer implements ConnectionInterface
{
    public const string CRAM_MD5_CHALLENGE = '<1896.697170952@postoffice.example.net>';

    /** @var list<string> */
    private array $capabilities = [
        'STARTTLS',
        'AUTH PLAIN LOGIN CRAM-MD5 XOAUTH2',
        'SIZE 1000',
        '8BITMIME',
        'SMTPUTF8',
    ];

    /** @var list<string> */
    private array $greeting = ['220 mail.example.com ESMTP'];

    /** @var array<string, list<list<string>>> */
    private array $scripted = [];

    /** @var list<string> Lines waiting to be read */
    private array $pending = [];

    /** @var list<string> Replies to the base64 lines of an AUTH exchange */
    private array $authReplies = [];

    /** @var list<string> Every line the client sent, including secrets */
    private array $sent = [];

    private bool $inData = false;

    private bool $connected = false;

    private bool $tls = false;

    private ?string $tlsFailure = null;

    private ?ConnectionConfig $config = null;

    private ?int $port = null;

    private string $lastRequest = '';

    /** @var array<string, int|null> The read timeout first used after each request */
    private array $timeouts = [];

    /**
     * The EHLO keywords to advertise, such as "STARTTLS" or "SIZE 1000".
     */
    public function setCapabilities(string ...$capabilities): void
    {
        $this->capabilities = $capabilities;
    }

    public function setGreeting(string ...$lines): void
    {
        $this->greeting = $lines;
    }

    /**
     * Answer the next command that starts with $prefix with these lines.
     */
    public function reply(string $prefix, string ...$lines): void
    {
        $this->scripted[$prefix][] = $lines;
    }

    public function failTls(string $message): void
    {
        $this->tlsFailure = $message;
    }

    /**
     * @return list<string>
     */
    public function sentLines(): array
    {
        return $this->sent;
    }

    /**
     * The settings the client connected with, if it did.
     */
    public function openedWith(): ?ConnectionConfig
    {
        return $this->config;
    }

    /**
     * The port the client connected to, if it did.
     */
    public function openedPort(): ?int
    {
        return $this->port;
    }

    /**
     * The read timeout set for the reply to a request; "" for the greeting.
     */
    public function timeoutFor(string $request): ?int
    {
        return $this->timeouts[$request] ?? null;
    }

    public function tlsStarted(): bool
    {
        return $this->tls;
    }

    #[Override]
    public function open(ConnectionConfig $config, int $port): void
    {
        $this->config      = $config;
        $this->port        = $port;
        $this->lastRequest = '';
        $this->connected   = true;
        $this->pending     = $this->greeting;
    }

    #[Override]
    public function isConnected(): bool
    {
        return $this->connected;
    }

    #[Override]
    public function write(string $data): void
    {
        if (! $this->connected) {
            throw new RuntimeException('Cannot write: the connection is closed');
        }

        if (! str_ends_with($data, "\r\n")) {
            throw new RuntimeException('Protocol\Smtp writes whole lines');
        }

        foreach (explode("\r\n", substr($data, offset: 0, length: -2)) as $line) {
            $this->lastRequest = $line;
            $this->sent[]      = $line;
            $this->queueReply($line);
        }
    }

    #[Override]
    public function readLine(int $maxLength): string
    {
        $line = array_shift($this->pending);
        if (null === $line) {
            throw new RuntimeException('The scripted server has nothing more to say');
        }

        if (! array_key_exists($this->lastRequest, $this->timeouts)) {
            $this->timeouts[$this->lastRequest] = null;
        }

        return "{$line}\r\n";
    }

    #[Override]
    public function waitUntilReadable(int $seconds): bool
    {
        throw new RuntimeException('SMTP never waits for news');
    }

    #[Override]
    public function read(int $length): string
    {
        throw new RuntimeException('SMTP reads only lines');
    }

    #[Override]
    public function enableTls(): void
    {
        if (null !== $this->tlsFailure) {
            throw new RuntimeException($this->tlsFailure);
        }

        $this->tls = true;
    }

    #[Override]
    public function setTimeout(int $seconds): void
    {
        if (! array_key_exists($this->lastRequest, $this->timeouts)) {
            $this->timeouts[$this->lastRequest] = $seconds;
        }
    }

    #[Override]
    public function close(): void
    {
        $this->connected = false;
    }

    private function queueReply(string $request): void
    {
        if ($this->inData) {
            $this->inData = '.' !== $request;
            if ($this->inData) {
                return;
            }
        }

        $reply = $this->scriptedReply($request);
        if (null === $reply) {
            $this->pending = [...$this->pending, ...$this->defaultReply($request)];
            return;
        }

        $this->pending = [...$this->pending, ...$reply];
        $this->inData  = 'DATA' === $request && str_starts_with($reply[array_key_last($reply)] ?? '', '354');
    }

    /**
     * @return list<string>|null
     */
    private function scriptedReply(string $request): ?array
    {
        foreach ($this->scripted as $prefix => $replies) {
            if (! str_starts_with($request, (string) $prefix) || [] === $replies) {
                continue;
            }

            return array_shift($this->scripted[$prefix]);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function defaultReply(string $request): array
    {
        $command = strtoupper(substr($request, offset: 0, length: 4));
        if ('AUTH' === $command) {
            return $this->startAuth(strtoupper($request));
        }

        return match ($command) {
            'EHLO'                                      => $this->ehloReply(),
            'HELO'                                      => ['250 mail.example.com'],
            'STAR'                                      => ['220 2.0.0 Ready to start TLS'],
            'DATA'                                      => $this->startData(),
            'QUIT'                                      => ['221 2.0.0 Bye'],
            'MAIL', 'RCPT', 'RSET', 'NOOP', 'VRFY', '.' => ['250 2.0.0 OK'],
            default                                     => [
                array_shift($this->authReplies) ?? '500 5.5.2 Unrecognised command',
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function startAuth(string $request): array
    {
        $this->authReplies = match ($request) {
            'AUTH LOGIN' => ['334 UGFzc3dvcmQ6', '235 2.7.0 Accepted'],
            default      => ['235 2.7.0 Accepted'],
        };

        return match ($request) {
            'AUTH LOGIN'    => ['334 VXNlcm5hbWU6'],
            'AUTH CRAM-MD5' => ['334 ' . base64_encode(self::CRAM_MD5_CHALLENGE)],
            default         => ['334 '],
        };
    }

    /**
     * @return list<string>
     */
    private function startData(): array
    {
        $this->inData = true;

        return ['354 End data with <CR><LF>.<CR><LF>'];
    }

    /**
     * @return list<string>
     */
    private function ehloReply(): array
    {
        $lines = ['mail.example.com', ...$this->capabilities];
        $reply = [];
        foreach ($lines as $index => $line) {
            $reply[] = ((count($lines) - 1) === $index ? '250 ' : '250-') . $line;
        }

        return $reply;
    }
}
