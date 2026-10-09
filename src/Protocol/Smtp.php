<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\CallbackChannel;
use Contenir\Mail\Protocol\Smtp\Chunks;
use Contenir\Mail\Protocol\Smtp\LineLengthCheck;
use Contenir\Mail\Protocol\Smtp\MessageData;
use Generator;
use Override;
use SensitiveParameter;

use function array_key_exists;
use function array_replace;
use function array_slice;
use function count;
use function explode;
use function get_resource_type;
use function implode;
use function in_array;
use function is_array;
use function is_resource;
use function ltrim;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function stream_get_meta_data;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * An SMTP client session (RFC 5321): EHLO, STARTTLS, AUTH, MAIL, RCPT, DATA, RSET, NOOP, VRFY and QUIT.
 *
 * ```php
 * $smtp = new Smtp(new ConnectionConfig('mail.example.com'), authenticator: new Login('user', $password));
 * $smtp = new Smtp('mail.example.com', 587, ['ssl' => 'tls']);
 * ```
 *
 * STARTTLS is required unless the connection is TLS from the start or Security::None is
 * chosen explicitly; a server that does not offer it is refused. Without a port, STARTTLS
 * connects to the submission port 587, TLS from the start to 465 and a plain connection to 25. Credentials are only sent
 * over TLS unless "allow_insecure_auth" is set.
 *
 * Every command argument is checked for CR, LF and NUL before it is sent, and message
 * lines are normalised to CRLF with leading dots doubled, so neither can start a command
 * of its own.
 *
 * @mago-expect lint:too-many-methods The SMTP command set, kept as one class as in laminas-mail.
 * @mago-expect lint:cyclomatic-complexity The SMTP command set, kept as one class as in laminas-mail.
 * @mago-expect lint:kan-defect The SMTP command set, kept as one class as in laminas-mail.
 * @mago-expect lint:too-many-properties Session state from laminas-mail plus the EHLO capabilities.
 * @mago-expect lint:no-boolean-flag-parameter MAIL's ESMTP parameters SMTPUTF8 and BODY=8BITMIME are on or off.
 * @mago-expect lint:method-name The underscored methods override AbstractProtocol's, kept from laminas-mail.
 */
final class Smtp extends AbstractProtocol
{
    use ProtocolTrait;

    /**
     * RFC 5322 section 2.2.3 limits a line to 998 bytes, excluding the CRLF.
     *
     * @see https://tools.ietf.org/html/rfc5322#section-2.2.3
     */
    public const int SMTP_LINE_LIMIT = 998;

    /** The most lines read for one reply, so a hostile server cannot keep a client reading forever */
    public const int MAX_REPLY_LINES = 100;

    /** Written to the session log and getRequest() in place of a line that carries credentials */
    public const string HIDDEN_LINE = '[credentials hidden]';

    /** Keys of the $config array when a ConnectionConfig is given */
    private const array KEYS = ['use_complete_quit', 'allow_insecure_auth'];

    /** Further keys of the laminas-mail $config array */
    private const array LEGACY_KEYS = ['ssl', 'novalidatecert'];

    private const array LEGACY_SSL = ['', 'none', 'ssl', 'tls'];

    private ConnectionConfig $config;

    private ?AuthenticatorInterface $authenticator;

    private bool $useCompleteQuit;

    private bool $allowInsecureAuth;

    /**
     * EHLO keywords in upper case, mapped to their parameters.
     *
     * @var array<string, string>
     */
    private array $capabilities = [];

    /**
     * The text of each line of the last reply, after the code.
     *
     * @var list<string>
     */
    private array $replyTexts = [];

    /** Whether the socket is encrypted */
    private bool $encrypted = false;

    /** Whether EHLO or HELO has been accepted */
    private bool $sess = false;

    /** Whether AUTH has succeeded in this session */
    private bool $auth = false;

    /** Whether MAIL has been accepted for the current transaction */
    private bool $mail = false;

    /** Whether the current transaction was opened with SMTPUTF8 */
    private bool $utf8 = false;

    /** Whether at least one RCPT has been accepted for the current transaction */
    private bool $rcpt = false;

