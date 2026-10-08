<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Imap\NamespaceEntry;
use Contenir\Mail\Protocol\Imap\Namespaces;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NAMESPACE (RFC 2342) and STATUS, with SIZE (RFC 8438), for describing
 * folders without selecting them (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[CoversClass(Namespaces::class)]
#[CoversClass(NamespaceEntry::class)]
#[Group('unit')]
final class NamespaceAndStatusTest extends TestCase
{
    /**
     * A server listing $capabilities, asked for them first; the next command is tagged TAG2.
     */
    private static function server(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 {$capabilities}\r\nTAG1 OK\r\n");
    }

    /**
     * A server answering NAMESPACE with $response; the client asks for capabilities first.
     */
    private static function namespaceServer(string $response): InMemoryConnection
    {
        return self::server('NAMESPACE')
            ->expect("TAG2 NAMESPACE\r\n")
            ->reply("{$response}TAG2 OK\r\n")
            ->hangUp();
    }

    #[Test]
    public function readsPersonalOtherUsersAndSharedNamespaces(): void
    {
        $server = self::namespaceServer(
            "* OK still here\r\n"
                . '* NAMESPACE (("" "/")("#mh/" "/" "X-PARAM" ("FLAG1" "FLAG2")))'
                . " ((\"~\" \"/\")) ((\"#shared/\" \"/\")(\"#public\" nil))\r\n",
        );

        static::assertEquals(
            new Namespaces(
                personal: [new NamespaceEntry('', '/'), new NamespaceEntry('#mh/', '/')],
                otherUsers: [new NamespaceEntry('~', '/')],
                shared: [new NamespaceEntry('#shared/', '/'), new NamespaceEntry('#public', null)],
            ),
            ScriptedServer::imap($server)->namespace(),
        );
    }

    #[Test]
    public function readsNilAsNoNamespaces(): void
    {
        $server = self::namespaceServer("* namespace ((\"INBOX.\" \".\")) nil NIL\r\n");

        static::assertEquals(
            new Namespaces(
                personal: [new NamespaceEntry('INBOX.', '.')],
                otherUsers: [],
                shared: [],
            ),
            ScriptedServer::imap($server)->namespace(),
        );
    }

    #[Test]
    public function decodesPrefixesFromModifiedUtf7(): void
    {
        $server = self::namespaceServer("* NAMESPACE ((\"&AMk-quipe/R&-D/\" \"/\")) NIL NIL\r\n");

        static::assertSame(
            'Équipe/R&D/',
            ScriptedServer::imap($server)->namespace()?->personal[0]?->prefix,
        );
    }

    #[Test]
    public function keepsPrefixesAsTheyAreWithUtf8Mailboxes(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 NAMESPACE\r\n")
            ->reply("* NAMESPACE ((\"Équipe/R&-D/\" \"/\")) NIL NIL\r\nTAG4 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        static::assertSame('Équipe/R&-D/', $imap->namespace()?->personal[0]?->prefix);
    }

    #[Test]
    #[DataProvider('enabledExtensionProvider')]
    public function reportsWhetherImap4Rev2IsEnabled(string $extension, bool $expected): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 {$extension}\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE {$extension}\r\n")
            ->reply("* ENABLED {$extension}\r\nTAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        static::assertSame($expected, $imap->isImap4Rev2Enabled());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function enabledExtensionProvider(): array
    {
        return [
            'IMAP4rev2'        => ['IMAP4rev2', true],
            'only UTF8=ACCEPT' => ['UTF8=ACCEPT', false],
        ];
    }

    #[Test]
    public function asksAnImap4Rev2ServerThatDoesNotListNamespace(): void
    {
        $server = self::server('IMAP4rev2')
            ->expect("TAG2 NAMESPACE\r\n")
            ->reply("* NAMESPACE NIL NIL ((\"\" \"/\"))\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertEquals(
            new Namespaces(
                personal: [],
                otherUsers: [],
                shared: [new NamespaceEntry('', '/')],
            ),
            ScriptedServer::imap($server)->namespace(),
        );
    }

    #[Test]
    public function hasNoNamespacesWhenTheServerDoesNotOfferThem(): void
    {
        $server = self::server('SORT')->hangUp();

        static::assertNull(ScriptedServer::imap($server)->namespace());
        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsARefusedNamespace(string $reply): void
    {
        $server = self::server('NAMESPACE')
            ->expect("TAG2 NAMESPACE\r\n")
            ->reply("TAG2 {$reply}\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server refused NAMESPACE');

        $imap->namespace();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'NO'  => ['NO Not now'],
            'BAD' => ['BAD Unknown command'],
        ];
    }

    #[Test]
    public function reportsAMissingNamespaceResponse(): void
    {
        $imap = ScriptedServer::imap(self::namespaceServer("* OK still here\r\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent no NAMESPACE response');

        $imap->namespace();
    }

    #[Test]
    #[DataProvider('malformedNamespaceProvider')]
    public function refusesAMalformedNamespaceResponse(string $response): void
    {
        $imap = ScriptedServer::imap(self::namespaceServer("* NAMESPACE {$response}\r\n"));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a malformed NAMESPACE response');

        $imap->namespace();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedNamespaceProvider(): array
    {
        return [
            'an empty list'                 => ['() NIL NIL'],
            'a string other than NIL'       => ['"" NIL NIL'],
            'an entry that is not a list'   => ['("" "/") NIL NIL'],
            'an entry without a delimiter'  => ['(("")) NIL NIL'],
            'a prefix that is a list'       => ['((("") "/")) NIL NIL'],
            'a delimiter that is a list'    => ['(("" ("/"))) NIL NIL'],
            'an empty delimiter'            => ['(("" "")) NIL NIL'],
            'a delimiter of two characters' => ['(("" "//")) NIL NIL'],
            'a later entry malformed'       => ['(("" "/")("x")) NIL NIL'],
            'no shared namespaces'          => ['(("" "/")) NIL'],
        ];
    }

    #[Test]
    public function readsTheStatusOfAMailbox(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 STATUS \"Sent Items\" (MESSAGES UNSEEN SIZE)\r\n")
            ->reply(
                "* OK still here\r\n* STATUS \"Sent Items\" (messages 12 UNSEEN 0 SIZE 999999999999999999)\r\nTAG1 OK\r\n",
            )
            ->hangUp();

        static::assertSame(
            ['MESSAGES' => 12, 'UNSEEN' => 0, 'SIZE' => 999_999_999_999_999_999],
            ScriptedServer::imap($server)->status('Sent Items', ['messages', 'Unseen', 'SIZE']),
        );
    }

    #[Test]
    public function asksForTheStatusOfAMailboxNamedInModifiedUtf7(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 STATUS \"Entw&APw-rfe\" (UIDNEXT UIDVALIDITY RECENT DELETED HIGHESTMODSEQ)\r\n")
            ->reply(
                "* STATUS \"Entw&APw-rfe\" (UIDNEXT 7 UIDVALIDITY 3 RECENT 1 DELETED 2 HIGHESTMODSEQ 90)\r\nTAG1 OK\r\n",
            )
            ->hangUp();

        static::assertSame(
            ['UIDNEXT' => 7, 'UIDVALIDITY' => 3, 'RECENT' => 1, 'DELETED' => 2, 'HIGHESTMODSEQ' => 90],
            ScriptedServer::imap($server)->status(
                'Entwürfe',
                ['UIDNEXT', 'UIDVALIDITY', 'RECENT', 'DELETED', 'HIGHESTMODSEQ'],
            ),
        );
    }

    #[Test]
    #[DataProvider('emptyStatusProvider')]
    public function readsNoStatusWhenTheServerSendsNone(string $response): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 STATUS \"INBOX\" (MESSAGES)\r\n")
            ->reply("{$response}TAG1 OK\r\n")
            ->hangUp();

        static::assertSame([], ScriptedServer::imap($server)->status('INBOX', ['MESSAGES']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyStatusProvider(): array
    {
        return [
            'no untagged response'  => [''],
            'a STATUS without list' => ["* STATUS \"INBOX\"\r\n"],
            'another response'      => ["* 3 EXISTS\r\n"],
        ];
    }

    #[Test]
    #[DataProvider('refusalProvider')]
    public function reportsARefusedStatus(string $reply): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 STATUS \"Gone\" (MESSAGES)\r\n")
            ->reply("TAG1 {$reply}\r\n")
            ->hangUp();

        static::assertFalse(ScriptedServer::imap($server)->status('Gone', ['MESSAGES']));
    }

    /**
     * @param list<string> $items
     */
    #[Test]
    #[DataProvider('badItemProvider')]
    public function refusesABadStatusItem(array $items, string $message): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $imap->status('INBOX', $items);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function badItemProvider(): array
    {
        return [
            'no item'                => [[], 'STATUS needs at least one item'],
            'an unknown item'        => [['NAME'], '"NAME" is not an IMAP status item'],
            'an injected list end'   => [['MESSAGES)'], '"MESSAGES)" is not an IMAP status item'],
            'a prefix of an item'    => [['XMESSAGES'], '"XMESSAGES" is not an IMAP status item'],
            'a trailing line'        => [["SIZE\n"], "\"SIZE\n\" is not an IMAP status item"],
            'two items in one'       => [['MESSAGES UNSEEN'], '"MESSAGES UNSEEN" is not an IMAP status item'],
            'a good then a bad item' => [['SIZE', 'ALL'], '"ALL" is not an IMAP status item'],
        ];
    }

    #[Test]
    #[DataProvider('malformedValueProvider')]
    public function refusesAStatusValueThatIsNotANumber(string $values): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 STATUS \"INBOX\" (MESSAGES)\r\n")
            ->reply("* STATUS \"INBOX\" ({$values})\r\nTAG1 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server sent a malformed STATUS response');

        $imap->status('INBOX', ['MESSAGES']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedValueProvider(): array
    {
        return [
            'a word'               => ['MESSAGES many'],
            'letters before'       => ['MESSAGES x12'],
            'letters after'        => ['MESSAGES 12x'],
            'a negative number'    => ['MESSAGES -1'],
            'an empty string'      => ['MESSAGES ""'],
            'nineteen digits'      => ['MESSAGES 1000000000000000000'],
            'a list'               => ['MESSAGES (1)'],
            'no value'             => ['MESSAGES'],
            'a good then bad item' => ['UNSEEN 1 MESSAGES x'],
        ];
    }
}
