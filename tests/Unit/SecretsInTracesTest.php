<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Container\TransportFactory;
use Contenir\Mail\Protocol\AbstractProtocol;
use Contenir\Mail\Protocol\CommandLine;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Protocol\TlsConfig;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use Contenir\Mail\Tests\Unit\TestAsset\ArrayContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameterValue;
use Throwable;

use function base64_encode;
use function ini_get;
use function ini_set;
use function is_array;
use function is_string;
use function str_contains;

/**
 * A secret never shows in the arguments of an exception's trace, even when
 * zend.exception_ignore_args is off, as it is by default in development.
 */
#[CoversClass(AbstractProtocol::class)]
#[CoversClass(CommandLine::class)]
#[CoversClass(ConfigReader::class)]
#[CoversClass(Imap::class)]
#[CoversClass(InMemoryConnection::class)]
#[CoversClass(Pop3::class)]
#[CoversClass(Smtp::class)]
#[CoversClass(TransportFactory::class)]
#[Group('unit')]
final class SecretsInTracesTest extends TestCase
{
    /**
     * @mago-expect lint:no-literal-password A made-up value the tests look for, not a credential.
     */
    private const string PASSWORD = 'hunter2';

    private string|false $ignoreArgs = false;

    /**
     * @mago-expect lint:no-ini-set Keeps arguments in traces for this test, as development settings do.
     */
    protected function setUp(): void
    {
        $this->ignoreArgs = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', value: '0');
    }

    /**
     * @mago-expect lint:no-ini-set Restores the setting setUp() changed.
     */
    protected function tearDown(): void
    {
        if (false !== $this->ignoreArgs) {
            ini_set('zend.exception_ignore_args', $this->ignoreArgs);
        }
    }

    /**
     * @param Closure(): mixed $fail
     * @param list<string> $secrets
     */
    #[DataProvider('failureProvider')]
    #[Test]
    public function keepsTheSecretOutOfTheTrace(Closure $fail, array $secrets): void
    {
        $caught = null;
        try {
            $fail();
        } catch (Throwable $e) {
            $caught = $e;
        }

        static::assertNotNull($caught, 'The failure was not thrown');

        $redacted = 0;
        $leaked   = [];
        for ($e = $caught; null !== $e; $e = $e->getPrevious()) {
            foreach ($e->getTrace() as $frame) {
                if (self::class === ($frame['class'] ?? null)) {
                    break;
                }

                self::inspect($frame['args'] ?? [], $secrets, $redacted, $leaked, $frame['function']);
            }
        }

        static::assertSame([], $leaked, 'A secret is in the trace');
        static::assertGreaterThan(0, $redacted, 'No argument was redacted, so the trace holds no arguments to check');
    }

