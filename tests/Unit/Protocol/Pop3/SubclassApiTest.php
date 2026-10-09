<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ExposedPop3;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The protected response reader stays available to subclasses, as it was for
 * Pop3\Xoauth2\Microsoft before Pop3::authenticate() took its place.
 */
#[CoversClass(Pop3::class)]
#[Group('unit')]
final class SubclassApiTest extends TestCase
{
    /**
     * @param array{string, string} $expected
     */
    #[Test]
    #[DataProvider('responseProvider')]
    public function readsTheStatusAndMessageOfTheNextResponse(string $line, array $expected): void
    {
        $pop3 = new ExposedPop3(connection: ScriptedServer::pop3Greeting()->reply($line)->hangUp());
        $pop3->connect(ScriptedServer::plain());

        static::assertSame($expected, $pop3->response());
    }

    /**
     * @return array<string, array{string, array{string, string}}>
     */
    public static function responseProvider(): array
    {
        return [
            'success with a message' => ["+OK 2 messages\r\n", ['+OK', '2 messages']],
            'failure with a message' => ["-ERR no such message\r\n", ['-ERR', 'no such message']],
            'a continuation'         => ["+ \r\n", ['+', '']],
        ];
    }
}
