<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\ExposedImap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * RFC 3501 quoted strings in a response line, which may hold backslash-escaped
 * quotes and backslashes. laminas-mail ended the string at the first quote,
 * cutting an ENVELOPE subject short (laminas/laminas-mail#62).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class QuotedStringTest extends TestCase
{
    /**
     * @param list<mixed> $expected
     */
    #[Test]
    #[DataProvider('quotedStringProvider')]
    public function decodesQuotedString(string $line, array $expected): void
    {
        $imap = new ExposedImap(connection: new InMemoryConnection());

        static::assertSame($expected, $imap->decode("{$line}\r\n"));
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function quotedStringProvider(): array
    {
        return [
            'plain'                       => ['"INBOX"', ['INBOX']],
            'empty'                       => ['A "" B', ['A', '', 'B']],
            'spaces and parentheses'      => ['("a (b) c" x)', [['a (b) c', 'x']]],
            'escaped quotes'              => [
                '(ENVELOPE ("Igor Presnyakov: \"Hi\"" NIL))',
                [['ENVELOPE', ['Igor Presnyakov: "Hi"', 'NIL']]],
            ],
            'escaped backslash delimiter' => ['LIST () "\\\\" INBOX', ['LIST', [], '\\', 'INBOX']],
            'escaped backslash at end'    => ['(("x\\\\" "y"))', [[['x\\', 'y']]]],
            'unterminated'                => ['"abc', ['"abc']],
        ];
    }
}