    /**
     * @return array<string, array{Closure(): mixed, list<string>}>
     */
    public static function failureProvider(): array
    {
        $plain = static fn(): ConnectionConfig => new ConnectionConfig('mail.example.com', security: Security::None);

        return [
            'IMAP LOGIN, quoted, connection lost'                             => [
                static fn(): bool => ScriptedServer::imap(
                    ScriptedServer::imapGreeting()
                        ->expect("TAG1 CAPABILITY\r\n")
                        ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
                        ->hangUp(),
                )->login('bob', self::PASSWORD),
                [self::PASSWORD],
            ],
            'IMAP LOGIN, literal, connection lost'                            => [
                static fn(): bool => ScriptedServer::imap(
                    ScriptedServer::imapGreeting()
                        ->expect("TAG1 CAPABILITY\r\n")
                        ->reply("* CAPABILITY IMAP4rev1 LITERAL+\r\nTAG1 OK\r\n")
                        ->hangUp(),
                )->login('bob', "h\u{fc}nter2"),
                ["h\u{fc}nter2"],
            ],
            'POP3 PASS, connection lost'                                      => [
                static function (): void {
                    ScriptedServer::pop3(
                        ScriptedServer::pop3Greeting()->expect("USER bob\r\n")->reply("+OK\r\n")->hangUp(),
                    )->login('bob', self::PASSWORD, tryApop: false);
                },
                [self::PASSWORD],
            ],
            'SMTP AUTH PLAIN, connection lost'                                => [
                static function () use ($plain): void {
                    $server = (new InMemoryConnection())->reply("220 mail.example.com ESMTP\r\n")
                        ->expect("EHLO localhost\r\n")
                        ->reply("250-mail.example.com\r\n250 AUTH PLAIN\r\n")
                        ->expect("AUTH PLAIN\r\n")
                        ->reply("334 \r\n")
                        ->hangUp();
                    $smtp = new Smtp(
                        $plain(),
                        config: ['allow_insecure_auth' => true],
                        authenticator: new Plain('bob', self::PASSWORD),
                        connection: $server,
                    );
                    $smtp->connect();
                    $smtp->helo('localhost');
                    $smtp->auth();
                },
                [self::PASSWORD, base64_encode("\0bob\0" . self::PASSWORD)],
            ],
            'SMTP settings array with an unknown key'                         => [
                static fn(): Smtp => new Smtp(['host' => 'mail.example.com', 'password' => self::PASSWORD]),
                [self::PASSWORD],
            ],
            'SMTP host and config with an unknown key'                        => [
                static fn(): Smtp => new Smtp('mail.example.com', 25, ['password' => self::PASSWORD]),
                [self::PASSWORD],
            ],
            'SMTP ConnectionConfig and config with an unknown key'            => [
                static fn(): Smtp => new Smtp($plain(), config: ['password' => self::PASSWORD]),
                [self::PASSWORD],
            ],
            'ImapConfig with an unknown key'                                  => [
                static fn(): ImapConfig => ImapConfig::fromIterable([
                    'host'    => 'imap.example.com',
                    'user'    => 'bob',
                    'pasword' => self::PASSWORD,
                ]),
                [self::PASSWORD],
            ],
            'ImapConfig with a password of the wrong type'                    => [
                static fn(): ImapConfig => ImapConfig::fromIterable([
                    'host'     => 'imap.example.com',
                    'user'     => 'bob',
                    'password' => [self::PASSWORD],
                ]),
                [self::PASSWORD],
            ],
            'TLS settings with a passphrase and an invalid file'              => [
                static fn(): ConnectionConfig => ConnectionConfig::fromIterable([
                    'cafile'     => '',
                    'local_cert' => 'client.pem',
                    'passphrase' => self::PASSWORD,
                ]),
                [self::PASSWORD],
            ],
            'a TLS passphrase given with an invalid file'                     => [
                static fn(): TlsConfig => new TlsConfig(
                    caFile: '',
                    localCert: 'client.pem',
                    localPrivateKeyPassphrase: self::PASSWORD,
                ),
                [self::PASSWORD],
            ],
            'a list holding an item of the wrong type'                        => [
                static fn(): ConfigReader => self::readList([self::PASSWORD, 1]),
                [self::PASSWORD],
            ],
            'transport factory with settings the in-memory transport refuses' => [
                static fn(): mixed => (new TransportFactory())(new ArrayContainer([
                    'config' => [
                        'mail' => ['transport' => ['type' => 'in-memory', 'password' => self::PASSWORD]],
                    ],
                ])),
                [self::PASSWORD],
            ],
        ];
    }

    /**
     * @param list<mixed> $value
     */
    private static function readList(array $value): ConfigReader
    {
        $reader = ConfigReader::read('Test', ['list' => $value], ['list']);
        $reader->stringList('list', default: []);

        return $reader;
    }

    /**
     * @param list<string> $secrets
     * @param list<string> $leaked
     */
    private static function inspect(mixed $value, array $secrets, int &$redacted, array &$leaked, string $where): void
    {
        if ($value instanceof SensitiveParameterValue) {
            $redacted++;

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::inspect($item, $secrets, $redacted, $leaked, $where);
            }

            return;
        }

        if (! is_string($value)) {
            return;
        }

        foreach ($secrets as $secret) {
            if (! str_contains($value, $secret)) {
                continue;
            }

            $leaked[] = "{$where}(): {$value}";
        }
    }
}
