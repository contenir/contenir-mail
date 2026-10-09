<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Protocol\Pop3\Response;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as XoauthEncoder;
use LogicException;
use SensitiveParameter;

use function array_map;
use function explode;
use function in_array;
use function md5;
use function preg_match;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * A POP3 client (RFC 1939), with STLS (RFC 2595) and CAPA (RFC 2449).
 *
 * Connections use STLS unless told otherwise, and fail rather than continue
 * in plain text when the server does not offer it. Message numbers must be
 * positive integers, no command line can contain CR, LF or NUL, and
 * responses are bounded by ResponseLimits.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity The POP3 command set kept from laminas-mail, one method per command.
 * @mago-expect lint:kan-defect The POP3 command set kept from laminas-mail, one method per command.
 * @mago-expect lint:too-many-methods The POP3 command set kept from laminas-mail, one method per command.
 */
class Pop3
{
    use ProtocolTrait;

    /**
     * Default timeout in seconds for initiating session
     */
    public const int TIMEOUT_CONNECTION = 30;

    /**
     * saves if server supports top
     */
    public ?bool $hasTop = null;

    /**
     * greeting timestamp for apop
     */
    protected ?string $timestamp = null;

    private ConnectionConfig $config;

    private ConnectionInterface $connection;

    private ResponseLimits $limits;

    /**
     * Public constructor
     *
     * A host name, port, "ssl" and $novalidatecert are the laminas-mail form, deprecated since 0.3.0:
     * pass a ConnectionConfig, or none and call connect() with one.
     *
     * @param string|ConnectionConfig $host hostname or IP address of POP3 server, or its settings; if given connect() is called
     * @param int|null $port port of POP3 server, null for default (110 or 995 for ssl)
     * @param string|bool|Security|null $ssl null for STLS, 'ssl' for TLS, 'tls' for STLS, false for plain text
     * @param bool $novalidatecert set to true to skip TLS certificate validation
     * @param ConnectionInterface|null $connection the connection to use, a StreamConnection by default
     * @throws Exception\ExceptionInterface
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When the port is out of range.
     */
    public function __construct(
        string|ConnectionConfig $host = '',
        ?int $port = null,
        string|bool|Security|null $ssl = null,
        bool $novalidatecert = false,
        ?ConnectionInterface $connection = null,
    ) {
        $this->config         = new ConnectionConfig(security: Security::StartTls);
        $this->connection     = $connection ?? new StreamConnection();
        $this->limits         = new ResponseLimits();
        $this->novalidatecert = $novalidatecert;

        if ($host instanceof ConnectionConfig || '' !== $host) {
            $this->connect($host, $port, $ssl);
        }
    }

    /**
     * Public destructor: logs out if connected, and does nothing otherwise
     */
    public function __destruct()
    {
        $this->logout();
    }

    /**
     * A protocol holds a live connection, so it cannot be serialized.
     *
     * @return never
     * @throws LogicException
     */
    public function __serialize(): array
    {
        throw new LogicException(static::class . ' cannot be serialized');
    }

    /**
     * Refuse to unserialize, so that a crafted payload never reaches the destructor.
     *
     * @return never
     * @throws LogicException
     */
    public function __wakeup(): void
    {
        throw new LogicException(static::class . ' cannot be unserialized');
    }

    /**
     * Bound how much the server may send for one command.
     */
    public function setResponseLimits(ResponseLimits $limits): static
    {
        $this->limits = $limits;

        return $this;
    }

    /**
     * The settings of the last connection, or of the next if none was made yet.
     */
    public function getConnectionConfig(): ConnectionConfig
    {
        return $this->config;
    }

    /**
     * Open connection to POP3 server
     *
     * A host, port and "ssl" are the laminas-mail form, deprecated since 0.3.0: pass a ConnectionConfig.
     *
     * @param string|ConnectionConfig $host hostname or IP address of POP3 server, or its settings
     * @param int|null $port of POP3 server, default is 110 (995 for ssl); ignored with a ConnectionConfig
     * @param string|bool|Security|null $ssl null for STLS, 'ssl' for TLS, 'tls' for STLS, false for plain text; ignored with a ConnectionConfig
     * @throws Exception\ExceptionInterface When the server cannot be reached, does not greet, or TLS cannot be negotiated.
     * @throws Exception\InvalidArgumentException When $ssl is not a recognised setting.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When the port is out of range.
     * @return string welcome message
     *
     * @mago-expect analysis:deprecated-method Reached only for the laminas-mail form, so that PHP 8.4 and later report its use.
     */
    public function connect(
        string|ConnectionConfig $host,
        ?int $port = null,
        string|bool|Security|null $ssl = null,
    ): string {
        $this->config = $host instanceof ConnectionConfig
            ? $host
            : LegacyOptions::config($host, $port, $ssl, $this->validateCert(), self::TIMEOUT_CONNECTION);
        $this->novalidatecert = ! $this->config->verifyPeer;

        $this->connection->open($this->config, $this->config->portOr(110, 995));

        $welcome         = $this->readResponse();
        $matches         = [];
        $this->timestamp = 1 === preg_match('/<[^<>@]+@[^<>]*>/', $welcome, $matches) ? $matches[0] ?? null : null;

        if (Security::StartTls === $this->config->security) {
            $this->startTls();
        }

        return $welcome;
    }

