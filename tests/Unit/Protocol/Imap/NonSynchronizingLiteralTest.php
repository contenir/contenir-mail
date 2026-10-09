<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * Non-synchronising literals (RFC 7888, LITERAL+ and LITERAL-), sent without
 * waiting for the server's "+" (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class NonSynchronizingLiteralTest extends TestCase
{
    /**
     * A message of $size bytes that a quoted string cannot carry.
     */
    private static function message(int $size): string
    {
        return str_repeat('a', $size - 2) . "\r\n";
    }

    /**
     * A server listing $capabilities, read before the first command; the next command is tagged TAG2.
     */
    private static function server(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 {$capabilities}\r\nTAG1 OK\r\n");
    }

    private static function imap(InMemoryConnection $server): Imap
    {
        $imap = ScriptedServer::imap($server);
        $imap->hasCapability('IMAP4rev1');

        return $imap;
    }

    #[DataProvider('nonSynchronizingProvider')]
    #[Test]
    public function sendsANonSynchronizingLiteralWhenTheServerOffersIt(string $capabilities, int $size): void
    {
        $message = self::message($size);
        $server  = self::server($capabilities)
            ->expect("TAG2 APPEND \"Sent\" {{$size}+}\r\n")
            ->expect("{$message}\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        static::assertTrue(self::imap($server)->append('Sent', $message));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function nonSynchronizingProvider(): array
    {
        return [
            'LITERAL+ at any size'      => ['LITERAL+', 10_000],
            'LITERAL- up to 4096 bytes' => ['LITERAL-', 4096],
            'LITERAL- for a small one'  => ['LITERAL-', 3],
            'LITERAL+ and LITERAL-'     => ['LITERAL- LITERAL+', 4097],
        ];
    }

    #[DataProvider('synchronizingProvider')]
    #[Test]
    public function waitsForTheServerOtherwise(string $capabilities, int $size): void
    {
        $message = self::message($size);
        $server  = self::server($capabilities)
            ->expect("TAG2 APPEND \"Sent\" {{$size}}\r\n")
            ->reply("+ Ready\r\n")
            ->expect("{$message}\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        static::assertTrue(self::imap($server)->append('Sent', $message));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function synchronizingProvider(): array
    {
        return [
            'no LITERAL extension'     => ['MOVE', 3],
            'LITERAL- over 4096 bytes' => ['LITERAL-', 4097],
        ];
    }

    #[Test]
    public function sendsANonSynchronizingLiteralOfUpTo4096BytesWithImap4Rev2Enabled(): void
    {
        $small  = self::message(4096);
        $large  = self::message(4097);
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 APPEND \"Sent\" {4096+}\r\n")
            ->expect("{$small}\r\n")
            ->reply("TAG4 OK\r\n")
            ->expect("TAG5 APPEND \"Sent\" {4097}\r\n")
            ->reply("+ Ready\r\n")
            ->expect("{$large}\r\n")
            ->reply("TAG5 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        static::assertSame([true, true], [$imap->append('Sent', $small), $imap->append('Sent', $large)]);
    }

    #[Test]
    public function sendsAPasswordAsANonSynchronizingLiteralWhenTheServerOffersLiteralPlus(): void
    {
        $server = self::server('LITERAL+')
            ->expect("TAG2 LOGIN \"jo\" {5+}\r\n")
            ->expect("p\xC3\xA4ss\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->login('jo', "p\xC3\xA4ss"));
    }

    #[Test]
    public function asksForNoCapabilitiesInTheMiddleOfACommand(): void
    {
        $message = self::message(3);
        $server  = ScriptedServer::imapGreeting()
            ->expect("TAG1 APPEND \"Sent\" {3}\r\n")
            ->reply("+ Ready\r\n")
            ->expect("{$message}\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->append('Sent', $message));
    }
}
