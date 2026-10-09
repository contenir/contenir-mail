<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

use const INF;

#[CoversClass(Imap::class)]
#[Group('unit')]
final class CommandTest extends TestCase
{
    /**
     * A client whose first command must be $request, answered with $response.
     */
    private static function imap(string $request, string $response): Imap
    {
        return ScriptedServer::imap(ScriptedServer::imapGreeting()->expect($request)->reply($response)->hangUp());
    }

    /**
     * A client that first reads the capabilities, as LOGIN does to honour LOGINDISABLED, then sends $request.
     */
    private static function loginImap(string $request, string $response): Imap
    {
        return ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 CAPABILITY\r\n")
                ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
                ->expect($request)
                ->reply($response)
                ->hangUp(),
        );
    }

    #[Test]
    public function logsIn(): void
    {
        $imap = self::loginImap("TAG2 LOGIN \"user\" \"pass\\\"word\"\r\n", "TAG2 OK done\r\n");

        static::assertTrue($imap->login('user', 'pass"word'));
    }

    #[Test]
    public function logsInWithUntaggedCapabilitiesInTheResponse(): void
    {
        $imap = self::loginImap("TAG2 LOGIN \"user\" \"secret\"\r\n", "* CAPABILITY IMAP4rev1\r\nTAG2 OK done\r\n");

        static::assertTrue($imap->login('user', 'secret'));
    }

    #[DataProvider('failedResponseProvider')]
    #[Test]
    public function reportsAFailedLogin(string $response): void
    {
        $imap = self::loginImap("TAG2 LOGIN \"user\" \"wrong\"\r\n", $response);

        static::assertFalse($imap->login('user', 'wrong'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failedResponseProvider(): array
    {
        return [
            'NO'  => ["TAG2 NO [AUTHENTICATIONFAILED] invalid\r\n"],
            'BAD' => ["TAG2 BAD syntax\r\n"],
        ];
    }

    #[Test]
    public function readsCapabilities(): void
    {
        $imap = self::imap("TAG1 CAPABILITY\r\n", "* CAPABILITY IMAP4rev1 IDLE\r\n* CAPABILITY UIDPLUS\r\nTAG1 OK\r\n");

        static::assertSame(['CAPABILITY', 'IMAP4rev1', 'IDLE', 'CAPABILITY', 'UIDPLUS'], $imap->capability());
    }

    #[Test]
    public function selectsAFolder(): void
    {
        $imap = self::imap(
            "TAG1 SELECT \"INBOX\"\r\n",
            "* FLAGS (\\Answered \\Seen)\r\n* 172 EXISTS\r\n* 1 RECENT\r\n"
                . "* OK [UIDVALIDITY 3857529045] UIDs valid\r\n* OK [UIDNEXT 4392] Predicted next UID\r\n"
                . "* OK\r\nTAG1 OK [READ-WRITE] SELECT completed\r\n",
        );

        static::assertSame(
            ['flags' => [['\\Answered', '\\Seen']], 'exists' => '172', 'recent' => '1', 'uidvalidity' => 3_857_529_045],
            $imap->select(),
        );
    }

    #[Test]
    public function examinesAFolder(): void
    {
        $imap = self::imap("TAG1 EXAMINE \"Sent\"\r\n", "* 3 EXISTS\r\nTAG1 OK [READ-ONLY]\r\n");

        static::assertSame(['exists' => '3'], $imap->examine('Sent'));
    }

    #[Test]
    public function readsAUidValidityWithoutAValueAsZero(): void
    {
        $imap = self::imap("TAG1 EXAMINE \"INBOX\"\r\n", "* OK [UIDVALIDITY\r\nTAG1 OK\r\n");

        static::assertSame(['uidvalidity' => 0], $imap->examineOrSelect());
    }

    #[Test]
    public function acceptsTheCommandNameInAnyCase(): void
    {
        $imap = self::imap("TAG1 SELECT \"INBOX\"\r\n", "TAG1 OK\r\n");

        static::assertSame([], $imap->examineOrSelect('select'));
    }

    #[Test]
    public function reportsAFolderThatCannotBeSelected(): void
    {
        $imap = self::imap("TAG1 SELECT \"Missing\"\r\n", "TAG1 NO no such folder\r\n");

        static::assertFalse($imap->select('Missing'));
    }

    #[Test]
    public function refusesCommandsOtherThanExamineOrSelect(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The command must be EXAMINE or SELECT');

        $imap->examineOrSelect('DELETE');
    }

    #[Test]
    public function listsMailboxes(): void
    {
        $imap = self::imap(
            "TAG1 LIST \"\" \"*\"\r\n",
            "* LIST (\\HasNoChildren) \"/\" INBOX\r\n* LIST (\\Noselect) \"/\" \"Sent Items\"\r\n"
                . "* LSUB () \"/\" Other\r\n* LIST () \"/\"\r\n* LIST () \"/\" (odd)\r\nTAG1 OK\r\n",
        );

        static::assertSame(
            [
                'INBOX'      => ['delim' => '/', 'flags' => ['\\HasNoChildren']],
                'Sent Items' => ['delim' => '/', 'flags' => ['\\Noselect']],
            ],
            $imap->listMailbox(),
        );
    }

    #[DataProvider('emptyListResponseProvider')]
    #[Test]
    public function listsNoMailboxesWhenThereAreNone(string $response): void
    {
        $imap = self::imap("TAG1 LIST \"Archive\" \"%\"\r\n", $response);

        static::assertSame([], $imap->listMailbox('Archive', '%'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyListResponseProvider(): array
    {
        return [
            'none listed' => ["TAG1 OK\r\n"],
            'refused'     => ["TAG1 NO\r\n"],
        ];
    }

    #[DataProvider('storeProvider')]
    #[Test]
    public function setsFlagsSilently(string $request, int|string $from, int|float|null $to, ?string $mode): void
    {
        $imap = self::imap($request, "TAG1 OK\r\n");

        static::assertTrue($imap->store(['\\Seen', '$Label'], $from, $to, $mode));
    }

    /**
     * @return array<string, array{string, int|string, int|float|null, string|null}>
     */
    public static function storeProvider(): array
    {
        return [
            'replace on one message' => ["TAG1 STORE 1 FLAGS.SILENT (\\Seen \$Label)\r\n", 1, null, null],
            'add on a range'         => ["TAG1 STORE 2:5 +FLAGS.SILENT (\\Seen \$Label)\r\n", 2, 5, '+'],
            'remove to the last'     => ["TAG1 STORE 3:* -FLAGS.SILENT (\\Seen \$Label)\r\n", 3, INF, '-'],
            'other mode replaces'    => ["TAG1 STORE 4 FLAGS.SILENT (\\Seen \$Label)\r\n", '4', null, '='],
        ];
    }

    #[Test]
    public function reportsFlagsThatCouldNotBeSet(): void
    {
        $imap = self::imap("TAG1 STORE 1 FLAGS.SILENT (\\Seen)\r\n", "TAG1 NO\r\n");

        static::assertFalse($imap->store(['\\Seen'], 1));
    }

    #[Test]
    public function returnsTheNewFlagsWhenNotSilent(): void
    {
        $imap = self::imap(
            "TAG1 STORE 1:2 +FLAGS (\\Seen)\r\n",
            "* 1 FETCH (FLAGS (\\Seen))\r\n* 2 FETCH (FLAGS (\\Seen \\Draft))\r\n* 3 FETCH (UID 9)\r\n"
                . "* 4 EXISTS\r\n* 5 FETCH (FLAGS)\r\nTAG1 OK\r\n",
        );

        static::assertSame(
            ['1' => ['\\Seen'], '2' => ['\\Seen', '\\Draft'], '5' => []],
            $imap->store(['\\Seen'], 1, 2, '+', false),
        );
    }

    #[Test]
    public function reportsRefusedStoreWhenNotSilent(): void
    {
        $imap = self::imap("TAG1 STORE 1 FLAGS (\\Seen)\r\n", "TAG1 NO\r\n");

        static::assertFalse($imap->store(['\\Seen'], 1, null, null, false));
    }

    /**
     * Dovecot sends no FETCH for a message whose flags did not change.
     */
    #[Test]
    public function returnsNoFlagsWhenNotSilentAndTheServerSendsNone(): void
    {
        $imap = self::imap("TAG1 STORE 1 FLAGS (\\Seen)\r\n", "TAG1 OK\r\n");

        static::assertSame([], $imap->store(['\\Seen'], 1, null, null, false));
    }

    #[Test]
    public function appendsAMessageAsALiteral(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 APPEND \"Sent\" (\\Seen) \"01-Jan-2024 10:00:00 +0000\" {14}\r\n")
            ->reply("+ Ready\r\n")
            ->expect("Subject: x\r\n\r\n\r\n")
            ->reply("TAG1 OK [APPENDUID 1 2] done\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        static::assertTrue($imap->append('Sent', "Subject: x\r\n\r\n", ['\\Seen'], '01-Jan-2024 10:00:00 +0000'));
    }

    #[Test]
    public function appendsAMessageWithoutFlagsOrDate(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 APPEND \"Drafts\" \"hello\"\r\n")
            ->reply("TAG1 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->append('Drafts', 'hello'));
    }

    #[Test]
    public function failsWhenTheServerRefusesALiteral(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 APPEND \"Sent\" {7}\r\n")
            ->reply("TAG1 NO too big\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot send literal string');

        $imap->append('Sent', "a\r\nb\r\nc");
    }

    #[DataProvider('copyProvider')]
    #[Test]
    public function copiesMessages(string $request, int|string $from, int|float|null $to): void
    {
        $imap = self::imap($request, "TAG1 OK\r\n");

        static::assertTrue($imap->copy('Archive', $from, $to));
    }

    /**
     * @return array<string, array{string, int|string, int|float|null}>
     */
    public static function copyProvider(): array
    {
        return [
            'one message'    => ["TAG1 COPY 4 \"Archive\"\r\n", 4, null],
            'a range'        => ["TAG1 COPY 4:9 \"Archive\"\r\n", 4, 9],
            'to the last'    => ["TAG1 COPY 4:* \"Archive\"\r\n", 4, INF],
            'a sequence set' => ["TAG1 COPY 1,3:5,7:* \"Archive\"\r\n", '1,3:5,7:*', null],
        ];
    }

    #[DataProvider('folderCommandProvider')]
    #[Test]
    public function runsFolderCommands(string $method, string $request): void
    {
        $imap = self::imap($request, "TAG1 OK\r\n");

        static::assertTrue($imap->{$method}('Projects/2024'));
    }

    #[DataProvider('folderCommandProvider')]
    #[Test]
    public function reportsFailedFolderCommands(string $method, string $request): void
    {
        $imap = self::imap($request, "TAG1 NO\r\n");

        static::assertFalse($imap->{$method}('Projects/2024'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function folderCommandProvider(): array
    {
        return [
            'create'    => ['create', "TAG1 CREATE \"Projects/2024\"\r\n"],
            'delete'    => ['delete', "TAG1 DELETE \"Projects/2024\"\r\n"],
            'subscribe' => ['subscribe', "TAG1 SUBSCRIBE \"Projects/2024\"\r\n"],
        ];
    }

    #[Test]
    public function renamesAFolder(): void
    {
        $imap = self::imap("TAG1 RENAME \"Old\" \"New\"\r\n", "TAG1 OK\r\n");

        static::assertTrue($imap->rename('Old', 'New'));
    }

    #[Test]
    public function returnsTheUntaggedResponsesOfExpunge(): void
    {
        $imap = self::imap("TAG1 EXPUNGE\r\n", "* 3 EXPUNGE\r\nTAG1 OK\r\n");

        static::assertSame([['3', 'EXPUNGE']], $imap->expunge());
    }

    #[Test]
    public function reportsARejectedExpungeAsFailed(): void
    {
        $imap = self::imap("TAG1 EXPUNGE\r\n", "TAG1 BAD\r\n");

        static::assertFalse($imap->expunge());
    }

    #[Test]
    public function reportsANoopWithoutUpdates(): void
    {
        $imap = self::imap("TAG1 NOOP\r\n", "TAG1 OK\r\n");

        static::assertTrue($imap->noop());
    }

    #[Test]
    public function reportsARejectedNoopAsFailed(): void
    {
        $imap = self::imap("TAG1 NOOP\r\n", "TAG1 BAD\r\n");

        static::assertFalse($imap->noop());
    }

    #[Test]
    public function searches(): void
    {
        $imap = self::imap("TAG1 SEARCH UNSEEN\r\n", "* OK\r\n* SEARCH 2 84 882\r\nTAG1 OK\r\n");

        static::assertSame(['2', '84', '882'], $imap->search(['UNSEEN']));
    }

    #[DataProvider('emptySearchProvider')]
    #[Test]
    public function findsNothingWhenTheServerReportsNoMatches(string $response): void
    {
        $imap = self::imap("TAG1 SEARCH ALL\r\n", $response);

        static::assertSame([], $imap->search(['ALL']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptySearchProvider(): array
    {
        return [
            'no untagged response' => ["TAG1 OK\r\n"],
            'no SEARCH response'   => ["* 3 EXISTS\r\nTAG1 OK\r\n"],
            'empty SEARCH'         => ["* SEARCH\r\nTAG1 OK\r\n"],
        ];
    }

    #[DataProvider('failedSearchProvider')]
    #[Test]
    public function reportsAFailedSearch(string $response): void
    {
        $imap = self::imap("TAG1 SEARCH ALL\r\n", $response);

        static::assertFalse($imap->search(['ALL']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failedSearchProvider(): array
    {
        return [
            'NO'  => ["TAG1 NO\r\n"],
            'BAD' => ["TAG1 BAD\r\n"],
        ];
    }

    #[Test]
    public function searchesWithAnEscapedKeyword(): void
    {
        $imap = self::imap("TAG1 SEARCH KEYWORD \"my label\"\r\n", "* SEARCH 5\r\nTAG1 OK\r\n");

        static::assertSame(['5'], $imap->search(['KEYWORD', $imap->escapeString('my label')]));
    }

    #[Test]
    public function usesTheTagItIsGiven(): void
    {
        $server = ScriptedServer::imapGreeting()->expect("A001 NOOP\r\n")->hangUp();
        $imap   = ScriptedServer::imap($server);
        $tag    = 'A001';

        $imap->sendRequest('NOOP', [], $tag);

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function returnsTheGeneratedTag(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->expect("TAG1 NOOP\r\n")->hangUp());
        $tag  = '';

        $imap->sendRequest('NOOP', [], $tag);

        static::assertSame('TAG1', $tag);
    }

    #[Test]
    public function numbersTagsInOrder(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 NOOP\r\n")
            ->reply("TAG1 OK\r\n")
            ->expect("TAG2 NOOP\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->noop();
        $imap->noop();

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function returnsUnparsedLinesWhenAsked(): void
    {
        $imap = self::imap("TAG1 NOOP\r\n", "* 3 EXISTS\r\nTAG1 OK\r\n");

        static::assertSame(["3 EXISTS\r\n"], $imap->requestAndResponse('NOOP', [], true));
    }

    #[Test]
    public function readsTheStatusOfAnUnparsedTaggedLine(): void
    {
        $imap = self::imap("TAG1 NOOP\r\n", "TAG1 NO\r\n");

        static::assertFalse($imap->requestAndResponse('NOOP', [], true));
    }

    #[Test]
    public function readsAnEmptyTaggedLineAsABadResponse(): void
    {
        $imap = self::imap("TAG1 NOOP\r\n", "TAG1 \r\n");

        static::assertNull($imap->requestAndResponse('NOOP'));
    }

    #[Test]
    public function sendsALiteralAfterTheServerInvitesIt(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN {9}\r\n")
            ->reply("+\r\n")
            ->expect("us\r\ner\"\\x \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->login("us\r\ner\"\\x", 'secret'));
    }

    #[Test]
    public function escapesOneString(): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        static::assertSame('"a \\"quoted\\" \\\\ string"', $imap->escapeString('a "quoted" \\ string'));
    }

    #[Test]
    public function escapesSeveralStrings(): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        static::assertSame(['"foo"', '"bar"', ['{3}', "a\nb"]], $imap->escapeString('foo', 'bar', "a\nb"));
    }

    #[DataProvider('literalProvider')]
    #[Test]
    public function sendsStringsAQuotedStringCannotCarryAsLiterals(string $string): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        static::assertSame(['{' . strlen($string) . '}', $string], $imap->escapeString($string));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function literalProvider(): array
    {
        return [
            'line feed' => ["a\nb"],
            'bare CR'   => ["a\rb"],
            'CRLF'      => ["a\r\nTAG2 DELETE INBOX"],
            'UTF-8'     => ['Entwürfe'],
            'high byte' => ["\xFF"],
        ];
    }

    #[Test]
    public function escapesNestedLists(): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        static::assertSame('(a (b c) () 1)', $imap->escapeList(['a', ['b', 'c'], [], 1]));
    }

    #[Test]
    public function sendsAFolderNameWithALineBreakInModifiedUtf7(): void
    {
        $imap = self::imap("TAG1 SELECT \"a&AA0ACg-b\"\r\n", "* 1 EXISTS\r\nTAG1 OK\r\n");

        static::assertSame(['exists' => '1'], $imap->select("a\r\nb"));
    }

    #[Test]
    public function readsAResponseByItsTag(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()->expect("A7 NOOP\r\n")->reply("* 2 EXISTS\r\nA7 OK\r\n")->hangUp(),
        );
        $tag = 'A7';
        $imap->sendRequest('NOOP', [], $tag);

        static::assertSame([['2', 'EXISTS']], $imap->readResponse('A7'));
    }

    #[Test]
    public function readsTheFolderStatusInAnyOrder(): void
    {
        $imap = self::imap(
            "TAG1 SELECT \"INBOX\"\r\n",
            "* OK [UIDVALIDITY 7] valid\r\n* 4 EXISTS\r\nTAG1 OK\r\n",
        );

        static::assertSame(['uidvalidity' => 7, 'exists' => '4'], $imap->select());
    }

    #[Test]
    public function skipsMalformedMailboxLinesBeforeValidOnes(): void
    {
        $imap = self::imap(
            "TAG1 LIST \"\" \"*\"\r\n",
            "* LSUB () \"/\" Other\r\n* LIST () \"/\" INBOX\r\nTAG1 OK\r\n",
        );

        static::assertSame(['INBOX' => ['delim' => '/', 'flags' => []]], $imap->listMailbox());
    }

    #[Test]
    public function ignoresFlagListsOutsideFetchResponses(): void
    {
        $imap = self::imap(
            "TAG1 STORE 1 FLAGS (\\Seen)\r\n",
            "* 2 STORE (FLAGS (\\Draft))\r\n* 1 FETCH (FLAGS (\\Seen))\r\nTAG1 OK\r\n",
        );

        static::assertSame([1 => ['\\Seen']], $imap->store(['\\Seen'], 1, null, null, false));
    }

    /**
     * RFC 3501 section 6.2.3: a server that advertises LOGINDISABLED refuses LOGIN, so no password is sent.
     */
    #[Test]
    #[DataProvider('loginDisabledProvider')]
    public function refusesToSendPasswordWhenLoginIsDisabled(string $capabilities): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY {$capabilities}\r\nTAG1 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'The server does not allow LOGIN on this connection (LOGINDISABLED); connect with TLS or STARTTLS',
        );

        $imap->login('user', 'secret');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loginDisabledProvider(): array
    {
        return [
            'upper case' => ['IMAP4rev1 LOGINDISABLED'],
            'lower case' => ['imap4rev1 logindisabled'],
        ];
    }

    #[Test]
    public function readsCapabilitiesOnceForSeveralLogins(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"user\" \"wrong\"\r\n")
            ->reply("TAG2 NO invalid\r\n")
            ->expect("TAG3 LOGIN \"user\" \"secret\"\r\n")
            ->reply("TAG3 OK done\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('user', 'wrong');

        static::assertTrue($imap->login('user', 'secret'));
    }
}