    /**
     * Send a request
     *
     * @param string $request your request without newline
     * @throws Exception\RuntimeException When the connection fails.
     * @throws Exception\InvalidArgumentException When the request contains CR, LF or NUL.
     */
    public function sendRequest(#[SensitiveParameter] string $request): void
    {
        $this->connection->write(CommandLine::terminate($request));
    }

    /**
     * read a response
     *
     * @param  bool $multiline response has multiple lines and should be read until "<nl>.<nl>"
     * @throws Exception\RuntimeException When the server answers -ERR, closes the connection, or exceeds the limits.
     * @return string response
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature, kept for compatibility.
     */
    public function readResponse(bool $multiline = false): string
    {
        $response = $this->readRemoteResponse();

        if ('+OK' !== $response->status()) {
            throw new Exception\RuntimeException(self::failure($response->message()));
        }

        if (! $multiline) {
            return $response->message();
        }

        $message = '';
        $line    = $this->nextLine();
        while ('.' !== rtrim($line, characters: "\r\n")) {
            if (str_starts_with($line, '.')) {
                $line = substr($line, offset: 1);
            }

            $message .= $line;
            if (strlen($message) > $this->limits->maxResponseSize) {
                throw new Exception\RuntimeException(
                    "The server's response exceeds the limit of {$this->limits->maxResponseSize} bytes",
                );
            }

            $line = $this->nextLine();
        }

        return $message;
    }

    /**
     * The failure message, with the reason the server gave, such as "[IN-USE] mailbox locked",
     * made safe to display.
     */
    private static function failure(string $reason): string
    {
        $reason = SafeText::display($reason);

        return '' === $reason ? 'last request failed' : "last request failed: {$reason}";
    }

    /**
     * read a response
     * return extracted status / message from response
     *
     * @throws Exception\RuntimeException
     */
    protected function readRemoteResponse(): Response
    {
        [$status, $message] = self::pair(trim($this->nextLine()), default: '');

        return new Response($status, $message);
    }

    /**
     * Send request and get response
     *
     * @see sendRequest()
     * @see readResponse()
     *
     * @param  string $request    request
     * @param  bool   $multiline  multiline response?
     * @return string             result from readResponse()
     * @throws Exception\ExceptionInterface
     */
    public function request(#[SensitiveParameter] string $request, bool $multiline = false): string
    {
        $this->sendRequest($request);

        return $this->readResponse($multiline);
    }

    /**
     * End communication with POP3 server (also closes socket); sends nothing when not connected
     *
     * @mago-expect lint:no-empty-catch-clause QUIT may fail on a dead or never opened connection, which is closed either way.
     */
    public function logout(): void
    {
        try {
            $this->request('QUIT');
        } catch (Exception\ExceptionInterface) {
        }

        $this->connection->close();
    }

    /**
     * Get capabilities from POP3 server
     *
     * @return list<string> list of capabilities
     * @throws Exception\ExceptionInterface
     */
    public function capa(): array
    {
        return explode("\n", $this->request('CAPA', true));
    }

    /**
     * Login to POP3 server. Can use APOP
     *
     * @param  string $user     username
     * @param  string $password password
     * @param  bool   $tryApop  should APOP be tried?
     * @throws Exception\ExceptionInterface
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature, kept for compatibility.
     */
    public function login(string $user, #[SensitiveParameter] string $password, bool $tryApop = true): void
    {
        if ($tryApop && null !== $this->timestamp && $this->apop($this->timestamp, $user, $password)) {
            return;
        }

        $this->request("USER {$user}");
        $this->request("PASS {$password}");
    }

    /**
     * Sign in with SASL (RFC 5034): an OAuth 2.0 access token (XOAUTH2), as Gmail and
     * Microsoft 365 require, or a password proved without sending it (SCRAM-SHA-256).
     *
     * A refused token is answered with the empty response that ends the exchange
     * (RFC 7628, section 3.2.3) before this throws, with the server's reason.
     *
     * @throws Exception\RuntimeException When the server refuses the mechanism or the credentials,
     *     or a SCRAM server cannot prove it knows the password.
     * @throws Exception\InvalidArgumentException When a token provider returns an invalid token.
     * @throws Exception\ExceptionInterface When the connection fails.
     */
    public function authenticate(XOAuth2|ScramSha256 $auth): void
    {
        if ($auth instanceof ScramSha256) {
            $this->authenticateScram($auth);

            return;
        }

        $initial = $auth->initialResponse();
        $this->sendRequest('AUTH XOAUTH2');
        $response = $this->readRemoteResponse();
        if ('+' !== $response->status()) {
            throw new Exception\RuntimeException(self::failure($response->message()));
        }

        $this->sendRequest($initial);
        $response = $this->readRemoteResponse();
        if ('+' === $response->status()) {
            $this->sendRequest('');
            $final = $this->readRemoteResponse();

            throw new Exception\RuntimeException(XoauthEncoder::refusal($response->message(), $final->message()));
        }

        if ('+OK' !== $response->status()) {
            $reason = SafeText::display($response->message());

            throw new Exception\RuntimeException('' === $reason ? 'The server refused the access token' : $reason);
        }
    }

    /**
     * SCRAM-SHA-256: server-first and server-final arrive in "+" continuations, and the second
     * is answered with an empty response before "+OK". A step the client refuses is cancelled
     * with "*" (RFC 5034, section 4) before this throws.
     *
     * @throws Exception\ExceptionInterface
     */
    private function authenticateScram(ScramSha256 $auth): void
    {
        $scram = $auth->start();
        $this->sendRequest("AUTH {$auth->mechanism()}");
        $this->saslChallenge();
        $this->sendRequest($scram->initialResponse());
        $challenge = $this->saslChallenge();
        try {
            $response = $scram->respond($challenge);
        } catch (Exception\RuntimeException $e) {
            $this->cancelSasl($e);
        }

        $this->sendRequest($response);
        $challenge = $this->saslChallenge();
        try {
            $scram->verify($challenge);
        } catch (Exception\RuntimeException $e) {
            $this->cancelSasl($e);
        }

        $this->sendRequest('');
        $response = $this->readRemoteResponse();
        if ('+OK' !== $response->status()) {
            throw new Exception\RuntimeException(self::failure($response->message()));
        }
    }

    /**
     * The base64 text of the next "+" continuation, refusing any other reply in its place.
     *
     * @throws Exception\RuntimeException When the server refuses the mechanism or the credentials, or ends
     *     the exchange without the server-final message that proves it knows the password.
     */
    private function saslChallenge(): string
    {
        $response = $this->readRemoteResponse();

        return match ($response->status()) {
            '+'     => $response->message(),
            '+OK' => throw new Exception\RuntimeException(
                'The server ended SCRAM-SHA-256 without proving it knows the password',
            ),
            default => throw new Exception\RuntimeException(self::failure($response->message())),
        };
    }

    /**
     * Cancel the exchange with "*", read the server's reply, and throw $reason.
     *
     * @throws Exception\ExceptionInterface
     */
    private function cancelSasl(Exception\RuntimeException $reason): never
    {
        $this->sendRequest('*');
        $this->readRemoteResponse();

        throw $reason;
    }

    /**
     * Make STAT call for message count and size sum
     *
     * @param  int $messages  out parameter with count of messages
     * @param  int $octets    out parameter with size in octets of messages
     * @throws Exception\ExceptionInterface
     *
     * @param-out int $messages
     * @param-out int $octets
     */
    public function status(mixed &$messages, mixed &$octets): void
    {
        [$count, $size] = self::pair($this->request('STAT'), default: '0');
        $messages = (int) $count;
        $octets   = (int) $size;
    }

    /**
     * Make LIST call for size of message(s)
     *
     * @param  int|null $msgno number of message, null for all
     * @return int|array<int, int> size of given message or list with array(num => size)
     * @throws Exception\ExceptionInterface
     */
    public function getList(?int $msgno = null): int|array
    {
        if (null !== $msgno) {
            [, $size] = self::pair($this->request('LIST ' . self::messageNumber($msgno)), default: '0');

            return (int) $size;
        }

        $messages = [];
        foreach (explode("\n", $this->request('LIST', true)) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            [$number, $size] = self::pair(trim($line), default: '0');
            $messages[(int) $number] = (int) $size;
        }

        return $messages;
    }

    /**
     * Make UIDL call for getting a uniqueid
     *
     * @param  int|null $msgno number of message, null for all
     * @return string|array<int, string> uniqueid of message or list with array(num => uniqueid)
     * @throws Exception\ExceptionInterface
     */
    public function uniqueid(?int $msgno = null): string|array
    {
        if (null !== $msgno) {
            [, $id] = self::pair($this->request('UIDL ' . self::messageNumber($msgno)), default: '');

            return $id;
        }

        $messages = [];
        foreach (explode("\n", $this->request('UIDL', true)) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            [$number, $id] = self::pair(trim($line), default: '');
            $messages[(int) $number] = $id;
        }

        return $messages;
    }

    /**
     * Make TOP call for getting headers and maybe some body lines
     * This method also sets hasTop - before it it's not known if top is supported
     *
     * The fallback makes normal RETR call, which retrieves the whole message. Additional
     * lines are not removed.
     *
     * @param  int  $msgno    number of message
     * @param  int  $lines    number of wanted body lines (empty line is inserted after header lines)
     * @param  bool $fallback fallback with full retrieve if top is not supported
     * @throws Exception\RuntimeException
     * @throws Exception\ExceptionInterface
     * @return string message headers with wanted body lines
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature, kept for compatibility.
     */
    public function top(int $msgno, int $lines = 0, bool $fallback = false): string
    {
        $number = self::messageNumber($msgno);
        if (false === $this->hasTop) {
            if ($fallback) {
                return $this->retrieve($msgno);
            }

            throw new Exception\RuntimeException('top not supported and no fallback wanted');
        }

        $this->hasTop = true;

        $lines = $lines < 1 ? 0 : $lines;

        try {
            return $this->request("TOP {$number} {$lines}", true);
        } catch (Exception\RuntimeException $e) {
            $this->hasTop = false;
            if (! $fallback) {
                throw $e;
            }
        }

        return $this->retrieve($msgno);
    }

    /**
     * Make a RETR call for retrieving a full message with headers and body
     *
     * @param  int $msgno  message number
     * @return string message
     * @throws Exception\ExceptionInterface
     */
    public function retrieve(int $msgno): string
    {
        return $this->request('RETR ' . self::messageNumber($msgno), true);
    }

    /**
     * Make a NOOP call, maybe needed for keeping the server happy
     *
     * @throws Exception\ExceptionInterface
     */
    public function noop(): void
    {
        $this->request('NOOP');
    }

    /**
     * Make a DELE count to remove a message
     *
     * @throws Exception\ExceptionInterface
     */
    public function delete(int $msgno): void
    {
        $this->request('DELE ' . self::messageNumber($msgno));
    }

    /**
     * Make RSET call, which rollbacks delete requests
     *
     * @throws Exception\ExceptionInterface
     */
    public function undelete(): void
    {
        $this->request('RSET');
    }

    /**
     * get the next line from the connection, refusing lines over the limit
     *
     * @throws Exception\RuntimeException
     */
    private function nextLine(): string
    {
        $line = $this->connection->readLine($this->limits->maxLineLength);
        if (strlen($line) === $this->limits->maxLineLength && ! str_ends_with($line, "\n")) {
            throw new Exception\RuntimeException(
                "The server sent a line longer than {$this->limits->maxLineLength} bytes",
            );
        }

        return $line;
    }

    /**
     * Upgrade the connection with STLS, which the server must advertise in CAPA.
     *
     * @throws Exception\RuntimeException When the server does not offer STLS, refuses it, or the handshake fails.
     * @throws Exception\ExceptionInterface
     */
    private function startTls(): void
    {
        try {
            $capabilities = array_map(static fn(string $line): string => strtoupper(trim($line)), $this->capa());
        } catch (Exception\RuntimeException $e) {
            throw new Exception\RuntimeException(
                'cannot enable TLS: the server does not list its capabilities; refusing to continue in plain text',
                previous: $e,
            );
        }

        if (! in_array('STLS', $capabilities, strict: true)) {
            throw new Exception\RuntimeException(
                'cannot enable TLS: the server does not offer STLS; refusing to continue in plain text',
            );
        }

        try {
            $this->request('STLS');
        } catch (Exception\RuntimeException $e) {
            throw new Exception\RuntimeException('cannot enable TLS: the server refused STLS', previous: $e);
        }

        $this->connection->enableTls();
    }

    /**
     * Log in with APOP, reporting whether the server accepted it.
     *
     * @throws Exception\ExceptionInterface When the user name contains CR, LF or NUL, or the connection fails.
     */
    private function apop(string $timestamp, string $user, #[SensitiveParameter] string $password): bool
    {
        try {
            $this->request("APOP {$user} " . md5($timestamp . $password));
        } catch (Exception\RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * The first word of a response and the rest, or $default when there is no rest.
     *
     * @return array{string, string}
     */
    private static function pair(string $text, string $default): array
    {
        $parts = explode(
            separator: ' ',
            string: $text,
            limit: 2,
        );

        return [$parts[0], $parts[1] ?? $default];
    }

    /**
     * @throws Exception\InvalidArgumentException When the number is below 1.
     */
    private static function messageNumber(int $msgno): int
    {
        if ($msgno < 1) {
            throw new Exception\InvalidArgumentException('Message numbers start at 1');
        }

        return $msgno;
    }
}
