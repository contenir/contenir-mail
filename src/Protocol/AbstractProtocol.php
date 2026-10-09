<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Protocol\Exception\LogicException;
use Contenir\Mail\Validator\HostnameValidator;
use SensitiveParameter;

use function array_map;
use function array_shift;
use function count;
use function implode;
use function in_array;
use function is_array;
use function preg_split;
use function str_starts_with;
use function strlen;

use const PREG_SPLIT_DELIM_CAPTURE;

/**
 * Provides low-level methods for concrete adapters to communicate with a
 * remote mail server and track requests and responses.
 *
 * Requests and responses travel over a Connection: the one given to the
 * constructor, or a StreamConnection that openConnection() opens.
 *
 * The log and getRequest() never hold credentials: LOGIN, AUTHENTICATE,
 * AUTH, USER, PASS and APOP arguments are replaced by "[redacted]", and
 * requests sent with sendSensitive() are logged as their redacted form.
 *
 * @api
 *
 * @mago-expect analysis:missing-constant-type Subclasses may redeclare these laminas-mail constants untyped.
 * @mago-expect analysis:missing-property-type Subclasses may redeclare these laminas-mail properties untyped.
 * @mago-expect analysis:missing-parameter-type The laminas-mail signatures Protocol\Smtp overrides.
 * @mago-expect analysis:missing-return-type Protocol\Smtp overrides these without return types.
 * @mago-expect lint:cyclomatic-complexity The protected API Protocol\Smtp builds on, kept from laminas-mail.
 * @mago-expect lint:too-many-methods The protected API Protocol\Smtp builds on, kept from laminas-mail.
 */
abstract class AbstractProtocol
{
    /**
     * Mail default EOL string
     */
    public const EOL = "\r\n";

    /**
     * Default timeout in seconds for initiating session
     */
    public const TIMEOUT_CONNECTION = 30;

    /** Bytes read for one line of a response, as fgets() read them before */
    public const int RESPONSE_LINE_LENGTH = 1023;

    /** What a redacted credential is logged as */
    public const string REDACTED = '[redacted]';

    /**
     * Maximum of the transaction log
     *
     * @var int
     */
    protected $maximumLog = 64;

    /**
     * Hostname or IP address of remote server
     *
     * @var string
     */
    protected $host;

    /**
     * Validates the remote and HELO host names
     *
     * @var HostnameValidator
     */
    protected $validHost;

    /**
     * Last request sent to server, with credentials redacted
     *
     * @var string|null
     */
    protected $request;

    /**
     * Array of server responses to last request
     *
     * @var array<array-key, string>|null
     */
    protected $response;

    /**
     * Log of mail requests and server responses for a session
     *
     * @var list<string>
     */
    private array $log = [];

    private ?ConnectionInterface $connection = null;

    /**
     * @param  string  $host OPTIONAL Hostname of remote connection (default: 127.0.0.1)
     * @param  int $port OPTIONAL Port number (default: null)
     * @param  ConnectionInterface|null $connection OPTIONAL The connection to use, a StreamConnection by default
     * @throws Exception\RuntimeException
     */
    public function __construct(
        $host = '127.0.0.1',
        protected $port = null,
        ?ConnectionInterface $connection = null,
    ) {
        $this->validHost = HostnameValidator::forConnection();

        if (! $this->validHost->isValid($host)) {
            throw new Exception\RuntimeException(implode(', ', $this->validHost->getMessages()));
        }

        $this->host       = $host;
        $this->connection = $connection;
    }