    /**
     * Pass a ConnectionConfig, with an optional $config of "use_complete_quit" and
     * "allow_insecure_auth"; or the laminas-mail arguments: a host name, a port and an array
     * that may also hold "ssl" ("ssl" for TLS from the start, "tls" for STARTTLS, "none" or
     * false for a plain connection) and "novalidatecert", or the ConnectionConfig keys. The
     * array may also be given first, with "host" and "port" in it. Without "ssl" or
     * "security", STARTTLS is required.
     *
     * @param ConnectionConfig|string|array<array-key, mixed> $host
     * @param array<array-key, mixed>|null $config
     * @param ConnectionInterface|null $connection The connection to the server, a StreamConnection by default.
     * @throws Exception\InvalidArgumentException When a setting is invalid or given twice.
     * @throws Exception\RuntimeException When the host name is invalid.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(
        #[SensitiveParameter]
        ConnectionConfig|string|array $host = '127.0.0.1',
        ?int $port = null,
        #[SensitiveParameter]
        ?array $config = null,
        ?AuthenticatorInterface $authenticator = null,
        ?ConnectionInterface $connection = null,
    ) {
        [$reader, $settings] = $host instanceof ConnectionConfig
            ? self::readSettings($host, $port, $config)
            : self::readLegacySettings($host, $port, $config);

        $this->config            = $settings;
        $this->authenticator     = $authenticator;
        $this->useCompleteQuit   = $reader->bool('use_complete_quit', default: true);
        $this->allowInsecureAuth = $reader->bool('allow_insecure_auth', default: false);
        $this->setNoValidateCert(! $settings->verifyPeer);

        parent::__construct(
            $settings->host,
            $settings->portOr(
                plain: 25,
                tls: 465,
                startTls: 587,
            ),
            $connection,
        );
    }

    public function getConnectionConfig(): ConnectionConfig
    {
        return $this->config;
    }

    public function getAuthenticator(): ?AuthenticatorInterface
    {
        return $this->authenticator;
    }

    /**
     * Whether credentials may be sent over an unencrypted connection ("allow_insecure_auth").
     */
    public function allowsInsecureAuth(): bool
    {
        return $this->allowInsecureAuth;
    }

    /**
     * Whether quit() sends QUIT; turn it off to keep a connection a server would otherwise close.
     */
    public function setUseCompleteQuit(bool $useCompleteQuit): void
    {
        $this->useCompleteQuit = $useCompleteQuit;
    }

    public function useCompleteQuit(): bool
    {
        return $this->useCompleteQuit;
    }

    /**
     * Open the connection: TLS from the start for Security::Tls, otherwise plain until STARTTLS.
     *
     * The peer is verified unless setNoValidateCert(true) or ConnectionConfig::$verifyPeer turned it off.
     *
     * @throws Exception\RuntimeException When the connection fails.
     */
    #[Override]
    public function connect(): bool
    {
        $this->openConnection($this->connectionSettings(), (int) $this->port);
        $this->encrypted = Security::Tls === $this->config->security;

        return true;
    }

    /**
     * Read the greeting, send EHLO, upgrade with STARTTLS when configured, and authenticate.
     *
     * @param string $host The client's own host name or address literal.
     * @throws Exception\ExceptionInterface When a session exists, the name is invalid, or the server
     *     refuses a step. With Security::StartTls a server that does not offer or refuses STARTTLS
     *     is refused: the session never continues unencrypted.
     */
    public function helo(string $host = '127.0.0.1'): void
    {
        if ($this->sess) {
            throw new Exception\RuntimeException('Cannot issue HELO to existing session');
        }

        if (! $this->validHost->isValid($host)) {
            throw new Exception\RuntimeException(implode(', ', $this->validHost->getMessages()));
        }

        $this->_expect(220, 300);
        $this->ehlo($host);

        if (Security::StartTls === $this->config->security) {
            $this->startTls($host);
        }

        $this->sess = true;
        $this->auth();
    }

    public function hasSession(): bool
    {
        return $this->sess;
    }

    /**
     * Whether the server listed the EHLO keyword, such as "SIZE" or "SMTPUTF8".
     */
    public function hasCapability(string $keyword): bool
    {
        return array_key_exists(strtoupper($keyword), $this->capabilities);
    }

