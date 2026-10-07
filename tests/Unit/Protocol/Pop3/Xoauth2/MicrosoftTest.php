<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3\Xoauth2;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\InMemoryConnection;
use Contenir\Mail\Protocol\Pop3\Xoauth2\Microsoft;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

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
