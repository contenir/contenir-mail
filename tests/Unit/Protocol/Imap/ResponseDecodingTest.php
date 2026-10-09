<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\ResponseLimits;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

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
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply($line)->hangUp());

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
            'only spaces after the tag'   => ["*     \r\n", []],
        ];
    }

    /**
     * @param list<mixed> $expected
     */
    #[DataProvider('tokenProvider')]
    #[Test]
    public function decodesTokens(string $response, array $expected): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply($response)->hangUp());

        $imap->readLine($tokens);

        static::assertSame($expected, $tokens);
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function tokenProvider(): array
    {
        return [
            'atoms'                          => ["* 3 EXISTS\r\n", ['3', 'EXISTS']],
            'quoted string with spaces'      => ["* LIST () \"/\" \"Sent Items\"\r\n", ['LIST', [], '/', 'Sent Items']],
            'quoted string in a list'        => ["* X (\"a b\" c)\r\n", ['X', ['a b', 'c']]],
            'nested lists'                   => ["* X (a (b (c)))\r\n", ['X', ['a', ['b', ['c']]]]],
            'lists closed in one token'      => ["* X ((a b))\r\n", ['X', [['a', 'b']]]],
            'closing brace without content'  => ["* X (a )\r\n", ['X', ['a']]],
            'more closing braces than open'  => ["* X (a)))\r\n", ['X', ['a']]],
            'missing closing braces'         => ["* X (a (b\r\n", ['X', ['a', ['b']]]],
            'unterminated quote'             => ["* X \"abc\r\n", ['X', '"abc']],
            'literal'                        => [
                "* 1 FETCH (RFC822 {5}\r\nhello)\r\n",
                ['1', 'FETCH', ['RFC822', 'hello']],
            ],
            'literal with line breaks'       => [
                "* 1 FETCH (RFC822 {7}\r\nab\r\ncd FLAGS (\\Seen))\r\n",
                ['1', 'FETCH', ['RFC822', "ab\r\ncd ", 'FLAGS', ['\\Seen']]],
            ],
            'literal ending in a line break' => [
                "* 1 FETCH (RFC822 {4}\r\nab\r\n)\r\n",
                ['1', 'FETCH', ['RFC822', "ab\r\n"]],
            ],
            'empty literal'                  => ["* 1 FETCH (RFC822 {0}\r\n)\r\n", ['1', 'FETCH', ['RFC822', '']]],
            'brace that is not a literal'    => ["* X {abc}\r\n", ['X', '{abc}']],
            'negative literal size'          => ["* X {-5}\r\n", ['X', '{-5}']],
            'brace after an atom'            => ["* X x{3}\r\n", ['X', 'x{3}']],
            'atom after a brace'             => ["* X {3}x\r\n", ['X', '{3}x']],
            'nested lists closed early'      => ["* X (((a b)) c) d\r\n", ['X', [[['a', 'b']], 'c'], 'd']],
            'list closed before an atom'     => ["* X ((a b)) c\r\n", ['X', [['a', 'b']], 'c']],
            'quoted string after an atom'    => ["* X a\"b c\" d\r\n", ['X', 'a"b', 'c"', 'd']],
            'quoted string then an atom'     => ["* X \"a\"b c\r\n", ['X', 'a', 'b', 'c']],
            'lists next to each other'       => ["* X ((a b)(c d))\r\n", ['X', [['a', 'b'], ['c', 'd']]]],
            'lists of strings side by side'  => [
                "* X ((\"a\" \"b\")(\"c\" NIL)(\"e\" \"f\"))\r\n",
                ['X', [['a', 'b'], ['c', 'NIL'], ['e', 'f']]],
            ],
            'nested lists side by side'      => ["* X (((a))(b)) c\r\n", ['X', [[['a']], ['b']], 'c']],
            'top-level lists side by side'   => ["* X (a)(b)\r\n", ['X', ['a'], ['b']]],
            'list marks inside a string'     => ["* X (\"a)(b c\" d)\r\n", ['X', ['a)(b c', 'd']]],
        ];
    }

    #[Test]
    public function returnsTheUnparsedLineWhenAsked(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply("* OK  {5}\r\n")->hangUp());

        $imap->readLine($tokens, '*', true);

        static::assertSame("OK  {5}\r\n", $tokens);
    }

    #[Test]
    public function reportsWhetherTheLineHasTheWantedTag(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply("TAG7 OK done\r\n")->hangUp());

        static::assertTrue($imap->readLine(wantedTag: 'TAG7'));
    }

    #[Test]
    public function reportsALineWithAnotherTag(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply("* OK done\r\n")->hangUp());

        static::assertFalse($imap->readLine(wantedTag: 'TAG7'));
    }

    #[Test]
    public function readsALineWithoutASpaceAsATagWithNoContent(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply("garbage\r\n")->hangUp());

        $imap->readLine($tokens, 'garbage');

        static::assertSame([], $tokens);
    }

    #[Test]
    public function refusesALiteralLargerThanTheResponseLimitBeforeReadingIt(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()->reply("* 1 FETCH (RFC822 {99999999999}\r\n")->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The server announced a literal of 99999999999 bytes, over the response limit of 4096 bytes',
        );

        $imap->readLine();
    }

    #[Test]
    public function acceptsALiteralThatFillsTheResponseLimitExactly(): void
    {
        $line    = "* 1 FETCH (RFC822 {4045}\r\n";
        $literal = str_repeat('a', times: 4045);
        $imap    = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->reply("{$line}{$literal})\r\n")
                ->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));

        $imap->readLine($tokens);

        static::assertSame(['1', 'FETCH', ['RFC822', $literal]], $tokens);
    }

    #[Test]
    public function readsALiteralThatReachesTheResponseLimitBeforeRefusingTheRestOfTheLine(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->reply("* 1 FETCH (RFC822 {4048}\r\n" . str_repeat('a', times: 4048) . ")\r\n")
                ->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server's response exceeds the limit of 4096 bytes");

        $imap->readLine();
    }

    #[Test]
    public function refusesALineLongerThanTheLineLimit(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->reply('* ' . str_repeat('a', times: 2000) . "\r\n")
                ->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a line longer than 1024 bytes');

        $imap->readLine();
    }

    #[Test]
    public function acceptsALineOfExactlyTheLineLimit(): void
    {
        $atom = str_repeat('a', 1024 - 4);
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply("* {$atom}\r\n")->hangUp());
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));

        $imap->readLine($tokens);

        static::assertSame([$atom], $tokens);
    }

    #[Test]
    public function acceptsAFinalLineWithoutALineFeedShorterThanTheLimit(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->reply('* BYE')->hangUp());

        $imap->readLine($tokens);

        static::assertSame(['BYE'], $tokens);
    }

    #[Test]
    public function refusesAResponseWhoseLinesTogetherExceedTheResponseLimit(): void
    {
        $line = '* ' . str_repeat('a', times: 1000) . "\r\n";
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 NOOP\r\n")
                ->reply(str_repeat($line, times: 5) . "TAG1 OK\r\n")
                ->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server's response exceeds the limit of 4096 bytes");

        $imap->noop();
    }

    /**
     * Four lines of 1004 bytes and a tagged line make a response one byte over, or exactly at, the limit.
     */
    #[DataProvider('responseSizeBoundaryProvider')]
    #[Test]
    public function measuresTheResponseFromTheStartOfTheCommand(int $padding, bool $fits): void
    {
        $line = '* ' . str_repeat('a', times: 1000) . "\r\n";
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 NOOP\r\n")
                ->reply(str_repeat($line, times: 4) . 'TAG1 OK ' . str_repeat('x', $padding) . "\r\n")
                ->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));
        try {
            $imap->noop();
            $result = true;
        } catch (RuntimeException) {
            $result = false;
        }

        static::assertSame($fits, $result);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function responseSizeBoundaryProvider(): array
    {
        return [
            'exactly the limit' => [70, true],
            'one byte over'     => [71, false],
        ];
    }

    #[Test]
    public function startsCountingAgainForEachCommand(): void
    {
        $line = '* ' . str_repeat('a', times: 1000) . "\r\n";
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 NOOP\r\n")
                ->reply(str_repeat($line, times: 3) . "TAG1 OK\r\n")
                ->expect("TAG2 NOOP\r\n")
                ->reply(str_repeat($line, times: 3) . "TAG2 OK\r\n")
                ->hangUp(),
        );
        $imap->setResponseLimits(new ResponseLimits(1024, 4096));
        $imap->noop();

        static::assertCount(3, $imap->noop());
    }

    #[Test]
    public function failsWhenTheServerClosesTheConnectionMidResponse(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()->expect("TAG1 NOOP\r\n")->reply("* 1 EXISTS\r\n")->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the connection is closed');

        $imap->noop();
    }

    #[Test]
    public function failsWhenTheServerClosesTheConnectionInsideALiteral(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()->reply("* 1 FETCH (RFC822 {50}\r\nshort")->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the connection is closed');

        $imap->readLine();
    }

    #[Test]
    public function failsWhenTheServerStopsAnswering(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->expect("TAG1 NOOP\r\n")->stall()->hangUp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('timed out');

        $imap->noop();
    }
}
