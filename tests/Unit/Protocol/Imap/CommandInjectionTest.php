<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

/**
 * Hostile user input must never reach the server as a second command.
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class CommandInjectionTest extends TestCase
{
    private InMemoryConnection $server;

    private Imap $imap;

    protected function setUp(): void
    {
        $this->server = ScriptedServer::imapGreeting()->hangUp();
        $this->imap   = ScriptedServer::imap($this->server);
    }

    #[DataProvider('invalidSequenceProvider')]
    #[Test]
    public function refusesMessageNumbersOutsideTheSequenceSetGrammar(int|string $from, int|float|null $to): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a valid message sequence set');

        $this->imap->fetch('UID', $from, $to);
    }

    /**
     * @return array<string, array{int|string, int|float|null}>
     */
    public static function invalidSequenceProvider(): array
    {
        return [
            'zero'                   => [0, null],
            'negative'               => [-1, null],
            'command after a number' => ['1 UID', null],
            'CRLF and a command'     => ["1\r\nTAG9 DELETE INBOX", null],
            'leading zero'           => ['01', null],
            'trailing comma'         => ['1,', null],
            'empty string'           => ['', null],
            'range to zero'          => [1, 0],
            'range to a negative'    => [1, -5],
            'parenthesis'            => ['1)', null],
            'trailing newline'       => ["1\n", null],
        ];
    }

    #[DataProvider('invalidSequenceProvider')]
    #[Test]
    public function refusesInvalidSequenceSetsWhenStoringFlags(int|string $from, int|float|null $to): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a valid message sequence set');

        $this->imap->store(['\\Seen'], $from, $to);
    }

    #[DataProvider('invalidSequenceProvider')]
    #[Test]
    public function refusesInvalidSequenceSetsWhenCopying(int|string $from, int|float|null $to): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a valid message sequence set');

        $this->imap->copy('Archive', $from, $to);
    }

    /**
     * @param list<mixed> $from
     */
    #[DataProvider('invalidListProvider')]
    #[Test]
    public function refusesListsOfMessagesOutsideTheSequenceSetGrammar(array $from): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a valid message sequence set');

        $this->imap->fetch('UID', $from);
    }

    /**
     * @return array<string, array{list<mixed>}>
     */
    public static function invalidListProvider(): array
    {
        return [
            'empty list'                => [[]],
            'element with a space'      => [['1', '2 3']],
            'element with a comma only' => [[1, ',']],
            'zero element'              => [[0]],
        ];
    }

    #[Test]
    public function refusesAListOfMessagesThatHoldsNonScalars(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message numbers must be integers or ranges');

        $this->imap->fetch('UID', [1, [2]]);
    }

    #[Test]
    public function refusesAFractionalLastMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The last message must be an integer or INF');

        $this->imap->fetch('UID', 1, 2.5);
    }

    #[DataProvider('invalidFlagProvider')]
    #[Test]
    public function refusesFlagsOutsideTheAtomGrammarWhenStoring(mixed $flag): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flags must be atoms');

        $this->imap->store([$flag], 1);
    }

    #[DataProvider('invalidFlagProvider')]
    #[Test]
    public function refusesFlagsOutsideTheAtomGrammarWhenAppending(mixed $flag): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Flags must be atoms');

        $this->imap->append('INBOX', 'message', [$flag]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidFlagProvider(): array
    {
        return [
            'closing parenthesis' => ['\\Seen) UID'],
            'space'               => ['my label'],
            'CRLF'                => ["\\Seen\r\nTAG9 LOGOUT"],
            'quote'               => ['"x"'],
            'empty'               => [''],
            'only a backslash'    => ['\\'],
            'two backslashes'     => ['\\\\Seen'],
            'percent wildcard'    => ['a%'],
            'star wildcard'       => ['a*'],
            'opening brace'       => ['{5}'],
            'closing bracket'     => ['a]'],
            'opening parenthesis' => ['(a'],
            'eight-bit'           => ['Entwürfe'],
            'delete character'    => ["a\x7F"],
            'tab'                 => ["a\tb"],
            'not a string'        => [5],
        ];
    }

    #[DataProvider('validFlagProvider')]
    #[Test]
    public function acceptsSystemFlagsAndKeywords(string $flag): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 STORE 1 +FLAGS.SILENT ({$flag})\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->store([$flag], 1, null, '+'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFlagProvider(): array
    {
        return [
            'system flag'      => ['\\Seen'],
            'keyword'          => ['$Forwarded'],
            'keyword with dot' => ['Work.Urgent'],
            'single character' => ['x'],
        ];
    }

    #[Test]
    public function refusesNulInAString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IMAP strings cannot contain NUL');

        $this->imap->login("user\0", 'secret');
    }

    #[DataProvider('lineBreakTokenProvider')]
    #[Test]
    public function refusesRawTokensThatWouldEndTheCommand(#[SensitiveParameter] string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to send a command containing CR, LF or NUL');

        $this->imap->search(['TEXT', $token]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function lineBreakTokenProvider(): array
    {
        return [
            'CRLF'    => ["x\r\nTAG9 DELETE INBOX"],
            'bare LF' => ["x\nTAG9 DELETE INBOX"],
            'bare CR' => ["x\rTAG9 DELETE INBOX"],
            'NUL'     => ["x\0"],
        ];
    }

    #[Test]
    public function refusesRequestTokensThatAreNeitherStringsIntegersNorLiterals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Request tokens must be strings, integers or literals');

        $this->imap->search(['UID', 1.5]);
    }

    #[Test]
    public function sendsIntegerRequestTokens(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SEARCH LARGER 1000\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertSame([], ScriptedServer::imap($server)->search(['LARGER', 1000]));
    }

    #[DataProvider('literalMarkerProvider')]
    #[Test]
    public function refusesRawTokensEndingInALiteralMarker(#[SensitiveParameter] string $token): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A request token may not end in a literal marker');

        $this->imap->search(['TEXT', $token]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function literalMarkerProvider(): array
    {
        return [
            'synchronising'     => ['{20}'],
            'non-synchronising' => ['{20+}'],
            'after an atom'     => ['x{3}'],
        ];
    }

    #[Test]
    public function acceptsABraceThatIsNotAtTheEndOfAToken(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SEARCH TEXT {3}x\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertSame([], ScriptedServer::imap($server)->search(['TEXT', '{3}x']));
    }

    #[DataProvider('forgedLiteralProvider')]
    #[Test]
    public function refusesALiteralWhoseSizeDoesNotMatchItsContent(array $literal): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A literal must be array("{size}", content)');

        $this->imap->search(['TEXT', $literal]);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function forgedLiteralProvider(): array
    {
        return [
            'size too small, smuggling a command' => [['{1}', "a\r\nTAG9 DELETE INBOX"]],
            'size too large'                      => [['{99}', 'abc']],
            'size without braces'                 => [['3', 'abc']],
            'no content'                          => [['{0}']],
            'content not a string'                => [['{1}', 1]],
            'NUL in the content'                  => [['{2}', "a\0"]],
        ];
    }

    #[Test]
    public function refusesATagThatWouldEndTheCommand(): void
    {
        $tag = "A1 NOOP\r\nA2";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to send a command containing CR, LF or NUL');

        $this->imap->sendRequest('NOOP', [], $tag);
    }

    #[Test]
    public function sendsAPasswordWithALineBreakAsALiteralRatherThanASecondCommand(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"user\" {21}\r\n")
            ->reply("+ go\r\n")
            ->expect("pw\r\nTAG2 DELETE INBOX\r\n")
            ->reply("TAG2 NO\r\n")
            ->hangUp();

        static::assertFalse(ScriptedServer::imap($server)->login('user', "pw\r\nTAG2 DELETE INBOX"));
    }

    /**
     * A control character in a mailbox name is written in modified UTF-7
     * (RFC 3501, section 5.1.3), so it never reaches the wire to end the command.
     */
    #[Test]
    public function sendsAControlCharacterInAFolderNameEncoded(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CREATE \"a&AA0-TAG2 LOGOUT x\"\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->create("a\rTAG2 LOGOUT x"));
    }

    #[Test]
    public function escapesQuotesAndBackslashesInAFolderName(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 DELETE \"a\\\" \\\\\"\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->delete('a" \\'));
    }

    #[Test]
    public function refusesALineBreakAfterALiteralMarker(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to send a command containing CR, LF or NUL');

        $this->imap->search(['TEXT', "x {5}\n"]);
    }
}
