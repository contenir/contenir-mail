<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/**
 * Hostile credentials must never reach the server as a second command.
 */
#[CoversClass(Pop3::class)]
#[Group('unit')]
final class CommandInjectionTest extends TestCase
{
    #[DataProvider('injectedCredentialProvider')]
    #[Test]
    public function refusesCredentialsThatWouldEndTheCommand(string $user, #[SensitiveParameter] string $password): void
    {
        $pop3 = ScriptedServer::pop3(ScriptedServer::pop3Greeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to send a command containing CR, LF or NUL');

        $pop3->login($user, $password, false);
    }

    #[DataProvider('injectedCredentialProvider')]
    #[Test]
    public function refusesCredentialsThatWouldEndTheApopCommand(
        string $user,
        #[SensitiveParameter]
        string $password,
    ): void {
        $pop3 = ScriptedServer::pop3(ScriptedServer::pop3Greeting('<1@host>')->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to send a command containing CR, LF or NUL');

        $pop3->login($user, $password);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function injectedCredentialProvider(): array
    {
        return [
            'CRLF in the user' => ["user\r\nDELE 1", 'secret'],
            'LF in the user'   => ["user\nDELE 1", 'secret'],
            'CR in the user'   => ["user\rDELE 1", 'secret'],
            'NUL in the user'  => ["user\0", 'secret'],
        ];
    }

    #[DataProvider('injectedPasswordProvider')]
    #[Test]
    public function refusesAPasswordThatWouldEndTheCommandWithoutRevealingIt(
        #[SensitiveParameter]
        string $password,
    ): void {
        $pop3 = ScriptedServer::pop3(
            ScriptedServer::pop3Greeting()->expect("USER user\r\n")->reply("+OK\r\n")->hangUp(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it could inject another command');

        $pop3->login('user', $password, false);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedPasswordProvider(): array
    {
        return [
            'CRLF' => ["secret\r\nDELE 1"],
            'LF'   => ["secret\nDELE 1"],
            'CR'   => ["secret\rDELE 1"],
            'NUL'  => ["secret\0"],
        ];
    }

    #[Test]
    public function refusesARawRequestThatWouldEndTheCommand(): void
    {
        $pop3 = ScriptedServer::pop3(ScriptedServer::pop3Greeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to send a command containing CR, LF or NUL');

        $pop3->request("NOOP\r\nDELE 1");
    }
}
