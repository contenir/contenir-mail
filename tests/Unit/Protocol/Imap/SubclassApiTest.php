<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ExposedImap;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The protected parsing methods kept from laminas-mail stay available to subclasses.
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class SubclassApiTest extends TestCase
{
    private static function imap(string $response): ExposedImap
    {
        $imap = new ExposedImap(connection: ScriptedServer::imapGreeting()->reply($response)->hangUp());
        $imap->connect(ScriptedServer::plain());

        return $imap;
    }

    #[Test]
    public function readsTheNextLine(): void
    {
        static::assertSame("* 1 EXISTS\r\n", self::imap("* 1 EXISTS\r\n")->line());
    }

    #[Test]
    public function checksTheStartOfTheNextLine(): void
    {
        static::assertTrue(self::imap("+ go ahead\r\n")->lineStartsWith('+ '));
    }

    #[Test]
    public function splitsTheTagFromTheNextLine(): void
    {
        static::assertSame(['TAG4', "OK done\r\n"], self::imap("TAG4 OK done\r\n")->taggedLine());
    }

    #[Test]
    public function decodesALine(): void
    {
        $imap = new ExposedImap(connection: new InMemoryConnection());

        static::assertSame(['FLAGS', ['\\Seen']], $imap->decode("FLAGS (\\Seen)\r\n"));
    }
}
