<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3\Xoauth2;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3\Xoauth2\Microsoft;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2;
use Contenir\Mail\Testing\InMemoryConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function base64_encode;
use function preg_quote;

#[CoversClass(Microsoft::class)]
#[Group('unit')]
final class MicrosoftTest extends TestCase
{
    #[Test]
    public function sendsTheXoauth2SaslResponseAfterTheServerAcceptsTheMechanism(): void
    {
        $sasl   = Xoauth2::encodeXoauth2Sasl('test@example.com', '123');
        $server = $this->greetingServer()
            ->expect("AUTH XOAUTH2\r\n")
            ->reply("+ \r\n")
            ->expect("{$sasl}\r\n")
            ->reply("+OK Authenticated\r\n")
            ->hangUp();

        $this->connect($server)->login('test@example.com', '123');

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function throwsTheServerMessageWhenTheMechanismIsRefused(): void
    {
        $server = $this->greetingServer()
            ->expect("AUTH XOAUTH2\r\n")
            ->reply("-ERR XOAUTH2 not available\r\n")
            ->hangUp();
        $pop3 = $this->connect($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('XOAUTH2 not available');

        $pop3->login('test@example.com', '123');
    }

    #[Test]
    public function endsTheExchangeAndReportsTheStatusWhenTheTokenIsRefused(): void
    {
        $sasl   = Xoauth2::encodeXoauth2Sasl('test@example.com', 'expired');
        $server = $this->greetingServer()
            ->expect("AUTH XOAUTH2\r\n")
            ->reply("+ \r\n")
            ->expect("{$sasl}\r\n")
            ->reply('+ ' . base64_encode('{"status":"401","schemes":"bearer"}') . "\r\n")
            ->expect("\r\n")
            ->reply("-ERR Authentication failed\r\n")
            ->hangUp();
        $pop3 = $this->connect($server);

        try {
            $pop3->login('test@example.com', 'expired');
            static::fail('The refused token was not reported');
        } catch (RuntimeException $e) {
            static::assertSame(
                ['The server refused the access token (status 401)', true],
                [$e->getMessage(), $server->isScriptComplete()],
            );
        }
    }

    #[Test]
    #[DataProvider('outrightRefusalProvider')]
    public function reportsATokenRefusedOutright(string $reply, string $message): void
    {
        $sasl   = Xoauth2::encodeXoauth2Sasl('test@example.com', 'expired');
        $server = $this->greetingServer()
            ->expect("AUTH XOAUTH2\r\n")
            ->reply("+ \r\n")
            ->expect("{$sasl}\r\n")
            ->reply($reply)
            ->hangUp();
        $pop3 = $this->connect($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $pop3->login('test@example.com', 'expired');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outrightRefusalProvider(): array
    {
        return [
            'with a reason'    => ["-ERR [AUTH] Authentication failed\r\n", '[AUTH] Authentication failed'],
            'without a reason' => ["-ERR\r\n", 'The server refused the access token'],
        ];
    }

    #[DataProvider('saslFieldInjectionProvider')]
    #[Test]
    public function refusesSaslFieldInjectionThroughControlCharacters(
        string $user,
        #[SensitiveParameter]
        string $token,
    ): void {
        $pop3 = $this->connect($this->greetingServer()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('XOAUTH2 user names and tokens cannot contain control characters');

        $pop3->login($user, $token);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function saslFieldInjectionProvider(): array
    {
        return [
            'field separator in the user'  => ["user@example.com\x01auth=Bearer stolen", 'token'],
            'field separator in the token' => ['user@example.com', "token\x01\x01"],
            'line feed in the token'       => ['user@example.com', "token\nQUIT"],
            'delete in the user'           => ["user\x7F", 'token'],
        ];
    }

    private function greetingServer(): InMemoryConnection
    {
        return (new InMemoryConnection())->reply("+OK ready\r\n");
    }

    private function connect(InMemoryConnection $server): Microsoft
    {
        $pop3 = new Microsoft(connection: $server);
        $pop3->connect(new ConnectionConfig(
            host: 'pop3.example.com',
            security: Security::None,
        ));

        return $pop3;
    }
}