    /**
     * Class destructor to cleanup open resources
     */
    public function __destruct()
    {
        $this->_disconnect();
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
     * Set the maximum log size
     *
     * @param int $maximumLog Maximum log size
     */
    public function setMaximumLog($maximumLog)
    {
        $this->maximumLog = (int) $maximumLog;
    }

    /**
     * Get the maximum log size
     *
     * @return int the maximum log size
     */
    public function getMaximumLog()
    {
        return $this->maximumLog;
    }

    /**
     * Create a connection to the remote host
     *
     * Concrete adapters for this class will implement their own unique connect
     * scripts, using openConnection() to open the connection.
     */
    abstract public function connect();

    /**
     * Retrieve the last client request, with credentials redacted
     *
     * @return string|null
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Retrieve the last server response
     *
     * @return array<array-key, string>|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Retrieve the transaction log, with credentials redacted
     *
     * @return string
     */
    public function getLog()
    {
        return implode('', $this->log);
    }

    /**
     * Reset the transaction log
     */
    public function resetLog()
    {
        $this->log = [];
    }

    /**
     * Add the transaction log
     *
     * @param  string $value new transaction
     *
     * @mago-expect lint:method-name The protected name Protocol\Smtp calls.
     */
    protected function _addLog($value)
    {
        if ($this->maximumLog >= 0 && count($this->log) >= $this->maximumLog) {
            array_shift($this->log);
        }

        $this->log[] = $value;
    }

    /**
     * Open the connection given to the constructor, or a new StreamConnection.
     *
     * @throws Exception\RuntimeException When the connection cannot be made.
     */
    protected function openConnection(ConnectionConfig $config, int $port): void
    {
        $this->connection = LoggingConnection::decorate($this->connection ?? new StreamConnection(), $config->logger);
        $this->connection->open($config, $port);
    }

    /**
     * Disconnect from remote host and free resource
     *
     * @mago-expect lint:method-name The protected name Protocol\Smtp calls.
     */
    protected function _disconnect()
    {
        $this->connection?->close();
    }

    /**
     * Send the given request followed by a LINEEND to the server.
     *
     * @param  string $request
     * @throws Exception\RuntimeException
     * @return int Number of bytes written to remote host
     *
     * @mago-expect lint:method-name The protected name Protocol\Smtp calls.
     */
    protected function _send(#[SensitiveParameter] $request)
    {
        return $this->sendAndLog($request, Redaction::redact($request));
    }

    /**
     * Send a request that holds a secret, such as a SASL response, logging it as $loggedAs.
     *
     * @throws Exception\RuntimeException
     * @return int Number of bytes written to remote host
     */
    protected function sendSensitive(#[SensitiveParameter] string $request, string $loggedAs = self::REDACTED): int
    {
        return $this->sendAndLog($request, $loggedAs);
    }

    /**
     * Get a line from the stream.
     *
     * @param  int|null $timeout Per-request timeout value if applicable, for adapters that give a command
     *     the timeout its RFC sets
     * @throws Exception\RuntimeException
     * @return string
     *
     * @mago-expect lint:method-name The protected name Protocol\Smtp calls.
     */
    protected function _receive($timeout = null)
    {
        $connection = $this->connection();
        if (null !== $timeout) {
            $connection->setTimeout((int) $timeout);
        }

        try {
            $response = $connection->readLine(self::RESPONSE_LINE_LENGTH);
        } catch (Exception\TimeoutException $e) {
            throw new Exception\RuntimeException("{$this->host} has timed out", previous: $e);
        } catch (Exception\RuntimeException $e) {
            throw new Exception\RuntimeException("Could not read from {$this->host}", previous: $e);
        }

        $this->_addLog($response);

        return $response;
    }

    /**
     * Parse server response for successful codes
     *
     * Read the response from the stream and check for expected return code.
     * Throws a Contenir\Mail\Protocol\Exception\ExceptionInterface if an unexpected code is returned.
     *
     * @param  string|int|array<string|int> $code One or more codes that indicate a successful response
     * @param  int|null $timeout Per-request timeout value if applicable
     * @throws Exception\RuntimeException
     * @return string Last line of response string
     *
     * @mago-expect lint:method-name The protected name Protocol\Smtp calls.
     * @mago-expect analysis:unreachable-match-arm The analyser does not carry $errMsg into the next iteration of the loop.
     */
    protected function _expect($code, $timeout = null)
    {
        $this->response = [];
        $errMsg         = null;
        $cmd            = '';
        $msg            = '';

        if (! is_array($code)) {
            $code = [$code];
        }

        $codes = array_map(strval(...), $code);
        do {
            $result           = $this->_receive($timeout);
            $this->response[] = $result;
            [$cmd, $more, $msg] = self::splitReply($result);
            $errMsg = match (true) {
                null !== $errMsg => "{$errMsg} {$msg}",
                in_array($cmd, $codes, strict: true) => null,
                default                              => $msg,
            };
        } while (str_starts_with($more, '-'));

        if (null !== $errMsg) {
            throw new Exception\RuntimeException($errMsg, (int) $cmd);
        }

        return $msg;
    }

    /**
     * The connection requests and responses travel over.
     *
     * @throws Exception\RuntimeException When there is none, or it is closed.
     */
    protected function connection(): ConnectionInterface
    {
        $connection = $this->connection;
        if (null === $connection || ! $connection->isConnected()) {
            throw new Exception\RuntimeException("No connection has been established to {$this->host}");
        }

        return $connection;
    }

    /**
     * The reply code, the separator after it ("-" for a line that more lines follow), and the text.
     *
     * @return array{string, string, string}
     */
    private static function splitReply(string $line): array
    {
        $parts = preg_split(
            pattern: '/([\s-]+)/',
            subject: $line,
            limit: 2,
            flags: PREG_SPLIT_DELIM_CAPTURE,
        );
        // @codeCoverageIgnoreStart
        // preg_split() fails only on an invalid pattern or a backtracking limit, neither possible with this pattern.
        if (false === $parts) {
            return [$line, '', ''];
        }

        // @codeCoverageIgnoreEnd

        return [$parts[0] ?? '', $parts[1] ?? '', $parts[2] ?? ''];
    }

    /**
     * @throws Exception\RuntimeException
     *
     * @mago-expect lint:no-boolean-flag-parameter Whether the bytes are a secret decides how they are written.
     */
    private static function write(
        ConnectionInterface $connection,
        #[SensitiveParameter]
        string $data,
        bool $public,
    ): void {
        if ($public) {
            $connection->write($data);

            return;
        }

        Redaction::writeSecret($connection, $data);
    }

    /**
     * Send the request, as a secret when it is logged as something else.
     *
     * @throws Exception\RuntimeException
     */
    private function sendAndLog(#[SensitiveParameter] string $request, string $loggedAs): int
    {
        $connection    = $this->connection();
        $this->request = $loggedAs;
        $this->_addLog($loggedAs . self::EOL);

        try {
            self::write($connection, $request . self::EOL, $loggedAs === $request);
        } catch (Exception\RuntimeException $e) {
            throw new Exception\RuntimeException("Could not send request to {$this->host}", previous: $e);
        }

        return strlen($request) + strlen(self::EOL);
    }
}
