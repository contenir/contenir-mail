<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Closure;
use Contenir\Mail\Protocol\AbstractProtocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\LoggingConnection;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Redaction;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\CramMd5;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\TestAsset\RecordingLogger;
use Contenir\Mail\Tests\Unit\TestAsset\ScramVector;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_intersect;
use function array_values;
use function base64_encode;
use function hash_hmac;
use function md5;

/**
 * Every way IMAP, POP3 and SMTP send credentials, logged through a ConnectionConfig "logger":
 * the log holds the session, but never a password, token, digest or SASL response.
 */
#[CoversClass(LoggingConnection::class)]
#[CoversClass(Redaction::class)]
#[CoversClass(Imap::class)]
#[CoversClass(Pop3::class)]
#[CoversClass(Smtp::class)]
#[CoversClass(AbstractProtocol::class)]
#[Group('unit')]
final class LoggedSecretsTest extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password A made-up password the tests look for in the log.
     */
    private const string PASSWORD = 'hunter2-secret';

    /**
     * A password with 8-bit bytes, which IMAP sends as a literal.
     *
     * @mago-expect lint:no-literal-password A made-up password the tests look for in the log.
     */
    private const string LITERAL_PASSWORD = "h\u{FC}nter2-secret";

    /**
     * @mago-expect lint:no-literal-password A made-up token the tests look for in the log.
     */
    private const string TOKEN = 'ya29.access-token-secret';

    private const string TIMESTAMP = '<1896.697170952@dbc.mtview.ca.us>';

    /**
     * @param Closure(ConnectionConfig): void $session
     * @param list<string> $secrets
     */
    #[Test]
    #[DataProvider('sessionProvider')]
    public function logsTheSessionWithoutItsSecrets(Closure $session, array $secrets): void
    {
        $logger = new RecordingLogger();

        $session(new ConnectionConfig('mail.example.com', security: Security::None, logger: $logger));

        $log = $logger->text();
        foreach ($secrets as $secret) {
            static::assertStringNotContainsString($secret, $log);
        }

        static::assertStringContainsString(AbstractProtocol::REDACTED, $log);
    }

    /**
     * @return array<string, array{Closure(ConnectionConfig): void, list<string>}>
     *
     * @mago-expect lint:halstead One session for each way a credential is sent.
     */
    public static function sessionProvider(): array
    {
        $sasl  = Encoder::encodeXoauth2Sasl('jo@example.com', self::TOKEN);
        $scram = [
            ScramVector::PASSWORD,
            ScramVector::b64(ScramVector::CLIENT_FINAL),
            'dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ=',
        ];

        return [
            'IMAP LOGIN'                         => [
                static fn(ConnectionConfig $config) => self::imap(
                    $config,
                    "IMAP4rev1\r\nTAG1 OK\r\n",
                    static fn(InMemoryConnection $server) => $server->expect(
                        'TAG2 LOGIN "jo" "' . self::PASSWORD . "\"\r\n",
                    )
                        ->reply("TAG2 OK\r\n"),
                )
                    ->login('jo', self::PASSWORD),
                [self::PASSWORD],
            ],
            'IMAP LOGIN with a literal'          => [
                static fn(ConnectionConfig $config) => self::imap(
                    $config,
                    "IMAP4rev1\r\nTAG1 OK\r\n",
                    static fn(InMemoryConnection $server) => $server->expect("TAG2 LOGIN \"jo\" {15}\r\n")
                        ->reply("+ go on\r\n")
                        ->expect(self::LITERAL_PASSWORD . "\r\n")
                        ->reply("TAG2 OK\r\n"),
                )
                    ->login('jo', self::LITERAL_PASSWORD),
                [self::LITERAL_PASSWORD],
            ],
            'IMAP LOGIN with a LITERAL+ literal' => [
                static fn(ConnectionConfig $config) => self::imap(
                    $config,
                    "IMAP4rev1 LITERAL+\r\nTAG1 OK\r\n",
                    static fn(InMemoryConnection $server) => $server->expect(
                        "TAG2 LOGIN \"jo\" {15+}\r\n" . self::LITERAL_PASSWORD . "\r\n",
                    )
                        ->reply("TAG2 OK\r\n"),
                )
                    ->login('jo', self::LITERAL_PASSWORD),
                [self::LITERAL_PASSWORD],
            ],
            'IMAP login in lower case'           => [
                static fn(ConnectionConfig $config) => self::imap(
                    $config,
                    null,
                    static fn(InMemoryConnection $server) => $server->expect("TAG1 login {2}\r\n")
                        ->reply("+ go on\r\n")
                        ->expect("jo {15}\r\n")
                        ->reply("+ go on\r\n")
                        ->expect(self::LITERAL_PASSWORD . "\r\n")
                        ->reply("TAG1 OK\r\n"),
                )
                    ->requestAndResponse('login', [['{2}', 'jo'], ['{15}', self::LITERAL_PASSWORD]]),
                [self::LITERAL_PASSWORD],
            ],
            'IMAP XOAUTH2 with SASL-IR'          => [
                static fn(ConnectionConfig $config) => self::imap(
                    $config,
                    "IMAP4rev1 SASL-IR AUTH=XOAUTH2\r\nTAG1 OK\r\n",
                    static fn(InMemoryConnection $server) => $server->expect("TAG2 AUTHENTICATE XOAUTH2 {$sasl}\r\n")
                        ->reply("TAG2 OK\r\n"),
                )
                    ->authenticate(new Xoauth2('jo@example.com', self::TOKEN)),
                [self::TOKEN, $sasl],
            ],
            'IMAP XOAUTH2, refused'              => [
                static fn(ConnectionConfig $config) => self::refused(static fn() => self::imap(
                    $config,
                    "IMAP4rev1 AUTH=XOAUTH2\r\nTAG1 OK\r\n",
                    static fn(InMemoryConnection $server) => $server->expect("TAG2 AUTHENTICATE XOAUTH2\r\n")
                        ->reply("+ \r\n")
                        ->expect("{$sasl}\r\n")
                        ->reply('+ ' . base64_encode('{"status":"401"}') . "\r\n")
                        ->expect("\r\n")
                        ->reply("TAG2 NO Invalid\r\n"),
                )
                    ->authenticate(new Xoauth2('jo@example.com', self::TOKEN))),
                [self::TOKEN, $sasl],
            ],
            'IMAP SCRAM-SHA-256'                 => [
                static fn(ConnectionConfig $config) => self::imap(
                    $config,
                    "IMAP4rev1 SASL-IR AUTH=SCRAM-SHA-256\r\nTAG1 OK\r\n",
                    static fn(InMemoryConnection $server) => $server->expect(
                        'TAG2 AUTHENTICATE SCRAM-SHA-256 ' . ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n",
                    )
                        ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FIRST) . "\r\n")
                        ->expect(ScramVector::b64(ScramVector::CLIENT_FINAL) . "\r\n")
                        ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FINAL) . "\r\n")
                        ->expect("\r\n")
                        ->reply("TAG2 OK\r\n"),
                )
                    ->authenticate(ScramVector::authenticator()),
                $scram,
            ],
            'POP3 USER and PASS'                 => [
                static fn(ConnectionConfig $config) => self::pop3(
                    $config,
                    '',
                    static fn(InMemoryConnection $server) => $server->expect("USER jo\r\n")
                        ->reply("+OK\r\n")
                        ->expect('PASS ' . self::PASSWORD . "\r\n")
                        ->reply("+OK\r\n"),
                )
                    ->login('jo', self::PASSWORD),
                [self::PASSWORD],
            ],
            'POP3 APOP'                          => [
                static fn(ConnectionConfig $config) => self::pop3(
                    $config,
                    self::TIMESTAMP,
                    static fn(InMemoryConnection $server) => $server->expect(
                        'APOP jo ' . md5(self::TIMESTAMP . self::PASSWORD) . "\r\n",
                    )
                        ->reply("+OK\r\n"),
                )
                    ->login('jo', self::PASSWORD),
                [self::PASSWORD, md5(self::TIMESTAMP . self::PASSWORD)],
            ],
            'POP3 XOAUTH2'                       => [
                static fn(ConnectionConfig $config) => self::pop3(
                    $config,
                    '',
                    static fn(InMemoryConnection $server) => $server->expect("AUTH XOAUTH2\r\n")
                        ->reply("+ \r\n")
                        ->expect("{$sasl}\r\n")
                        ->reply("+OK\r\n"),
                )
                    ->authenticate(new Xoauth2('jo@example.com', self::TOKEN)),
                [self::TOKEN, $sasl],
            ],
            'POP3 SCRAM-SHA-256'                 => [
                static fn(ConnectionConfig $config) => self::pop3(
                    $config,
                    '',
                    static fn(InMemoryConnection $server) => $server->expect("AUTH SCRAM-SHA-256\r\n")
                        ->reply("+ \r\n")
                        ->expect(ScramVector::b64(ScramVector::CLIENT_FIRST) . "\r\n")
                        ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FIRST) . "\r\n")
                        ->expect(ScramVector::b64(ScramVector::CLIENT_FINAL) . "\r\n")
                        ->reply('+ ' . ScramVector::b64(ScramVector::SERVER_FINAL) . "\r\n")
                        ->expect("\r\n")
                        ->reply("+OK\r\n"),
                )
                    ->authenticate(ScramVector::authenticator()),
                $scram,
            ],
            'SMTP AUTH PLAIN'                    => [
                static fn(ConnectionConfig $config) => self::smtp($config, new Plain('jo', self::PASSWORD)),
                [self::PASSWORD, base64_encode("\0jo\0" . self::PASSWORD)],
            ],
            'SMTP AUTH LOGIN'                    => [
                static fn(ConnectionConfig $config) => self::smtp($config, new Login('jo', self::PASSWORD)),
                [self::PASSWORD, base64_encode(self::PASSWORD)],
            ],
            'SMTP AUTH CRAM-MD5'                 => [
                static fn(ConnectionConfig $config) => self::smtp($config, new CramMd5('jo', self::PASSWORD)),
                [
                    self::PASSWORD,
                    base64_encode('jo ' . hash_hmac('md5', SmtpServer::CRAM_MD5_CHALLENGE, self::PASSWORD)),
                ],
            ],
            'SMTP AUTH XOAUTH2'                  => [
                static fn(ConnectionConfig $config) => self::smtp($config, new Xoauth2('jo@example.com', self::TOKEN)),
                [self::TOKEN, $sasl],
            ],
            'SMTP AUTH SCRAM-SHA-256'            => [
                static function (ConnectionConfig $config): void {
                    $server = new SmtpServer();
                    $server->reply(
                        ScramVector::b64(ScramVector::CLIENT_FIRST),
                        '334 ' . ScramVector::b64(ScramVector::SERVER_FIRST),
                    );
                    $server->reply(
                        ScramVector::b64(ScramVector::CLIENT_FINAL),
                        '334 ' . ScramVector::b64(ScramVector::SERVER_FINAL),
                    );
                    self::smtp($config, ScramVector::authenticator(), $server);
                },
                $scram,
            ],
        ];
    }

    /**
     * @param Closure(InMemoryConnection): InMemoryConnection $script What follows CAPABILITY.
     */
    private static function imap(ConnectionConfig $config, ?string $capabilities, Closure $script): Imap
    {
        $server = (new InMemoryConnection())->reply("* OK IMAP4rev1 ready\r\n");
        if (null !== $capabilities) {
            $server->expect("TAG1 CAPABILITY\r\n")->reply("* CAPABILITY {$capabilities}");
        }

        $server = $script($server)->hangUp();
        $imap   = new Imap(connection: $server);
        $imap->connect($config);

        return $imap;
    }

    /**
     * Commands without credentials are logged as they are, so the log can be read.
     */
    #[Test]
    public function logsOtherCommandsAsTheyAre(): void
    {
        $logger = new RecordingLogger();
        $config = new ConnectionConfig('mail.example.com', security: Security::None, logger: $logger);
        self::pop3($config, '', static fn(InMemoryConnection $server) => $server->expect("NOOP\r\n")
            ->reply("+OK 2 320\r\n"))
            ->noop();
        self::imap($config, null, static fn(InMemoryConnection $server) => $server->expect("TAG1 NOOP\r\n")
            ->reply("TAG1 OK\r\n"))->noop();
        self::smtp(
            new ConnectionConfig('mail.example.com', security: Security::None, logger: $logger),
            new Plain('jo', self::PASSWORD),
        );

        static::assertSame(
            ['C: NOOP', 'S: +OK 2 320', 'C: TAG1 NOOP', 'C: AUTH PLAIN'],
            array_values(array_intersect(
                $logger->messages(),
                ['C: NOOP', 'S: +OK 2 320', 'C: TAG1 NOOP', 'C: AUTH PLAIN'],
            )),
        );
    }

    /**
     * @param Closure(InMemoryConnection): InMemoryConnection $script What follows the greeting.
     */
    private static function pop3(ConnectionConfig $config, string $timestamp, Closure $script): Pop3
    {
        $pop3 = new Pop3(
            connection: $script((new InMemoryConnection())->reply("+OK POP3 ready {$timestamp}\r\n"))->hangUp(),
        );
        $pop3->connect($config);

        return $pop3;
    }

    private static function smtp(
        ConnectionConfig $config,
        AuthenticatorInterface $authenticator,
        SmtpServer $server = new SmtpServer(),
    ): void {
        $server->setCapabilities('AUTH PLAIN LOGIN CRAM-MD5 XOAUTH2 SCRAM-SHA-256');
        $smtp = new Smtp(
            $config,
            config: ['allow_insecure_auth' => true],
            authenticator: $authenticator,
            connection: $server,
        );
        $smtp->connect();
        $smtp->helo('localhost');
        static::assertTrue($smtp->isAuthenticated());
    }

    /**
     * @param Closure(): void $session
     */
    private static function refused(Closure $session): void
    {
        try {
            $session();
        } catch (RuntimeException) {
            return;
        }

        static::fail('The server did not refuse');
    }
}
