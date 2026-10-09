<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Imap\NamespaceEntry;
use Contenir\Mail\Imap\Namespaces;
use Contenir\Mail\Protocol\Imap\MailboxName;
use Contenir\Mail\Protocol\Imap\UidMapping;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as XoauthEncoder;
use Contenir\Mail\SystemClock;
use Generator;
use LogicException;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

use function array_chunk;
use function array_map;
use function array_pop;
use function array_shift;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function intval;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;
use function preg_match;
use function preg_replace;
use function range;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function strval;
use function substr;
use function trim;

use const INF;

/**
 * An IMAP4rev1 client (RFC 3501).
 *
 * Connections use STARTTLS unless told otherwise, and fail rather than
 * continue in plain text when the server does not offer it.
 *
 * Every argument that reaches the server is checked or escaped: strings are
 * quoted or sent as literals, flags must be atoms, message numbers must form
 * a valid sequence set, and no command line can contain CR, LF or NUL.
 * Responses are bounded by ResponseLimits.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity The IMAP command set kept from laminas-mail, one method per command.
 * @mago-expect lint:kan-defect The IMAP command set kept from laminas-mail, one method per command.
 * @mago-expect lint:too-many-methods The IMAP command set kept from laminas-mail, one method per command.
 * @mago-expect analysis:mixed-assignment Response tokens are strings and lists nested to any depth.
 */
class Imap
{
    use ProtocolTrait;

    /**
     * Default timeout in seconds for initiating session
     */
    public const int TIMEOUT_CONNECTION = 30;

    /** One part of an RFC 3501 sequence-set, which joins them by ",": a number or "*", or a range of them with ":" */
    private const string SEQUENCE_RANGE = '/^(?:[1-9]\d*|\*)(?::(?:[1-9]\d*|\*))?$/D';

    /** One part of the sequence set of an ESEARCH result: a 32-bit number, or a range of them */
    private const string SEARCH_RANGE = '/^[1-9]\d{0,9}(?::[1-9]\d{0,9})?$/D';

    /** An RFC 3501 quoted string at the offset, with its backslash-escaped quotes and backslashes */
    private const string QUOTED_STRING = '/\\G"((?:[^"\\\\]|\\\\.)*+)"/s';

    /** RFC 3501 flag: an atom, optionally after a backslash */
    private const string FLAG = '/^\\\\?[^\x00-\x20\x7F-\xFF(){%*"\\\\\]]+$/D';

    /**
     * counter for request tag
     */
    protected int $tagCount = 0;

    private ConnectionConfig $config;

    private ConnectionInterface $connection;

    private ResponseLimits $limits;

    /** Bytes read so far for the current command's response */
    private int $responseBytes = 0;

    /**
     * The server's capabilities in upper case, as last read; null until read, and again after STARTTLS,
     * which RFC 3501 requires clients to re-read them after.
     *
     * @var list<string>|null
     */
    private ?array $capabilities = null;

    /** A STATUS item: RFC 3501, DELETED and SIZE (RFC 9051, RFC 8438), HIGHESTMODSEQ (RFC 7162) */
    private const string STATUS_ITEM = '/^(?:MESSAGES|RECENT|UIDNEXT|UIDVALIDITY|UNSEEN|DELETED|SIZE|HIGHESTMODSEQ)$/Di';

    /** A sort key (RFC 5256, RFC 5957), optionally reversed */
    private const string SORT_KEY = '/^(?:REVERSE )?(?:ARRIVAL|CC|DATE|FROM|SIZE|SUBJECT|TO|DISPLAYFROM|DISPLAYTO)$/Di';

    /** The largest literal sent without waiting for the server under LITERAL- (RFC 7888) */
    public const int MAX_LITERAL_MINUS_SIZE = 4096;

    /** The most ids an ESEARCH result may expand to */
    public const int MAX_SEARCH_RESULTS = 1_000_000;

    private bool $preferImap4Rev2 = true;

    private bool $utf8Mailboxes = false;

    private bool $imap4Rev2 = false;

    /**
     * The IDLE in progress, until its tagged reply is read: its tag, and whether DONE has been sent.
     *
     * @var array{string, bool}|null
     */
    private ?array $idle = null;

    /**
     * Public constructor
     *
     * A host name, port, "ssl" and $novalidatecert are the laminas-mail form, deprecated since 0.3.0:
     * pass a ConnectionConfig, or none and call connect() with one.
     *
     * @param string|ConnectionConfig $host hostname or IP address of IMAP server, or its settings; if given connect() is called
     * @param int|null $port port of IMAP server, null for default (143 or 993 for ssl)
     * @param string|bool|Security|null $ssl null for STARTTLS, 'ssl' for TLS, 'tls' for STARTTLS, false for plain text
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
     * Open connection to IMAP server
     *
     * A host, port and "ssl" are the laminas-mail form, deprecated since 0.3.0: pass a ConnectionConfig.
     *
     * @param string|ConnectionConfig $host hostname or IP address of IMAP server, or its settings
     * @param int|null $port of IMAP server, default is 143 (993 for ssl); ignored with a ConnectionConfig
     * @param string|bool|Security|null $ssl null for STARTTLS, 'ssl' for TLS, 'tls' for STARTTLS, false for plain text; ignored with a ConnectionConfig
     * @throws Exception\ExceptionInterface When the server cannot be reached, does not greet, or TLS cannot be negotiated.
     * @throws Exception\InvalidArgumentException When $ssl is not a recognised setting.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When the port is out of range.
     *
     * @mago-expect analysis:deprecated-method Reached only for the laminas-mail form, so that PHP 8.4 and later report its use.
     */
    public function connect(
        string|ConnectionConfig $host,
        ?int $port = null,
        string|bool|Security|null $ssl = null,
    ): void {
        $this->config = $host instanceof ConnectionConfig
            ? $host
            : LegacyOptions::config($host, $port, $ssl, $this->validateCert(), self::TIMEOUT_CONNECTION);
        $this->novalidatecert = ! $this->config->verifyPeer;

        $this->responseBytes = 0;
        $this->connection->open($this->config, $this->config->portOr(143, 993));

        if (! $this->assumedNextLine('* OK')) {
            throw new Exception\RuntimeException('host doesn\'t allow connection');
        }

        if (Security::StartTls === $this->config->security) {
            $this->startTls();
        }
    }

    /**
     * get the next line from the connection, refusing lines and responses over the limits
     *
     * @throws Exception\RuntimeException
     * @return string next line
     */
    protected function nextLine(): string
    {
        $line = $this->connection->readLine($this->limits->maxLineLength);
        if (strlen($line) === $this->limits->maxLineLength && ! str_ends_with($line, "\n")) {
            throw new Exception\RuntimeException(
                "The server sent a line longer than {$this->limits->maxLineLength} bytes",
            );
        }

        $this->countResponseBytes(strlen($line));

        return $line;
    }

    /**
     * get next line and assume it starts with $start. some requests give a simple
     * feedback so we can quickly check if we can go on.
     *
     * @param  string $start the first bytes we assume to be in the next line
     * @return bool line starts with $start
     * @throws Exception\RuntimeException
     */
    protected function assumedNextLine(string $start): bool
    {
        return str_starts_with($this->nextLine(), $start);
    }

    /**
     * get next line and split the tag. that's the normal case for a response line
     *
     * @param  string|null $tag tag of line is returned by reference
     * @return string next line
     * @throws Exception\RuntimeException
     */
    protected function nextTaggedLine(?string &$tag): string
    {
        $parts = explode(
            separator: ' ',
            string: $this->nextLine(),
            limit: 2,
        );
        $tag = $parts[0];

        return $parts[1] ?? '';
    }