    /**
     * The EHLO keywords the server listed, in upper case, mapped to their parameters.
     *
     * @return array<string, string>
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Whether the socket is encrypted, from the start or after STARTTLS.
     */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /**
     * Start a transaction with the envelope sender; an empty string sends the null reverse-path "<>".
     *
     * @param int|null $size The message size in bytes, declared when the server supports SIZE (RFC 1870).
     * @param bool $smtpUtf8 Whether any envelope address is not ASCII (RFC 6531).
     * @param bool $eightBit Whether the message has 8-bit content, declared when the server supports 8BITMIME.
     * @throws Exception\ExceptionInterface When there is no session, the address is unsafe, the message is
     *     larger than the server accepts, SMTPUTF8 is needed but not offered, or the server refuses.
     */
    public function mail(string $from, ?int $size = null, bool $smtpUtf8 = false, bool $eightBit = false): void
    {
        [$command, $utf8] = $this->mailCommand($from, $size, $smtpUtf8, $eightBit);
        $this->command($command);
        $this->_expect(250, 300);

        $this->mail = true;
        $this->utf8 = $utf8;
        $this->rcpt = false;
    }

    /**
     * @throws Exception\ExceptionInterface When MAIL has not been sent, the address is unsafe or not
     *     ASCII outside an SMTPUTF8 transaction, or the server refuses it.
     */
    public function rcpt(string $to): void
    {
        if (! $this->mail) {
            throw new Exception\RuntimeException('No sender reverse path has been supplied');
        }

        $this->command($this->rcptCommand($to, $this->utf8));
        $this->_expect([250, 251], 300);
        $this->rcpt = true;
    }

    /**
     * Start a transaction: MAIL, then RCPT for each recipient, ready for data().
     *
     * When the server offers PIPELINING (RFC 2920), the commands are sent together and
     * the replies read after, which saves a round trip for each recipient; DATA stays a
     * step of its own, so a refusal never leaves a message half sent. Otherwise each
     * command waits for its reply.
     *
     * Every reply is read before a refusal is reported, so the session stays in step,
     * and the transaction is then abandoned with RSET, so the session is ready for the
     * next message. A refusal throws as mail() and rcpt() do, with the server's reply.
     *
     * @param non-empty-list<string> $recipients
     * @throws Exception\ExceptionInterface When there is no session, an address is unsafe, the
     *     message is larger than the server accepts, SMTPUTF8 is needed but not offered, or the
     *     server refuses the sender or a recipient.
     */
    public function envelope(
        string $from,
        array $recipients,
        ?int $size = null,
        bool $smtpUtf8 = false,
        bool $eightBit = false,
    ): void {
        [$command, $utf8] = $this->mailCommand($from, $size, $smtpUtf8, $eightBit);
        $commands = [$command];
        foreach ($recipients as $recipient) {
            $commands[] = $this->rcptCommand($recipient, $utf8);
        }

        $refusal = $this->hasCapability('PIPELINING') ? $this->pipeline($commands) : $this->stepByStep($commands);
        if (null !== $refusal) {
            $this->rset();

            throw $refusal;
        }

        $this->mail = true;
        $this->utf8 = $utf8;
        $this->rcpt = true;
    }

    /**
     * Send every command, then read every reply, keeping the first refusal.
     *
     * @param list<string> $commands
     * @throws Exception\ExceptionInterface When a command cannot be sent or a reply is malformed.
     */
    private function pipeline(array $commands): ?Exception\RuntimeException
    {
        foreach ($commands as $command) {
            $this->command($command);
        }

        $refusal = null;
        foreach ($commands as $command) {
            $refusal = $this->replyTo($command, $refusal);
        }

        return $refusal;
    }

