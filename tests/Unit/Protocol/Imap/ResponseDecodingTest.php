<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\StreamImap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Imap::class)]
#[Group('unit')]
final class ResponseDecodingTest extends TestCase
{
    /**
     * @param list<mixed> $expected
     */
    #[DataProvider('repeatedSpaceProvider')]
    #[Test]
    public function skipsEmptyTokensBetweenRepeatedSpaces(string $line, array $expected): void
    {
        $imap = new StreamImap($line);

        $imap->readLine($tokens);

        static::assertSame($expected, $tokens);
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function repeatedSpaceProvider(): array
    {
        return [
            'double space between atoms'  => ["* OK  ready\r\n", ['OK', 'ready']],
            'double space inside a list'  => [
                "* LIST (\\HasNoChildren)  \"/\" INBOX\r\n",
                ['LIST', ['\\HasNoChildren'], '/', 'INBOX'],
            ],
            'leading space after the tag' => ["*  SEARCH 1 2\r\n", ['SEARCH', '1', '2']],
            'run of spaces before a list' => ["* FLAGS    (\\Seen)\r\n", ['FLAGS', ['\\Seen']]],
        ];
    }
}