    /**
     * split a given line in tokens. a token is literal of any form or a list
     *
     * We start to decode the response here. The understood tokens are:
     * literal
     * "literal" or also "lit\\er\"al"
     * {bytes}<NL>literal
     * (literals*)
     * All tokens are returned in an array. Literals in braces (the last understood
     * token in the list) are returned as an array of tokens. I.e. the following response:
     * "foo" baz {3}<NL>bar ("f\\\"oo" bar)
     * would be returned as:
     * array('foo', 'baz', 'bar', array('f\\\"oo', 'bar'));
     * Lists may follow each other without a space, as in ((a b)(c d)).
     *
     * @param  string $line line to decode
     * @return array<mixed> tokens, literals are returned as string, lists as array
     * @throws Exception\RuntimeException When a literal is larger than the response limit allows.
     *
     * @mago-expect lint:halstead The tokenizer kept from laminas-mail handles every token kind in one pass.
     */
    protected function decodeLine(string $line): array
    {
        $tokens = [];
        $stack  = [];

        $line   = rtrim($line) . ' ';
        $offset = 0;
        $space  = strpos($line, needle: ' ');
        $split  = strpos($line, needle: ')(');
        while (false !== ($space = self::nextFrom($line, ' ', $offset, $space))) {
            $split   = self::nextFrom($line, ')(', $offset, $split);
            $pos     = false === $split ? $space : min($space, $split + 1);
            $token   = substr($line, $offset, $pos - $offset);
            $literal = [];
            if ('' === $token) {
                $offset = $pos + 1;
                continue;
            }

            while (str_starts_with($token, '(')) {
                $stack[] = $tokens;
                $tokens  = [];
                $token   = substr($token, offset: 1);
            }

            $start  = $pos - strlen($token);
            $quoted = [];
            if (str_starts_with($token, '"') && 1 === preg_match(self::QUOTED_STRING, $line, $quoted, offset: $start)) {
                $tokens[] = preg_replace('/\\\\(.)/s', replacement: '$1', subject: $quoted[1] ?? '');
                $offset   = $start + strlen($quoted[0] ?? '');
                continue;
            }

            if (1 === preg_match('/^\{(\d+)\}$/', $token, $literal)) {
                $tokens[] = $this->readLiteral($literal[1] ?? '0');
                $line     = trim($this->nextLine()) . ' ';
                $offset   = 0;
                $space    = strpos($line, needle: ' ');
                $split    = strpos($line, needle: ')(');
                continue;
            }

            if ([] !== $stack && str_ends_with($token, ')')) {
                // closing braces are not separated by spaces, so we need to count them
                $braces = strlen($token);
                $token  = rtrim($token, characters: ')');
                $braces -= strlen($token) + 1;
                if ('' !== $token) {
                    $tokens[] = $token;
                }

                $token  = $tokens;
                $tokens = array_pop($stack);
                while ($braces-- > 0 && [] !== $stack) {
                    $tokens[] = $token;
                    $token    = $tokens;
                    $tokens   = array_pop($stack);
                }
            }

            $tokens[] = $token;
            $offset   = $pos;
        }

        // maybe the server forgot to send some closing braces
        while ([] !== $stack) {
            $child    = $tokens;
            $tokens   = array_pop($stack);
            $tokens[] = $child;
        }

        return $tokens;
    }

    /**
     * Where $needle next occurs in $line at or after $offset, given where it was last found.
     *
     * The last position is kept while the offset has not passed it, so each line is searched
     * once however many tokens it holds.
     */
    private static function nextFrom(string $line, string $needle, int $offset, int|false $found): int|false
    {
        if (false === $found || $found >= $offset) {
            return $found;
        }

        return strpos($line, $needle, $offset);
    }

    /**
     * read a response "line" (could also be more than one real line if response has {..}<NL>)
     * and do a simple decode
     *
     * @param  mixed $tokens decoded tokens are returned by reference, if $dontParse
     *                       is true the unparsed line is returned here
     * @param  string $wantedTag check for this tag for response code. Default '*' is
     *                           continuation tag.
     * @param  bool $dontParse if true only the unparsed line is returned $tokens
     * @return bool if returned tag matches wanted tag
     * @throws Exception\RuntimeException
     *
     * @param-out ($dontParse is true ? string : array<mixed>) $tokens
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature, kept for compatibility.
     */
    public function readLine(mixed &$tokens = [], string $wantedTag = '*', bool $dontParse = false): bool
    {
        $tag    = null;
        $line   = $this->nextTaggedLine($tag);
        $tokens = $dontParse ? $line : $this->decodeLine($line);

        // if tag is wanted tag we might be at the end of a multiline response
        return $tag === $wantedTag;
    }

    /**
     * read all lines of response until given tag is found (last line of response)
     *
     * @param  string $tag the tag of your request
     * @param  bool $dontParse if true every line is returned unparsed instead of
     *                         the decoded tokens
     * @return ($dontParse is true ? list<string>|bool|null : list<array<mixed>>|bool|null) tokens if success, false if error, null if bad request
     * @throws Exception\RuntimeException
     */
    public function readResponse(string $tag, bool $dontParse = false): array|bool|null
    {
        $lines  = [];
        $tokens = null;
        while (! $this->readLine($tokens, $tag, $dontParse)) {
            $lines[] = $tokens;
        }

        $status = is_string($tokens) ? substr($tokens, offset: 0, length: 2) : $tokens[0] ?? null;

        return match ($status) {
            'OK'    => [] === $lines ? true : $lines,
            'NO'    => false,
            default => null,
        };
    }

    /**
     * send a request
     *
     * @param  string $command your request command
     * @param  array<mixed> $tokens additional parameters to command, use escapeString() to prepare
     * @param  string|null $tag provide a tag otherwise an autogenerated is returned
     * @throws Exception\RuntimeException When the server refuses a literal or the connection fails.
     * @throws Exception\InvalidArgumentException When a token could inject a command or a literal is malformed.
     *
     * @param-out string $tag
     */
    public function sendRequest(
        string $command,
        #[SensitiveParameter]
        array $tokens = [],
        ?string &$tag = null,
    ): void {
        $tag  = null === $tag || '' === $tag ? $this->nextTag() : $this->startResponse($tag);
        $line = "{$tag} {$command}";

        foreach ($tokens as $token) {
            if (is_array($token)) {
                $literal = self::literal($token);
                $this->sendLiteralSize($line, strlen($literal));
                $this->connection->write($literal);
                $line = '';
                continue;
            }

            if (! is_string($token) && ! is_int($token)) {
                throw new Exception\InvalidArgumentException('Request tokens must be strings, integers or literals');
            }

            if (1 === preg_match('/\{\d+\+?\}$/D', (string) $token)) {
                throw new Exception\InvalidArgumentException(
                    'A request token may not end in a literal marker; use escapeString() for literals',
                );
            }

            $line .= " {$token}";
        }

        $this->connection->write(CommandLine::terminate($line));
    }