    /**
     * Send each command and read its reply, stopping at the first refusal.
     *
     * @param list<string> $commands
     * @throws Exception\ExceptionInterface When a command cannot be sent or a reply is malformed.
     */
    private function stepByStep(array $commands): ?Exception\RuntimeException
    {
        foreach ($commands as $command) {
            $this->command($command);
            $refusal = $this->replyTo($command, null);
            if (null !== $refusal) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * Read the reply to MAIL or RCPT, keeping the first refusal; anything other than a
     * refusal, such as a malformed reply, is thrown at once.
     *
     * @throws Exception\RuntimeException When the reply is not a reply at all.
     */
    private function replyTo(string $command, ?Exception\RuntimeException $refusal): ?Exception\RuntimeException
    {
        try {
            $this->_expect(str_starts_with($command, 'MAIL') ? 250 : [250, 251], 300);
        } catch (Exception\RuntimeException $e) {
            if (0 === $e->getCode()) {
                throw $e;
            }

            return $refusal ?? $e;
        }

        return $refusal;
    }

    /**
     * The MAIL command for a sender, and whether the transaction needs SMTPUTF8.
     *
     * @return array{string, bool}
     * @throws Exception\ExceptionInterface When there is no session, the address is unsafe, the
     *     message is larger than the server accepts, or SMTPUTF8 or 8BITMIME is needed but not offered.
     */
    private function mailCommand(string $from, ?int $size, bool $smtpUtf8, bool $eightBit): array
    {
        if (! $this->sess) {
            throw new Exception\RuntimeException('A valid session has not been started');
        }

        $utf8 = $smtpUtf8 || self::isUtf8($from);
        if ($utf8 && ! $this->hasCapability('SMTPUTF8')) {
            throw new Exception\RuntimeException(
                'The server does not offer SMTPUTF8, which addresses that are not ASCII need',
            );
        }

        if ($eightBit && ! $this->hasCapability('8BITMIME')) {
            throw new Exception\RuntimeException(
                'The message has 8-bit content, which the server does not accept without 8BITMIME;'
                    . ' send it quoted-printable or base64 encoded',
            );
        }

        $command   = 'MAIL FROM:<' . self::path($from) . '>';
        $sizeLimit = $this->capabilities['SIZE'] ?? null;
        if (null !== $size && null !== $sizeLimit) {
            $limit = (int) $sizeLimit;
            if ($limit > 0 && $size > $limit) {
                throw new Exception\RuntimeException(sprintf(
                    'The message is %d bytes; the server accepts at most %d',
                    $size,
                    $limit,
                ));
            }

            $command .= " SIZE={$size}";
        }

        if ($eightBit) {
            $command .= ' BODY=8BITMIME';
        }

        if ($utf8) {
            $command .= ' SMTPUTF8';
        }

        return [$command, $utf8];
    }

    /**
     * @throws Exception\ExceptionInterface When the address is unsafe, or not ASCII outside an SMTPUTF8 transaction.
     */
    private function rcptCommand(string $to, bool $utf8): string
    {
        if (! $utf8 && self::isUtf8($to)) {
            throw new Exception\RuntimeException(
                'A recipient that is not ASCII needs a transaction started with SMTPUTF8',
            );
        }

        return 'RCPT TO:<' . self::path($to) . '>';
    }

    /**
     * Send the message. Bare CR and LF become CRLF and a leading "." is doubled after that;
     * nothing else in the message is changed.
     *
     * The message is written in chunks of Chunks::SIZE bytes, and the log holds
     * "[DATA n bytes]" in place of its text.
     *
     * @throws Exception\InvalidArgumentException When a line is longer than SMTP_LINE_LIMIT, which
     *     the message's encoding should have prevented; nothing is sent in that case.
     * @throws Exception\ExceptionInterface When no recipient was accepted or the server refuses the message.
     */
    public function data(string $data): void
    {
        $this->sendData(static fn(): Generator => Chunks::ofString($data));
    }

    /**
     * Send the message read from a stream, from its start to its end, as data() does.
     *
     * The stream is read twice, first to check the line lengths, so it must be seekable;
     * a message written to php://temp is never held in memory as a whole.
     *
     * @param resource $stream
     * @throws Exception\InvalidArgumentException When $stream is not an open, seekable stream, or a
     *     line is longer than SMTP_LINE_LIMIT; nothing is sent in that case.
     * @throws Exception\ExceptionInterface When no recipient was accepted or the server refuses the message.
     */
    public function dataFromStream(mixed $stream): void
    {
        if (! is_resource($stream) || 'stream' !== get_resource_type($stream)) {
            throw new Exception\InvalidArgumentException('Expected an open stream');
        }

        if (! stream_get_meta_data($stream)['seekable']) {
            throw new Exception\InvalidArgumentException('The message stream must be seekable');
        }

        $this->sendData(
            /** @throws Exception\RuntimeException When the stream cannot be read. */
            static fn(): Generator => Chunks::ofStream($stream),
        );
    }

    /**
     * Abandon the current transaction, so a new one can start on the same session.
     *
     * @throws Exception\ExceptionInterface When the server refuses.
     */
    public function rset(): void
    {
        $this->_send('RSET');
        $this->_expect([250, 220]);

        $this->mail = false;
        $this->rcpt = false;
    }

    /**
     * @throws Exception\ExceptionInterface When the server refuses.
     */
    public function noop(): void
    {
        $this->_send('NOOP');
        $this->_expect(250, 300);
    }

    /**
     * @throws Exception\ExceptionInterface When the name contains CR, LF or NUL, or the server refuses.
     */
    public function vrfy(string $user): void
    {
        $this->command("VRFY {$user}");
        $this->_expect([250, 251, 252], 300);
    }

    /**
     * End the session, sending QUIT unless setUseCompleteQuit(false) was called.
     *
     * @throws Exception\ExceptionInterface When the server refuses QUIT.
     */
    public function quit(): void
    {
        if (! $this->sess) {
            return;
        }

        $this->auth = false;
        $this->sess = false;
        $this->mail = false;
        $this->rcpt = false;
        if ($this->useCompleteQuit) {
            $this->_send('QUIT');
            $this->_expect(221, 300);
        }
    }

    /**
     * Authenticate with the configured authenticator, if any. helo() calls this.
     *
     * @throws Exception\ExceptionInterface When already authenticated, the connection is not
     *     encrypted and "allow_insecure_auth" is off, the server does not offer the mechanism,
     *     or the server rejects the credentials.
     */
    public function auth(): void
    {
        if ($this->auth) {
            throw new Exception\RuntimeException('Already authenticated for this session');
        }

        $authenticator = $this->authenticator;
        if (null === $authenticator) {
            return;
        }

        if (! $this->encrypted && ! $this->allowInsecureAuth) {
            throw new Exception\RuntimeException(
                'Refusing to send credentials over an unencrypted connection; use TLS or STARTTLS',
            );
        }

        $mechanism = strtoupper($authenticator->mechanism());
        $offered   = explode(' ', $this->capabilities['AUTH'] ?? '');
        if (! in_array($mechanism, $offered, strict: true)) {
            throw new Exception\RuntimeException(sprintf(
                'The server does not offer AUTH %s; it offers "%s"',
                $mechanism,
                $this->capabilities['AUTH'] ?? '',
            ));
        }

        $authenticator->authenticate(new CallbackChannel($this->exchange(...), $this->exchangeSecret(...)));
        $this->auth = true;
    }

    /**
     * Whether AUTH has succeeded in this session.
     */
    public function isAuthenticated(): bool
    {
        return $this->auth;
    }

    /**
     * Send QUIT (unless turned off) and close the socket.
     */
    public function disconnect(): void
    {
        $this->_disconnect();
    }

    /**
     * The configured settings, with peer verification as setNoValidateCert() last left it.
     *
     * @throws \Contenir\Mail\Exception\InvalidArgumentException Never: the settings were checked when constructed.
     */
    private function connectionSettings(): ConnectionConfig
    {
        return new ConnectionConfig(
            host: $this->config->host,
            port: $this->config->port,
            security: $this->config->security,
            verifyPeer: $this->validateCert(),
            timeout: $this->config->timeout,
            tls: $this->config->tls,
        );
    }

    /**
     * Send EHLO, falling back to HELO for servers that do not support it, and record the
     * keywords the server lists.
     *
     * @throws Exception\ExceptionInterface When the server refuses both.
     */
    private function ehlo(string $host): void
    {
        $this->capabilities = [];
        try {
            $this->command("EHLO {$host}");
            $this->_expect(250, 300);
            $this->capabilities = self::capabilities(array_slice($this->replyTexts, offset: 1));
        } catch (Exception\RuntimeException) {
            $this->command("HELO {$host}");
            $this->_expect(250, 300);
        }
    }

    /**
     * Read a reply of at most MAX_REPLY_LINES lines and check the code of its last line.
     *
     * @param int|string|array<array-key, mixed> $code One or more codes that mean success.
     * @param int|null $timeout Seconds to wait for each line.
     * @return string The text of the last line, after the code.
     * @throws Exception\RuntimeException When the code is unexpected or the reply is too long.
     */
    #[Override]
    protected function _expect($code, $timeout = null) // phpcs:ignore
    {
        $codes            = is_array($code) ? $code : [$code];
        $this->response   = [];
        $this->replyTexts = [];
        do {
            if (count($this->response) === self::MAX_REPLY_LINES) {
                throw new Exception\RuntimeException(sprintf(
                    'The server reply is longer than %d lines',
                    self::MAX_REPLY_LINES,
                ));
            }

            $line             = rtrim($this->_receive($timeout), characters: "\r\n");
            $this->response[] = $line;
            if (1 !== preg_match('/^\d{3}(?:[ -]|$)/', $line)) {
                throw new Exception\RuntimeException('The server sent a malformed reply line');
            }

            $reply              = (int) $line;
            $text               = substr($line, offset: 4);
            $this->replyTexts[] = $text;
        } while ('-' === substr($line, offset: 3, length: 1));

        if (! in_array($reply, $codes, strict: true)) {
            throw new Exception\RuntimeException(implode(' ', $this->replyTexts), $reply);
        }

        return $text;
    }

    /**
     * Close the session before closing the connection. A server that has already gone cannot be
     * asked to QUIT, so its failure to answer is ignored and the connection closed anyway.
     *
     * @mago-expect lint:no-empty-catch-clause The connection is closed next, which is all that is left to do.
     */
    #[Override]
    protected function _disconnect() // phpcs:ignore
    {
        try {
            $this->quit();
        } catch (Exception\ExceptionInterface) {
        }

        parent::_disconnect();
        $this->encrypted = false;
    }

    /**
     * Read EHLO keyword lines such as "SIZE 1000" or the old "AUTH=LOGIN PLAIN".
     *
     * @param list<string> $lines
     * @return array<string, string> Keywords in upper case, mapped to their parameters.
     */
    private static function capabilities(array $lines): array
    {
        $capabilities = [];
        foreach ($lines as $line) {
            $pair = explode(
                ' ',
                str_replace(
                    search: '=',
                    replace: ' ',
                    subject: $line,
                ),
                limit: 2,
            );
            if ('' !== $pair[0]) {
                $capabilities[strtoupper($pair[0])] = strtoupper(ltrim($pair[1] ?? ''));
            }
        }

        return $capabilities;
    }

    /**
     * @param array<array-key, mixed>|null $config
     * @return array{ConfigReader, ConnectionConfig}
     * @throws Exception\InvalidArgumentException When a port is given besides the ConnectionConfig.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    private static function readSettings(
        ConnectionConfig $connection,
        ?int $port,
        #[SensitiveParameter]
        ?array $config,
    ): array {
        if (null !== $port) {
            throw new Exception\InvalidArgumentException('Give the port in the ConnectionConfig');
        }

        return [ConfigReader::read(self::class, $config ?? [], self::KEYS), $connection];
    }

    /**
     * @param string|array<array-key, mixed> $host
     * @param array<array-key, mixed>|null $config
     * @return array{ConfigReader, ConnectionConfig}
     * @throws Exception\InvalidArgumentException When a setting is invalid or given twice.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    private static function readLegacySettings(
        #[SensitiveParameter]
        string|array $host,
        ?int $port,
        #[SensitiveParameter]
        ?array $config,
    ): array {
        $values = is_array($host)
            ? array_replace($host, $config ?? [])
            : array_replace($config ?? [], ['host' => $host, 'port' => $port]);
        if (array_key_exists('ssl', $values) && false === $values['ssl']) {
            $values['ssl'] = 'none';
        }

        $reader = ConfigReader::read(
            self::class,
            $values,
            [
                ...ConnectionConfig::KEYS,
                ...self::KEYS,
                ...self::LEGACY_KEYS,
            ],
        );

        return [$reader, self::legacyConnection($reader)];
    }

    /**
     * @throws Exception\InvalidArgumentException When a setting is invalid or given twice.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting has the wrong type.
     */
    private static function legacyConnection(ConfigReader $reader): ConnectionConfig
    {
        $connection = ConnectionConfig::fromReader($reader);
        $ssl        = $reader->nullableString('ssl');
        if (null !== $ssl && $reader->has('security')) {
            throw new Exception\InvalidArgumentException('Give either "ssl" or "security", not both');
        }

        if ($reader->has('novalidatecert') && $reader->has('verify_peer')) {
            throw new Exception\InvalidArgumentException('Give either "novalidatecert" or "verify_peer", not both');
        }

        if (null !== $ssl && ! in_array(strtolower($ssl), self::LEGACY_SSL, strict: true)) {
            throw new Exception\InvalidArgumentException("{$ssl} is unsupported SSL type");
        }

        return new ConnectionConfig(
            host: $connection->host,
            port: $connection->port,
            security: null === $ssl ? $connection->security : Security::fromLegacy($ssl),
            verifyPeer: $reader->has('verify_peer')
                ? $connection->verifyPeer
                : ! $reader->bool('novalidatecert', default: false),
            timeout: $connection->timeout,
            tls: $connection->tls,
        );
    }

    /**
     * Check an envelope address: no control characters or angle brackets, and no spaces
     * outside a quoted local part.
     *
     * @throws Exception\InvalidArgumentException When the address could end the path or the command.
     */
    private static function path(string $address): string
    {
        $unquoted = (string) preg_replace('/^"(?:[^"\\\\]|\\\\.)*"/', replacement: '""', subject: $address);
        if (1 === preg_match('/[\x00-\x1F\x7F<>]/', $address) || 1 === preg_match('/\s/', $unquoted)) {
            throw new Exception\InvalidArgumentException(
                'An envelope address must not contain control characters, angle brackets or unquoted spaces',
            );
        }

        return $address;
    }

    private static function isUtf8(string $address): bool
    {
        return 1 === preg_match('/[\x80-\xFF]/', $address);
    }

    /**
     * Ask for STARTTLS, upgrade the connection and repeat EHLO, as RFC 3207 requires.
     *
     * The connection refuses to start TLS when the server sent more after agreeing, since an
     * attacker could have injected it into the plain-text stream (CVE-2011-0411).
     *
     * @throws Exception\ExceptionInterface When the server does not offer or refuses STARTTLS, or TLS fails.
     */
    private function startTls(string $host): void
    {
        if (! $this->hasCapability('STARTTLS')) {
            throw new Exception\RuntimeException(
                'The server does not offer STARTTLS; set security to "tls" for TLS from the start, '
                    . 'or to "none" explicitly to send without encryption',
            );
        }

        $this->_send('STARTTLS');
        try {
            $this->_expect(220, 180);
        } catch (Exception\RuntimeException $e) {
            throw new Exception\RuntimeException(
                "The server refused STARTTLS: {$e->getMessage()}",
                (int) $e->getCode(),
                $e,
            );
        }

        $this->connection()->enableTls();
        $this->encrypted = true;
        $this->ehlo($host);
    }

    /**
     * @param Closure(): Generator<int, string> $chunks The message text, from its start each time it is called.
     * @throws Exception\ExceptionInterface
     */
    private function sendData(Closure $chunks): void
    {
        if (! $this->rcpt) {
            throw new Exception\RuntimeException('No recipient forward path has been supplied');
        }

        LineLengthCheck::check($chunks(), self::SMTP_LINE_LIMIT);

        $this->_send('DATA');
        $this->_expect(354, 120);

        $connection = $this->connection();
        $bytes      = 0;
        foreach (MessageData::encode($chunks()) as $chunk) {
            try {
                $connection->write($chunk);
            } catch (Exception\RuntimeException $e) {
                throw new Exception\RuntimeException("Could not send request to {$this->host}", previous: $e);
            }

            $bytes += strlen($chunk);
        }

        $this->_addLog("[DATA {$bytes} bytes]" . self::EOL);
        $this->_send('.');
        $this->_expect(250, 600);
        $this->mail = false;
        $this->rcpt = false;
    }

    /**
     * Send a command line, refusing CR, LF and NUL so it cannot carry a second command.
     *
     * @throws Exception\InvalidArgumentException When the line contains CR, LF or NUL.
     * @throws Exception\RuntimeException When there is no connection.
     */
    private function command(#[SensitiveParameter] string $line): void
    {
        $this->_send(self::singleLine($line));
    }

    /**
     * @throws Exception\InvalidArgumentException When the line contains CR, LF or NUL.
     */
    private static function singleLine(#[SensitiveParameter] string $line): string
    {
        if (1 === preg_match('/[\r\n\0]/', $line)) {
            throw new Exception\InvalidArgumentException('An SMTP command must not contain CR, LF or NUL');
        }

        return $line;
    }

    /**
     * A plain step of the authenticator's Channel.
     *
     * @throws Exception\ExceptionInterface When the line is unsafe or the server replies with another code.
     */
    private function exchange(string $line, int $expect): string
    {
        $this->command($line);

        return $this->_expect($expect, 300);
    }

    /**
     * A step of the authenticator's Channel that carries credentials, kept out of the log and getRequest().
     *
     * @throws Exception\ExceptionInterface When the line is unsafe or the server replies with another code.
     */
    private function exchangeSecret(#[SensitiveParameter] string $line, int $expect): string
    {
        $this->request = self::HIDDEN_LINE;
        $this->sendSensitive(self::singleLine($line), self::HIDDEN_LINE);

        return $this->_expect($expect, 300);
    }
}