    /**
     * End the line with the size of the literal that follows: non-synchronising ({size+}, RFC 7888)
     * when the server offers LITERAL+, or LITERAL- or IMAP4rev2 and the literal is at most
     * MAX_LITERAL_MINUS_SIZE bytes; otherwise synchronising, waiting for the server's "+".
     * Only capabilities already read are consulted, so no command is sent in the middle of another.
     *
     * @throws Exception\RuntimeException When the server refuses the literal or the connection fails.
     */
    private function sendLiteralSize(#[SensitiveParameter] string $line, int $size): void
    {
        $capabilities = $this->capabilities ?? [];
        if (
            in_array('LITERAL+', $capabilities, strict: true)
            || (
                $size <= self::MAX_LITERAL_MINUS_SIZE
                && (
                    $this->imap4Rev2
                    || in_array('LITERAL-', $capabilities, strict: true)
                )
            )
        ) {
            $this->connection->write(CommandLine::terminate("{$line} {{$size}+}"));

            return;
        }

        $this->connection->write(CommandLine::terminate("{$line} {{$size}}"));
        if (! $this->assumedNextLine('+')) {
            throw new Exception\RuntimeException('cannot send literal string');
        }
    }

    /**
     * send a request and get response at once
     *
     * @param  string $command command as in sendRequest()
     * @param  array<mixed> $tokens parameters as in sendRequest()
     * @param  bool $dontParse if true unparsed lines are returned instead of tokens
     * @return ($dontParse is true ? list<string>|bool|null : list<array<mixed>>|bool|null) response as in readResponse()
     * @throws Exception\ExceptionInterface
     */
    public function requestAndResponse(
        string $command,
        #[SensitiveParameter]
        array $tokens = [],
        bool $dontParse = false,
    ): array|bool|null {
        $tag = null;
        $this->sendRequest($command, $tokens, $tag);

        return $this->readResponse($tag, $dontParse);
    }

    /**
     * escape one or more strings for sendRequest()
     *
     * A string is quoted, unless it contains CR, LF or 8-bit bytes, which a
     * quoted string cannot carry: then it is returned as a literal,
     * array('{size}', 'string').
     *
     * @return string|array{string, string}|list<string|array{string, string}> one escaped string, or a list when given several
     * @throws Exception\InvalidArgumentException When a string contains NUL, which IMAP4rev1 cannot send.
     */
    public function escapeString(
        #[SensitiveParameter]
        string $string,
        #[SensitiveParameter]
        string ...$more,
    ): string|array {
        if ([] !== $more) {
            return array_map($this->escapeOne(...), [$string, ...$more]);
        }

        return $this->escapeOne($string);
    }

    /**
     * escape a list with literals or lists
     *
     * @param  array<mixed> $list list with literals or lists as PHP array
     * @return string escaped list for imap
     */
    public function escapeList(array $list): string
    {
        $result = [];
        foreach ($list as $item) {
            $result[] = is_array($item) ? $this->escapeList($item) : (string) $item;
        }

        return '(' . implode(' ', $result) . ')';
    }

    /**
     * Login to IMAP server.
     *
     * The server's capabilities are read first, unless already known, so the
     * password is never sent to a server that advertises LOGINDISABLED.
     *
     * @param  string $user      username
     * @param  string $password  password
     * @return bool success
     * @throws Exception\RuntimeException When the server advertises LOGINDISABLED.
     * @throws Exception\ExceptionInterface
     */
    public function login(string $user, #[SensitiveParameter] string $password): bool
    {
        $arguments = [$this->escapeOne($user), $this->escapeOne($password)];
        if (in_array('LOGINDISABLED', $this->capabilities ?? $this->upperCaseCapabilities(), strict: true)) {
            throw new Exception\RuntimeException(
                'The server does not allow LOGIN on this connection (LOGINDISABLED); connect with TLS or STARTTLS',
            );
        }

        $capabilities = $this->capabilities ?? [];
        if (! $this->succeeded($this->requestAndResponse('LOGIN', $arguments))) {
            return false;
        }

        $this->capabilities = null;
        $this->negotiate($capabilities);

        return true;
    }

    /**
     * Sign in with SASL: an OAuth 2.0 access token (XOAUTH2), as Gmail and Microsoft 365 require,
     * or a password proved without sending it (SCRAM-SHA-256).
     *
     * The first response goes with the command when the server offers SASL-IR (RFC 4959), and
     * after its continuation otherwise. A refused token is answered with the empty
     * response that ends the exchange (RFC 7628, section 3.2.3) before this throws.
     *
     * @throws Exception\ExceptionInterface When the server does not offer the mechanism or refuses the
     *     credentials, the connection fails, a token provider returns an invalid token, or a SCRAM
     *     server cannot prove it knows the password.
     */
    public function authenticate(XOAuth2|ScramSha256 $auth): void
    {
        $mechanism    = $auth->mechanism();
        $capabilities = $this->capabilities ?? $this->upperCaseCapabilities();
        if (! in_array("AUTH={$mechanism}", $capabilities, strict: true)) {
            throw new Exception\RuntimeException("The server does not offer {$mechanism}");
        }

        if ($auth instanceof ScramSha256) {
            $this->authenticateScram($auth, $mechanism, $capabilities);
            $this->negotiate($capabilities);

            return;
        }

        $response = $auth->initialResponse();
        $tag      = $this->sendAuthenticate($mechanism, $response, $capabilities);

        $line = $this->nextLine();
        if (str_starts_with($line, '+')) {
            $this->connection->write(CommandLine::terminate(''));

            throw new Exception\RuntimeException(XoauthEncoder::refusal(
                substr($line, offset: 1),
                $this->taggedReply($tag, $this->nextLine())[1],
            ));
        }

        [$status, $text] = $this->taggedReply($tag, $line);
        if ('OK' !== $status) {
            throw new Exception\RuntimeException('' === $text ? 'The server refused the access token' : $text);
        }

        $this->capabilities = null;
        $this->negotiate($capabilities);
    }

    /**
     * Send AUTHENTICATE with the first response, or wait for its continuation without SASL-IR.
     *
     * @param list<string> $capabilities
     * @throws Exception\ExceptionInterface
     */
    private function sendAuthenticate(
        string $mechanism,
        #[SensitiveParameter]
        string $response,
        array $capabilities,
    ): string {
        $saslIr = in_array('SASL-IR', $capabilities, strict: true);
        $tag    = $this->nextTag();
        $this->connection->write(CommandLine::terminate(
            $saslIr ? "{$tag} AUTHENTICATE {$mechanism} {$response}" : "{$tag} AUTHENTICATE {$mechanism}",
        ));
        if (! $saslIr) {
            $this->awaitContinuation($tag, $mechanism);
            $this->connection->write(CommandLine::terminate($response));
        }

        return $tag;
    }

    /**
     * SCRAM-SHA-256: server-first and server-final arrive in continuations, and the second
     * is answered with an empty response before the tagged reply. A step the client refuses
     * is cancelled with "*" (RFC 3501, section 6.2.2) before this throws.
     *
     * @param list<string> $capabilities
     * @throws Exception\ExceptionInterface
     */
    private function authenticateScram(ScramSha256 $auth, string $mechanism, array $capabilities): void
    {
        $scram     = $auth->start();
        $tag       = $this->sendAuthenticate($mechanism, $scram->initialResponse(), $capabilities);
        $challenge = $this->saslChallenge($tag);
        try {
            $response = $scram->respond($challenge);
        } catch (Exception\RuntimeException $e) {
            $this->cancelSasl($tag, $e);
        }

        $this->connection->write(CommandLine::terminate($response));
        $challenge = $this->saslChallenge($tag);
        try {
            $scram->verify($challenge);
        } catch (Exception\RuntimeException $e) {
            $this->cancelSasl($tag, $e);
        }

        $this->connection->write(CommandLine::terminate(''));
        [$status, $text] = $this->taggedReply($tag, $this->nextLine());
        if ('OK' !== $status) {
            throw new Exception\RuntimeException('' === $text ? 'The server refused the credentials' : $text);
        }

        $this->capabilities = null;
    }

    /**
     * The base64 text of the next continuation, refusing a tagged reply in its place.
     *
     * @throws Exception\RuntimeException When the server refuses the credentials, or ends the
     *     exchange without the server-final message that proves it knows the password.
     */
    private function saslChallenge(string $tag): string
    {
        $line = $this->nextLine();
        if (str_starts_with($line, '+')) {
            return substr($line, offset: 1);
        }

        [$status, $text] = $this->taggedReply($tag, $line);

        throw new Exception\RuntimeException(match (true) {
            'OK' === $status => 'The server ended SCRAM-SHA-256 without proving it knows the password',
            '' === $text => 'The server refused the credentials',
            default => $text,
        });
    }

    /**
     * Cancel the exchange with "*" (RFC 3501, section 6.2.2), read the server's reply, and throw $reason.
     *
     * @throws Exception\ExceptionInterface
     */
    private function cancelSasl(string $tag, Exception\RuntimeException $reason): never
    {
        $this->connection->write(CommandLine::terminate('*'));
        $this->taggedReply($tag, $this->nextLine());

        throw $reason;
    }

    /**
     * A new tag for the next command, with the count of response bytes started again.
     * An IDLE still in progress is ended first, so the command is not sent into it.
     *
     * @throws Exception\RuntimeException When an IDLE in progress cannot be ended.
     */
    private function nextTag(): string
    {
        $this->endIdle();
        ++$this->tagCount;

        return $this->startResponse("TAG{$this->tagCount}");
    }

    /**
     * Start counting the bytes of the response to the command tagged $tag.
     */
    private function startResponse(string $tag): string
    {
        $this->responseBytes = 0;

        return $tag;
    }

    /**
     * Wait for the "+" that asks for the SASL response, refusing a tagged reply in its place.
     *
     * @throws Exception\RuntimeException When the server refuses the mechanism.
     */
    private function awaitContinuation(string $tag, string $mechanism): void
    {
        $line = $this->nextLine();
        if (str_starts_with($line, '+')) {
            return;
        }

        $text = $this->taggedReply($tag, $line)[1];

        throw new Exception\RuntimeException('' === $text ? "The server refused {$mechanism}" : $text);
    }

    /**
     * The status and text of the reply tagged $tag, starting from $line and skipping untagged lines.
     *
     * @return array{string, string}
     * @throws Exception\RuntimeException When the connection fails or a line exceeds the limits.
     */
    private function taggedReply(string $tag, string $line): array
    {
        while (! str_starts_with($line, "{$tag} ")) {
            $line = $this->nextLine();
        }

        $parts = explode(' ', rtrim(substr($line, strlen($tag) + 1), characters: "\r\n"), limit: 2);

        return [strtoupper($parts[0]), SafeText::display($parts[1] ?? '')];
    }

    /**
     * Whether to turn on IMAP4rev2 (RFC 9051) after signing in, when the server offers it.
     * On by default; UTF8=ACCEPT (RFC 6855) is turned on instead when only that is offered.
     */
    public function preferImap4Rev2(bool $prefer = true): static
    {
        $this->preferImap4Rev2 = $prefer;

        return $this;
    }

    /**
     * Whether mailbox names travel as UTF-8, because IMAP4rev2 or UTF8=ACCEPT is enabled,
     * rather than as modified UTF-7.
     */
    public function usesUtf8MailboxNames(): bool
    {
        return $this->utf8Mailboxes;
    }

    /**
     * Whether IMAP4rev2 (RFC 9051) is enabled, which brings ESEARCH, NAMESPACE and STATUS SIZE with it.
     */
    public function isImap4Rev2Enabled(): bool
    {
        return $this->imap4Rev2;
    }

    /**
     * Whether the server advertises a capability, such as "MOVE"; asked once and kept.
     *
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    public function hasCapability(string $capability): bool
    {
        return in_array(
            strtoupper($capability),
            $this->capabilities ?? $this->upperCaseCapabilities(),
            strict: true,
        );
    }

    /**
     * Turn on extensions (RFC 5161), returning the ones the server enabled, in upper case.
     *
     * @return list<string>
     * @throws Exception\ExceptionInterface When the server refuses or cannot be asked.
     */
    public function enable(string ...$extensions): array
    {
        $response = $this->requestAndResponse('ENABLE', $extensions);
        if (false === $response || null === $response) {
            throw new Exception\RuntimeException('The server refused ENABLE');
        }

        $enabled = [];
        foreach (is_array($response) ? $response : [] as $line) {
            if ('ENABLED' !== strtoupper(is_string($line[0] ?? null) ? $line[0] : '')) {
                continue;
            }

            foreach (array_slice($line, offset: 1) as $extension) {
                $enabled[] = strtoupper(is_string($extension) ? $extension : '');
            }
        }

        return $enabled;
    }

    /**
     * After signing in, turn on IMAP4rev2, or else UTF8=ACCEPT, if the server offered it
     * before signing in; either lets mailbox names travel as UTF-8. A server that then
     * refuses ENABLE is used as an IMAP4rev1 server, rather than failing the sign-in.
     *
     * @param list<string> $capabilities
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    private function negotiate(array $capabilities): void
    {
        $extension = match (true) {
            ! $this->preferImap4Rev2 => null,
            in_array('IMAP4REV2', $capabilities, strict: true)   => 'IMAP4rev2',
            in_array('UTF8=ACCEPT', $capabilities, strict: true) => 'UTF8=ACCEPT',
            default                                              => null,
        };
        if (null === $extension) {
            return;
        }

        try {
            $enabled = $this->enable($extension);
        } catch (Exception\RuntimeException) {
            return;
        }

        $this->utf8Mailboxes = in_array(strtoupper($extension), $enabled, strict: true);
        $this->imap4Rev2     = in_array('IMAP4REV2', $enabled, strict: true);
    }

    /**
     * A mailbox name ready to send: UTF-8 when the server takes it, modified UTF-7 otherwise.
     *
     * @return string|array{string, string}
     * @throws Exception\InvalidArgumentException When the name is not UTF-8 or contains NUL.
     */
    private function mailbox(string $name): string|array
    {
        return $this->escapeOne($this->utf8Mailboxes ? $name : MailboxName::encode($name));
    }

    /**
     * logout of imap server; sends nothing when not connected
     *     * @return bool success
     */
    public function logout(): bool
    {
        try {
            $result = $this->succeeded($this->requestAndResponse('LOGOUT'));
        } catch (Exception\ExceptionInterface) {
            $result = false;
        }

        $this->connection->close();

        return $result;
    }

    /**
     * Get capabilities from IMAP server
     *
     * @return array<mixed> list of capabilities
     * @throws Exception\ExceptionInterface
     */
    public function capability(): array
    {
        $response = $this->requestAndResponse('CAPABILITY');

        if (! is_array($response)) {
            return [];
        }

        $capabilities = [];
        foreach ($response as $line) {
            foreach ($line as $capability) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * Examine and select have the same response. The common code for both
     * is in this method
     *
     * @param  string $command can be 'EXAMINE' or 'SELECT' and this is used as command
     * @param  string $box which folder to change to or examine
     * @return array<string, mixed>|false false if error, array with returned information
     *                    otherwise (flags, exists, recent, uidvalidity)
     * @throws Exception\ExceptionInterface
     */
    public function examineOrSelect(string $command = 'EXAMINE', string $box = 'INBOX'): array|false
    {
        $command = strtoupper($command);
        if (! in_array($command, ['EXAMINE', 'SELECT'], strict: true)) {
            throw new Exception\InvalidArgumentException('The command must be EXAMINE or SELECT');
        }

        $tag = null;
        $this->sendRequest($command, [$this->mailbox($box)], $tag);

        $result = [];
        $tokens = [];
        while (! $this->readLine($tokens, $tag)) {
            if ('FLAGS' === ($tokens[0] ?? null)) {
                array_shift($tokens);
                $result['flags'] = $tokens;
                continue;
            }

            $name = $tokens[1] ?? null;
            if ('[UIDVALIDITY' === $name) {
                $result['uidvalidity'] = (int) ($tokens[2] ?? 0);
                continue;
            }

            if ('EXISTS' === $name || 'RECENT' === $name) {
                $result[strtolower($name)] = $tokens[0] ?? null;
            }
        }

        if ('OK' !== ($tokens[0] ?? null)) {
            return false;
        }

        return $result;
    }

    /**
     * change folder
     *
     * @param  string $box change to this folder
     * @return array<string, mixed>|false see examineOrselect()
     * @throws Exception\ExceptionInterface
     */
    public function select(string $box = 'INBOX'): array|false
    {
        return $this->examineOrSelect('SELECT', $box);
    }

    /**
     * examine folder
     *
     * @param  string $box examine this folder
     * @return array<string, mixed>|false see examineOrselect()
     * @throws Exception\ExceptionInterface
     */
    public function examine(string $box = 'INBOX'): array|false
    {
        return $this->examineOrSelect('EXAMINE', $box);
    }

    /**
     * fetch one or more items of one or more messages
     *
     * @param  string|list<string> $items items to fetch from message(s) as string (if only one item)
     *                             or array of strings
     * @param  int|string|list<int|string> $from message for items or start message if $to !== null,
     *                                           or a list of message numbers and ranges
     * @param  int|float|null $to if null only one message ($from) is fetched, else it's the
     *                            last message, INF means last message available
     * @param  bool $uid set to true if passing a unique id
     * @throws Exception\RuntimeException
     * @return mixed if only one item of one message is fetched it's returned as string
     *               if items of one message are fetched it's returned as (name => value)
     *               if one items of messages are fetched it's returned as (msgno => value)
     *               if items of messages are fetched it's returned as (msgno => (name => value))
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature, kept for compatibility.
     */
    public function fetch(
        string|array $items,
        int|string|array $from,
        int|float|null $to = null,
        bool $uid = false,
    ): mixed {
        $set      = self::sequenceSet($from, $to);
        $items    = (array) $items;
        $single   = null === $to && ! is_array($from);
        $itemList = $this->escapeList($items);

        $tag = null;
        $this->sendRequest(($uid ? 'UID ' : '') . 'FETCH', [$set, $itemList], $tag);

        $result = [];
        $tokens = [];
        while (! $this->readLine($tokens, $tag)) {
            if ('FETCH' !== ($tokens[1] ?? null) || ! is_array($tokens[2] ?? null)) {
                continue;
            }

            $values = self::itemMap($tokens[2]);
            if ($single && ($uid ? $values['UID'] ?? null : $tokens[0]) !== (string) $from) {
                continue;
            }

            $data = 1 === count($items) ? $values[$items[0]] ?? null : $values;
            if ($single) {
                $this->skipToTag($tag);

                return $data;
            }

            $result[$tokens[0]] = $data;
        }

        if ($single) {
            throw new Exception\RuntimeException('the single id was not found in response');
        }

        return $result;
    }

    /**
     * get mailbox list
     *
     * this method can't be named after the IMAP command 'LIST', as list is a reserved keyword
     *
     * @param  string $reference mailbox reference for list
     * @param  string $mailbox   mailbox name match with wildcards
     * @return array<string, array{delim: mixed, flags: mixed}> mailboxes that matched $mailbox as array(globalName => array('delim' => .., 'flags' => ..))
     * @throws Exception\ExceptionInterface
     */
    public function listMailbox(string $reference = '', string $mailbox = '*'): array
    {
        $result = [];
        $list   = $this->requestAndResponse('LIST', [$this->mailbox($reference), $this->mailbox($mailbox)]);
        if (! is_array($list)) {
            return $result;
        }

        foreach ($list as $item) {
            if (
                4 !== count($item)
                || 'LIST' !== ($item[0] ?? null)
                || ! is_string($item[3] ?? null)
            ) {
                continue;
            }

            $name          = $this->utf8Mailboxes ? $item[3] : MailboxName::decode($item[3]);
            $result[$name] = ['delim' => $item[2], 'flags' => $item[1]];
        }

        return $result;
    }

    /**
     * The server's namespaces (RFC 2342, part of IMAP4rev2), with their prefixes as UTF-8;
     * null when the server offers neither NAMESPACE nor IMAP4rev2.
     *
     * @throws Exception\RuntimeException When the server refuses or its response is malformed.
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    public function namespace(): ?Namespaces
    {
        if (! $this->imap4Rev2 && ! $this->hasCapability('NAMESPACE') && ! $this->hasCapability('IMAP4rev2')) {
            return null;
        }

        $response = $this->requestAndResponse('NAMESPACE');
        if (null === $response || false === $response) {
            throw new Exception\RuntimeException('The server refused NAMESPACE');
        }

        foreach (is_array($response) ? $response : [] as $tokens) {
            if ('NAMESPACE' === strtoupper(is_string($tokens[0] ?? null) ? $tokens[0] : '')) {
                return new Namespaces(
                    personal: $this->namespaceEntries($tokens[1] ?? null),
                    otherUsers: $this->namespaceEntries($tokens[2] ?? null),
                    shared: $this->namespaceEntries($tokens[3] ?? null),
                );
            }
        }

        throw new Exception\RuntimeException('The server sent no NAMESPACE response');
    }

    /**
     * The namespaces of one kind: NIL for none, or a list of namespaces.
     *
     * @return list<NamespaceEntry>
     * @throws Exception\RuntimeException When the namespaces are malformed.
     */
    private function namespaceEntries(mixed $namespaces): array
    {
        if (is_string($namespaces) && 'NIL' === strtoupper($namespaces)) {
            return [];
        }

        if (! is_array($namespaces) || [] === $namespaces) {
            throw new Exception\RuntimeException('The server sent a malformed NAMESPACE response');
        }

        return array_values(array_map($this->namespaceEntry(...), $namespaces));
    }

    /**
     * One namespace: its prefix and delimiter, or NIL for none, then any extension data, which is ignored.
     *
     * @throws Exception\RuntimeException When the namespace is malformed.
     */
    private function namespaceEntry(mixed $entry): NamespaceEntry
    {
        $prefix    = is_array($entry) ? $entry[0] ?? null : null;
        $delimiter = is_array($entry) ? $entry[1] ?? null : null;
        if (
            ! is_string($prefix)
            || ! is_string($delimiter)
            || (
                1 !== strlen($delimiter)
                && 'NIL' !== strtoupper($delimiter)
            )
        ) {
            throw new Exception\RuntimeException('The server sent a malformed NAMESPACE response');
        }

        return new NamespaceEntry(
            prefix: $this->utf8Mailboxes ? $prefix : MailboxName::decode($prefix),
            delimiter: 1 === strlen($delimiter) ? $delimiter : null,
        );
    }

    /**
     * The status of a mailbox without selecting it, such as its MESSAGES and UNSEEN counts, or
     * its SIZE in octets when the server offers STATUS=SIZE (RFC 8438) or has IMAP4rev2 enabled.
     *
     * @param list<string> $items MESSAGES, RECENT, UIDNEXT, UIDVALIDITY, UNSEEN, DELETED, SIZE or HIGHESTMODSEQ.
     * @return array<string, int>|false The values by item name in upper case, or false when the server refuses.
     * @throws Exception\InvalidArgumentException When there is no item, an item is not one of those,
     *     or the name is not UTF-8 or contains NUL.
     * @throws Exception\RuntimeException When a value is not a number.
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    public function status(string $folder, array $items): array|false
    {
        if ([] === $items) {
            throw new Exception\InvalidArgumentException('STATUS needs at least one item');
        }

        $names = [];
        foreach ($items as $item) {
            if (1 !== preg_match(self::STATUS_ITEM, $item)) {
                throw new Exception\InvalidArgumentException("\"{$item}\" is not an IMAP status item");
            }

            $names[] = strtoupper($item);
        }

        $response = $this->requestAndResponse('STATUS', [$this->mailbox($folder), $this->escapeList($names)]);
        if (null === $response || false === $response) {
            return false;
        }

        foreach (is_array($response) ? $response : [] as $tokens) {
            $values = $tokens[2] ?? null;
            if ('STATUS' === strtoupper(is_string($tokens[0] ?? null) ? $tokens[0] : '') && is_array($values)) {
                return self::statusValues($values);
            }
        }

        return [];
    }

    /**
     * @param array<mixed> $values
     * @return array<string, int>
     * @throws Exception\RuntimeException When a value is not a number.
     */
    private static function statusValues(array $values): array
    {
        $status = [];
        foreach (self::itemMap($values) as $name => $value) {
            if (! is_string($value) || 1 !== preg_match('/\A\d{1,18}\z/', $value)) {
                throw new Exception\RuntimeException('The server sent a malformed STATUS response');
            }

            $status[strtoupper($name)] = (int) $value;
        }

        return $status;
    }

    /**
     * set flags
     *
     * @param  array<mixed> $flags flags to set, add or remove - see $mode
     * @param  int|string $from message for items or start message if $to !== null
     * @param  int|float|null $to if null only one message ($from) is fetched, else it's the
     *                            last message, INF means last message available
     * @param  string|null $mode '+' to add flags, '-' to remove flags, everything else sets the flags as given
     * @param  bool $silent if false the return values are the new flags for the wanted messages
     * @return array<mixed>|bool if $silent is false, the new flags by message number, empty when the server
     *     reports none (Dovecot sends none for flags that did not change), or false when refused;
     *     else true or false depending on success
     * @throws Exception\ExceptionInterface
     *
     * @mago-expect lint:no-boolean-flag-parameter The laminas-mail signature, kept for compatibility.
     */
    public function store(
        array $flags,
        int|string $from,
        int|float|null $to = null,
        ?string $mode = null,
        bool $silent = true,
    ): array|bool {
        $item = 'FLAGS';
        if ('+' === $mode || '-' === $mode) {
            $item = $mode . $item;
        }

        if ($silent) {
            $item .= '.SILENT';
        }

        $set    = self::sequenceSet($from, $to);
        $result = $this->requestAndResponse('STORE', [$set, $item, self::flagList($flags)], $silent);

        if ($silent) {
            return $this->succeeded($result);
        }

        if (! is_array($result)) {
            return $this->succeeded($result) ? [] : false;
        }

        $flagsByMessage = [];
        foreach ($result as $token) {
            $values = $token[2] ?? null;
            if ('FETCH' !== ($token[1] ?? null) || ! is_array($values) || 'FLAGS' !== ($values[0] ?? null)) {
                continue;
            }

            $flagsByMessage[$token[0] ?? ''] = $values[1] ?? [];
        }

        return $flagsByMessage;
    }

    /**
     * append a new message to given folder
     *
     * @param string $folder  name of target folder
     * @param string $message full message content
     * @param array<mixed>|null $flags flags for new message
     * @param string|null $date date for new message
     * @return bool success
     * @throws Exception\ExceptionInterface
     */
    public function append(string $folder, string $message, ?array $flags = null, ?string $date = null): bool
    {
        try {
            $this->appendReturningUids($folder, $message, $flags, $date);
        } catch (Exception\CommandRefusedException) {
            return false;
        }

        return true;
    }

    /**
     * Append a message as append() does, and read the UID the server gave it (RFC 4315, UIDPLUS).
     *
     * @param array<mixed>|null $flags
     * @return UidMapping|null The UIDs the server reported in an APPENDUID response code, or null when it sent none.
     * @throws Exception\CommandRefusedException When the server refuses the message.
     * @throws Exception\ExceptionInterface When a flag is not valid or the server cannot be asked.
     */
    public function appendReturningUids(
        string $folder,
        string $message,
        ?array $flags = null,
        ?string $date = null,
    ): ?UidMapping {
        $tokens   = [];
        $tokens[] = $this->mailbox($folder);
        if (null !== $flags) {
            $tokens[] = self::flagList($flags);
        }

        if (null !== $date) {
            $tokens[] = $this->escapeOne($date);
        }

        $tokens[] = $this->escapeOne($message);

        return $this->returningUids('APPEND', $tokens);
    }

    /**
     * copy message set from current folder to other folder
     *
     * @param string $folder destination folder
     * @param int|string $from first message, or a sequence set
     * @param int|float|null $to if null only one message ($from) is fetched, else it's the
     *                           last message, INF means last message available
     * @return bool success
     * @throws Exception\ExceptionInterface
     */
    public function copy(string $folder, int|string $from, int|float|null $to = null): bool
    {
        try {
            $this->copyReturningUids($folder, $from, $to);
        } catch (Exception\CommandRefusedException) {
            return false;
        }

        return true;
    }

    /**
     * Copy messages as copy() does, and read the UIDs the server gave the copies (RFC 4315, UIDPLUS).
     *
     * @param int|float|null $to The last message, INF for the last one there is, or null for $from alone.
     * @return UidMapping|null The source and destination UIDs the server reported in a COPYUID
     *     response code, or null when it sent none.
     * @throws Exception\CommandRefusedException When the server refuses the copy.
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    public function copyReturningUids(string $folder, int|string $from, int|float|null $to = null): ?UidMapping
    {
        return $this->returningUids('COPY', [self::sequenceSet($from, $to), $this->mailbox($folder)]);
    }

    /**
     * Send APPEND, COPY or MOVE, and read the UIDs of the first APPENDUID or COPYUID response
     * code: in the tagged reply, or in an untagged OK, where MOVE sends it before the EXPUNGE
     * responses (RFC 6851, section 4.3).
     *
     * @param array<mixed> $tokens
     * @throws Exception\CommandRefusedException When the server refuses the command.
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    private function returningUids(string $command, array $tokens): ?UidMapping
    {
        $tag = null;
        $this->sendRequest($command, $tokens, $tag);
        $responses = [];
        do {
            $response    = [];
            $tagged      = $this->readLine($response, $tag);
            $responses[] = $response;
        } while (! $tagged);

        if ('OK' !== ($response[0] ?? null)) {
            throw new Exception\CommandRefusedException("The server refused {$command}");
        }

        foreach ($responses as $response) {
            $uids = 'OK' === ($response[0] ?? null) ? UidMapping::fromStatusResponse($response) : null;
            if (null !== $uids) {
                return $uids;
            }
        }

        return null;
    }

    /**
     * Leave the selected folder without expunging the messages flagged \Deleted (RFC 3691,
     * part of IMAP4rev2), when the server offers UNSELECT or IMAP4rev2 is enabled.
     *
     * CLOSE would expunge them; SELECT, EXAMINE and LOGOUT leave a folder without expunging
     * (RFC 3501, section 6.4.2), so this is only needed to have no folder selected.
     *
     * @throws Exception\ExceptionInterface When the server does not offer UNSELECT or cannot be asked.
     */
    public function unselect(): bool
    {
        if (! $this->imap4Rev2 && ! $this->hasCapability('UNSELECT')) {
            throw new Exception\RuntimeException('The server does not offer UNSELECT');
        }

        return $this->succeeded($this->requestAndResponse('UNSELECT'));
    }

    /**
     * Move messages to another folder (RFC 6851), when the server offers MOVE.
     *
     * @param int|float|null $to The last message, INF for the last one there is, or null for $from alone.
     * @throws Exception\ExceptionInterface When the server does not offer MOVE or cannot be asked.
     */
    public function move(string $folder, int|string $from, int|float|null $to = null): bool
    {
        try {
            $this->moveReturningUids($folder, $from, $to);
        } catch (Exception\CommandRefusedException) {
            return false;
        }

        return true;
    }

    /**
     * Move messages as move() does, and read the UIDs the server gave them in the destination
     * (RFC 4315, UIDPLUS), from the COPYUID response code MOVE sends before the EXPUNGE responses.
     *
     * @param int|float|null $to The last message, INF for the last one there is, or null for $from alone.
     * @return UidMapping|null The source and destination UIDs the server reported, or null when it sent none.
     * @throws Exception\CommandRefusedException When the server refuses the move.
     * @throws Exception\ExceptionInterface When the server does not offer MOVE or cannot be asked.
     */
    public function moveReturningUids(string $folder, int|string $from, int|float|null $to = null): ?UidMapping
    {
        if (! $this->hasCapability('MOVE')) {
            throw new Exception\RuntimeException('The server does not offer MOVE');
        }

        return $this->returningUids('MOVE', [self::sequenceSet($from, $to), $this->mailbox($folder)]);
    }

    /**
     * create a new folder (and parent folders if needed)
     *
     * @param string $folder folder name
     * @return bool success
     * @throws Exception\ExceptionInterface
     */
    public function create(string $folder): bool
    {
        return $this->succeeded($this->requestAndResponse('CREATE', [$this->mailbox($folder)]));
    }

    /**
     * rename an existing folder
     *
     * @param string $old old name
     * @param string $new new name
     * @return bool success
     * @throws Exception\ExceptionInterface
     */
    public function rename(string $old, string $new): bool
    {
        return $this->succeeded($this->requestAndResponse('RENAME', [$this->mailbox($old), $this->mailbox($new)]));
    }

    /**
     * remove a folder
     *
     * @param string $folder folder name
     * @return bool success
     * @throws Exception\ExceptionInterface
     */
    public function delete(string $folder): bool
    {
        return $this->succeeded($this->requestAndResponse('DELETE', [$this->mailbox($folder)]));
    }

    /**
     * subscribe to a folder
     *
     * @param string $folder folder name
     * @return bool success
     * @throws Exception\ExceptionInterface
     */
    public function subscribe(string $folder): bool
    {
        return $this->succeeded($this->requestAndResponse('SUBSCRIBE', [$this->mailbox($folder)]));
    }

    /**
     * permanently remove messages
     *
     * @return array<mixed>|bool the untagged responses, true if there were none, or false on failure
     * @throws Exception\ExceptionInterface
     */
    public function expunge(): array|bool
    {
        return $this->requestAndResponse('EXPUNGE') ?? false;
    }

    /**
     * send noop
     *
     * @return array<mixed>|bool the untagged responses, true if there were none, or false on failure
     * @throws Exception\ExceptionInterface
     */
    public function noop(): array|bool
    {
        return $this->requestAndResponse('NOOP') ?? false;
    }

    /**
     * Listen for changes to the selected mailbox with IDLE (RFC 2177), for at most $timeout seconds.
     *
     * Each untagged response the server sends is yielded as its tokens, such as
     * ['3', 'EXISTS'] or ['4', 'FETCH', ['FLAGS', ['\\Seen']]]. IDLE is sent when the
     * generator is first iterated. DONE is sent once the timeout has passed, and the
     * responses the server sends before its tagged reply are yielded too. When the
     * caller stops iterating early, DONE is sent and the reply read as the generator
     * is destroyed, or else before the next command, so the connection stays usable.
     *
     * RFC 2177 asks clients to end IDLE at least every 29 minutes, the default
     * timeout; to keep listening, call idle() again in a loop.
     *
     * @param int $timeout Seconds to listen for, counted from the first iteration.
     * @param ClockInterface $clock The time the timeout is counted by.
     * @return Generator<int, array<mixed>, mixed, void>
     * @throws Exception\InvalidArgumentException When the timeout is under one second.
     * @throws Exception\RuntimeException When the server offers neither IDLE nor IMAP4rev2. Iterating
     *     throws it when the server refuses IDLE, ends it with an error, or says BYE.
     * @throws Exception\ExceptionInterface When the capabilities cannot be read, or, while
     *     iterating, when the connection fails.
     */
    public function idle(int $timeout = 1740, ClockInterface $clock = new SystemClock()): Generator
    {
        if ($timeout < 1) {
            throw new Exception\InvalidArgumentException('The IDLE timeout must be at least one second');
        }

        if (! $this->imap4Rev2 && ! $this->hasCapability('IDLE')) {
            throw new Exception\RuntimeException('The server does not offer IDLE');
        }

        return $this->listen($timeout, $clock);
    }

    /**
     * Send IDLE, then yield untagged responses until the timeout passes or the server ends IDLE.
     *
     * Each pass reads one line, or waits until the deadline and sends DONE, so the loop
     * ends with the tagged reply. Another command ends the IDLE through nextTag(), and
     * this generator then yields nothing more.
     *
     * @return Generator<int, array<mixed>, mixed, void>
     * @throws Exception\ExceptionInterface
     */
    private function listen(int $timeout, ClockInterface $clock): Generator
    {
        $tag = $this->nextTag();
        $this->connection->write(CommandLine::terminate("{$tag} IDLE"));
        $early    = $this->awaitIdle($tag);
        $deadline = $clock->now()->getTimestamp() + $timeout;

        $this->idle = [$tag, false];
        try {
            foreach ($early as $tokens) {
                yield $tokens;
            }

            while (null !== $this->idle && $tag === $this->idle[0]) {
                if (! $this->idle[1]) {
                    $this->awaitNews($tag, $deadline, $clock);
                }

                $this->responseBytes = 0;
                $line                = $this->nextLine();
                if (str_starts_with($line, "{$tag} ")) {
                    $this->idle = null;
                    $this->idleEnded($tag, $line);
                }

                if (str_starts_with($line, '* ')) {
                    yield $this->untagged(substr($line, offset: 2));
                }
            }
        } finally {
            if ($tag === ($this->idle[0] ?? null)) {
                $this->endIdle();
            }
        }
    }

    /**
     * Wait for the "+" that starts IDLE, returning the untagged responses that came before it.
     *
     * @return list<array<mixed>>
     * @throws Exception\RuntimeException When the server refuses IDLE or says BYE.
     */
    private function awaitIdle(string $tag): array
    {
        $early = [];
        $line  = $this->nextLine();
        while (! str_starts_with($line, '+')) {
            if (! str_starts_with($line, '* ')) {
                $text = $this->taggedReply($tag, $line)[1];

                throw new Exception\RuntimeException('' === $text ? 'The server refused IDLE' : $text);
            }

            $early[] = $this->untagged(substr($line, offset: 2));
            $line    = $this->nextLine();
        }

        return $early;
    }

    /**
     * Wait until the server sends something or the deadline passes, and send DONE if it passes:
     * the server's next lines are then the last responses and the tagged reply.
     *
     * @throws Exception\RuntimeException When the connection fails.
     */
    private function awaitNews(string $tag, int $deadline, ClockInterface $clock): void
    {
        $remaining = $deadline - $clock->now()->getTimestamp();
        if ($remaining > 0 && $this->connection->waitUntilReadable($remaining)) {
            return;
        }

        $this->connection->write(CommandLine::terminate('DONE'));
        $this->idle = [$tag, true];
    }

    /**
     * The tokens of an untagged response, after "* ".
     *
     * @return array<mixed>
     * @throws Exception\RuntimeException When it is BYE: the connection is closed, as the server closes it.
     */
    private function untagged(string $line): array
    {
        $tokens = $this->decodeLine($line);
        if ('BYE' !== strtoupper(is_string($tokens[0] ?? null) ? $tokens[0] : '')) {
            return $tokens;
        }

        $this->idle = null;
        $this->connection->close();
        $text = SafeText::display(explode(' ', $line, limit: 2)[1] ?? '');

        throw new Exception\RuntimeException(
            '' === $text ? 'The server closed the connection' : "The server closed the connection: {$text}",
        );
    }

    /**
     * End the IDLE in progress, if any: send DONE unless it was sent, and read the tagged reply.
     *
     * @throws Exception\RuntimeException When the connection fails.
     */
    private function endIdle(): void
    {
        if (null === $this->idle) {
            return;
        }

        [$tag, $done] = $this->idle;
        $this->idle = null;
        if (! $done) {
            $this->connection->write(CommandLine::terminate('DONE'));
        }

        $this->responseBytes = 0;
        $this->taggedReply($tag, $this->nextLine());
    }

    /**
     * @throws Exception\RuntimeException When the tagged reply to IDLE is not OK.
     */
    private function idleEnded(string $tag, string $line): void
    {
        [$status, $text] = $this->taggedReply($tag, $line);
        if ('OK' !== $status) {
            throw new Exception\RuntimeException('' === $text ? 'The server ended IDLE with an error' : $text);
        }
    }

    /**
     * The numbers of the messages matching a search, in the order of the sort keys
     * (RFC 5256), such as ["REVERSE DATE"] or ["FROM", "SUBJECT"], when the server offers SORT.
     *
     * The search parameters are sent as for search(): pass any string from outside
     * through escapeString().
     *
     * @param list<string> $keys Sort keys: ARRIVAL, CC, DATE, FROM, SIZE, SUBJECT, TO, DISPLAYFROM
     *     or DISPLAYTO (RFC 5957), each optionally after REVERSE.
     * @param array<mixed> $criteria Search criteria, as for search().
     * @param bool $uid Return UIDs rather than message numbers.
     * @return list<string>|false message numbers or UIDs in order, or false when the server refuses
     * @throws Exception\InvalidArgumentException When there is no key, or a key is not one of those.
     * @throws Exception\ExceptionInterface When the server does not offer SORT or cannot be asked.
     *
     * @mago-expect lint:no-boolean-flag-parameter Matches fetch(), which picks UIDs the same way.
     */
    public function sort(array $keys, array $criteria = ['ALL'], bool $uid = false): array|false
    {
        if ([] === $keys) {
            throw new Exception\InvalidArgumentException('SORT needs at least one sort key');
        }

        $words = [];
        foreach ($keys as $key) {
            if (1 !== preg_match(self::SORT_KEY, $key)) {
                throw new Exception\InvalidArgumentException("\"{$key}\" is not an IMAP sort key");
            }

            foreach (explode(' ', strtoupper($key)) as $word) {
                $words[] = $word;
            }
        }

        if (! $this->hasCapability('SORT')) {
            throw new Exception\RuntimeException('The server does not offer SORT');
        }

        $response = $this->requestAndResponse(
            ($uid ? 'UID ' : '') . 'SORT',
            [$this->escapeList($words), 'UTF-8', ...$criteria],
        );
        if (null === $response || false === $response) {
            return false;
        }

        foreach (is_array($response) ? $response : [] as $ids) {
            if ('SORT' === strtoupper(is_string($ids[0] ?? null) ? $ids[0] : '')) {
                return array_values(array_map(strval(...), array_slice($ids, offset: 1)));
            }
        }

        return [];
    }

    /**
     * How many messages match a search: counted by the server with ESEARCH
     * (RFC 4731), which IMAP4rev2 includes, rather than by sending every matching
     * number.
     *
     * @param array<mixed> $criteria Search criteria, as for search().
     * @throws Exception\CommandRefusedException When the server refuses the search.
     * @throws Exception\ExceptionInterface When the server cannot be asked.
     */
    public function searchCount(array $criteria): int
    {
        if (! $this->imap4Rev2 && ! $this->hasCapability('ESEARCH')) {
            $ids = $this->search($criteria);
            if (false === $ids) {
                throw new Exception\CommandRefusedException('The server refused the search');
            }

            return count($ids);
        }

        $response = $this->requestAndResponse('SEARCH', ['RETURN', '(COUNT)', ...$criteria]);
        if (null === $response || false === $response) {
            throw new Exception\CommandRefusedException('The server refused the search');
        }

        foreach (is_array($response) ? $response : [] as $tokens) {
            $count = self::esearchCount($tokens);
            if (null !== $count) {
                return $count;
            }
        }

        return 0;
    }

    /**
     * The COUNT of an ESEARCH response, or null for any other response.
     *
     * @param array<mixed> $tokens
     * @throws Exception\RuntimeException When the count is not a number.
     */
    private static function esearchCount(array $tokens): ?int
    {
        $tokens = array_values($tokens);
        if ('ESEARCH' !== strtoupper(is_string($tokens[0] ?? null) ? $tokens[0] : '')) {
            return null;
        }

        foreach ($tokens as $index => $token) {
            if (! is_string($token) || 'COUNT' !== strtoupper($token)) {
                continue;
            }

            $count = $tokens[$index + 1] ?? null;
            if (! is_string($count) || 1 !== preg_match('/\A\d{1,10}\z/', $count)) {
                throw new Exception\RuntimeException('The server sent a malformed search count');
            }

            return (int) $count;
        }

        return 0;
    }

    /**
     * do a search request
     *
     * The criteria are sent as they are, apart from the checks every
     * request gets: pass any string from outside through escapeString().
     *
     * An IMAP4rev2 server answers with ESEARCH (RFC 4731, RFC 9051) rather than
     * SEARCH; its ALL sequence set is expanded to the same list of ids, up to
     * MAX_SEARCH_RESULTS, so a server cannot make the client build an endless list.
     *
     * @param array<mixed> $criteria The search keys, such as ['UNSEEN'] or ['FROM', $imap->escapeString($from)].
     * @return array<mixed>|false message ids, or false on failure
     * @throws Exception\ExceptionInterface When the server cannot be asked, or an ESEARCH result is malformed or too long.
     */
    public function search(array $criteria): array|false
    {
        $response = $this->requestAndResponse('SEARCH', $criteria);
        if (null === $response || false === $response) {
            return false;
        }

        foreach (is_array($response) ? $response : [] as $ids) {
            $kind = strtoupper(is_string($ids[0] ?? null) ? $ids[0] : '');
            if ('SEARCH' === $kind) {
                array_shift($ids);

                return $ids;
            }

            if ('ESEARCH' === $kind) {
                return self::esearchIds($ids);
            }
        }

        return [];
    }

    /**
     * The ids of an ESEARCH response: its ALL sequence set, expanded, or none.
     *
     * @param array<mixed> $tokens
     * @return list<string>
     * @throws Exception\RuntimeException When the set is malformed or holds more than MAX_SEARCH_RESULTS ids.
     */
    private static function esearchIds(array $tokens): array
    {
        $tokens = array_values($tokens);
        foreach ($tokens as $index => $token) {
            if (! is_string($token) || 'ALL' !== strtoupper($token)) {
                continue;
            }

            $set = $tokens[$index + 1] ?? null;

            return self::expandSequenceSet(is_string($set) ? $set : '');
        }

        return [];
    }

    /**
     * @return list<string>
     * @throws Exception\RuntimeException When the set is malformed or holds more than MAX_SEARCH_RESULTS ids.
     */
    private static function expandSequenceSet(string $set): array
    {
        if (! self::isSetOf(self::SEARCH_RANGE, $set)) {
            throw new Exception\RuntimeException('The server sent a malformed search result');
        }

        $ids = [];
        foreach (explode(',', $set) as $range) {
            $bounds = array_map(intval(...), explode(':', $range));
            $low    = min($bounds);
            $high   = max($bounds);
            if ((count($ids) + $high - $low + 1) > self::MAX_SEARCH_RESULTS) {
                throw new Exception\RuntimeException(sprintf(
                    'The server sent more than %d search results',
                    self::MAX_SEARCH_RESULTS,
                ));
            }

            foreach (range($low, $high) as $id) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Upgrade the connection with STARTTLS, which the server must advertise.
     *
     * @throws Exception\RuntimeException When the server does not offer STARTTLS, refuses it, or the handshake fails.
     * @throws Exception\ExceptionInterface
     */
    private function startTls(): void
    {
        if (! in_array('STARTTLS', $this->upperCaseCapabilities(), strict: true)) {
            throw new Exception\RuntimeException(
                'cannot enable TLS: the server does not offer STARTTLS; refusing to continue in plain text',
            );
        }

        if (true !== $this->requestAndResponse('STARTTLS')) {
            throw new Exception\RuntimeException('cannot enable TLS: the server refused STARTTLS');
        }

        $this->connection->enableTls();
        $this->capabilities = null;
    }

    /**
     * Read the capabilities from the server, in upper case, and remember them until STARTTLS.
     *
     * @return list<string>
     * @throws Exception\ExceptionInterface
     */
    private function upperCaseCapabilities(): array
    {
        return $this->capabilities = array_values(array_map(
            static fn(mixed $capability): string => is_string($capability) ? strtoupper($capability) : '',
            $this->capability(),
        ));
    }

    /**
     * @throws Exception\InvalidArgumentException When the string contains NUL.
     * @return string|array{string, string}
     */
    private function escapeOne(#[SensitiveParameter] string $string): string|array
    {
        if (str_contains($string, "\0")) {
            throw new Exception\InvalidArgumentException('IMAP strings cannot contain NUL');
        }

        if (1 === preg_match('/[\r\n\x80-\xFF]/', $string)) {
            return ['{' . strlen($string) . '}', $string];
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $string) . '"';
    }

    /**
     * The content of a literal token from escapeString(), checked against its declared size.
     *
     * @param array<mixed> $token
     * @throws Exception\InvalidArgumentException When the token is not a literal of the size it declares.
     */
    private static function literal(#[SensitiveParameter] array $token): string
    {
        $size    = $token[0] ?? null;
        $content = $token[1] ?? null;
        if (! is_string($content) || $size !== '{' . strlen($content) . '}' || str_contains($content, "\0")) {
            throw new Exception\InvalidArgumentException(
                'A literal must be array("{size}", content) with the exact size and no NUL; use escapeString()',
            );
        }

        return $content;
    }

    /**
     * A sequence set for one message, a range, or a list of numbers and ranges.
     *
     * @param int|string|array<mixed> $from
     * @throws Exception\InvalidArgumentException When the result is not an RFC 3501 sequence set.
     */
    private static function sequenceSet(int|string|array $from, int|float|null $to): string
    {
        $set = is_array($from) ? self::numberList($from) : (string) $from;

        if (null !== $to) {
            if (is_float($to) && INF !== $to) {
                throw new Exception\InvalidArgumentException('The last message must be an integer or INF');
            }

            $set .= ':' . (INF === $to ? '*' : $to);
        }

        if (! self::isSetOf(self::SEQUENCE_RANGE, $set)) {
            throw new Exception\InvalidArgumentException(
                'Not a valid message sequence set: numbers from 1, "*", ":" and ","',
            );
        }

        return $set;
    }

    /**
     * Whether every comma-separated part of the set matches $range.
     *
     * Each part is matched on its own: one pattern over the whole set runs out of PCRE's
     * stack at a few thousand parts, and a valid set would then be refused.
     */
    private static function isSetOf(string $range, string $set): bool
    {
        foreach (explode(',', $set) as $part) {
            if (1 !== preg_match($range, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $numbers
     * @throws Exception\InvalidArgumentException When a number is neither an integer nor a string.
     */
    private static function numberList(array $numbers): string
    {
        $parts = [];
        foreach ($numbers as $number) {
            if (! is_int($number) && ! is_string($number)) {
                throw new Exception\InvalidArgumentException('Message numbers must be integers or ranges');
            }

            $parts[] = $number;
        }

        return implode(',', $parts);
    }

    /**
     * @param array<mixed> $flags
     * @throws Exception\InvalidArgumentException When a flag is not an atom, optionally after a backslash.
     */
    private static function flagList(array $flags): string
    {
        $atoms = [];
        foreach ($flags as $flag) {
            if (! is_string($flag) || 1 !== preg_match(self::FLAG, $flag)) {
                throw new Exception\InvalidArgumentException(
                    'Flags must be atoms such as \Seen or $Label, without spaces, quotes, brackets or control characters',
                );
            }

            $atoms[] = $flag;
        }

        return '(' . implode(' ', $atoms) . ')';
    }

    /**
     * The items of a FETCH response by name.
     *
     * @param array<mixed> $values
     * @return array<string, mixed>
     *
     * @mago-expect analysis:possibly-undefined-int-array-index array_chunk() pairs always have a first item, and the union supplies a missing second.
     */
    private static function itemMap(array $values): array
    {
        $map = [];
        foreach (array_chunk($values, length: 2) as $pair) {
            [$name, $value] = $pair + [1 => null];
            if (! is_string($name)) {
                continue;
            }

            $map[$name] = $value;
        }

        return $map;
    }

    /**
     * Read the rest of a response up to its tagged line.
     *
     * @throws Exception\RuntimeException
     */
    private function skipToTag(string $tag): void
    {
        do {
            $tokens = [];
            $done   = $this->readLine($tokens, $tag);
        } while (! $done);
    }

    /**
     * @param array<mixed>|bool|null $response
     */
    private function succeeded(array|bool|null $response): bool
    {
        return null !== $response && false !== $response;
    }

    /**
     * @throws Exception\RuntimeException
     */
    private function readLiteral(string $digits): string
    {
        $size = (int) $digits;
        if (($this->responseBytes + $size) > $this->limits->maxResponseSize) {
            throw new Exception\RuntimeException(
                "The server announced a literal of {$size} bytes, over the response limit of {$this->limits->maxResponseSize} bytes",
            );
        }

        $literal = $this->connection->read($size);
        $this->countResponseBytes($size);

        return $literal;
    }

    /**
     * @throws Exception\RuntimeException
     */
    private function countResponseBytes(int $bytes): void
    {
        $this->responseBytes += $bytes;
        if ($this->responseBytes > $this->limits->maxResponseSize) {
            throw new Exception\RuntimeException(
                "The server's response exceeds the limit of {$this->limits->maxResponseSize} bytes",
            );
        }
    }
}
