<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Imap\UidPlus;
use Contenir\Mail\Protocol\Imap\UidSet;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The UIDs of appended and copied messages (RFC 4315, UIDPLUS), and leaving a
 * folder without expunging it (RFC 3691, UNSELECT) (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[CoversClass(UidPlus::class)]
#[CoversClass(UidSet::class)]
#[Group('unit')]
final class UidPlusCommandTest extends TestCase
{
    /**
     * A client whose first command must be $request, answered with $response.
     */
    private static function imap(string $request, string $response): Imap
    {
        return ScriptedServer::imap(ScriptedServer::imapGreeting()->expect($request)->reply($response)->hangUp());
    }

    #[Test]
    public function returnsTheUidTheServerGaveTheAppendedMessage(): void
    {
        $imap = self::imap(
            "TAG1 APPEND \"Sent\" (\\Seen) \"x\"\r\n",
            "* 4 EXISTS\r\nTAG1 OK [APPENDUID 38505 3955] APPEND completed\r\n",
        );

        static::assertEquals(new UidPlus(38_505, [], [3955]), $imap->appendWithUid('Sent', 'x', ['\\Seen']));
    }

    #[Test]
    public function returnsTheUidsTheServerGaveTheCopies(): void
    {
        $imap = self::imap(
            "TAG1 COPY 2:4 \"Archive\"\r\n",
            "TAG1 OK [COPYUID 38505 304,319:320 3956:3958] Done\r\n",
        );

        static::assertEquals(
            new UidPlus(38_505, [304, 319, 320], [3956, 3957, 3958]),
            $imap->copyWithUid('Archive', 2, 4),
        );
    }

    /**
     * @param 'appendWithUid'|'copyWithUid' $method
     * @param list<mixed> $arguments
     */
    #[DataProvider('withoutUidsProvider')]
    #[Test]
    public function reportsSuccessWithoutUidsWhenTheServerSendsNone(
        string $method,
        array $arguments,
        string $request,
        string $reply,
    ): void {
        static::assertTrue(self::imap($request, $reply)->{$method}(...$arguments));
    }

    /**
     * @return array<string, array{string, list<mixed>, string, string}>
     */
    public static function withoutUidsProvider(): array
    {
        return [
            'append without a code'  => [
                'appendWithUid',
                ['Sent', 'x'],
                "TAG1 APPEND \"Sent\" \"x\"\r\n",
                "TAG1 OK done\r\n",
            ],
            'append with a bad code' => [
                'appendWithUid',
                ['Sent', 'x'],
                "TAG1 APPEND \"Sent\" \"x\"\r\n",
                "TAG1 OK [APPENDUID 0 1] done\r\n",
            ],
            'copy without a code'    => ['copyWithUid', ['Archive', 2], "TAG1 COPY 2 \"Archive\"\r\n", "TAG1 OK\r\n"],
            'copy with another code' => [
                'copyWithUid',
                ['Archive', 2],
                "TAG1 COPY 2 \"Archive\"\r\n",
                "* OK [UIDNEXT 9]\r\nTAG1 OK [READ-WRITE]\r\n",
            ],
        ];
    }

    /**
     * @param 'append'|'appendWithUid'|'copy'|'copyWithUid' $method
     * @param list<mixed> $arguments
     */
    #[DataProvider('refusedProvider')]
    #[Test]
    public function reportsARefusal(string $method, array $arguments, string $request, string $reply): void
    {
        static::assertFalse(self::imap($request, $reply)->{$method}(...$arguments));
    }

    /**
     * @return array<string, array{string, list<mixed>, string, string}>
     */
    public static function refusedProvider(): array
    {
        $append = "TAG1 APPEND \"Sent\" \"x\"\r\n";
        $copy   = "TAG1 COPY 2 \"Archive\"\r\n";

        return [
            'append refused'          => ['append', ['Sent', 'x'], $append, "TAG1 NO [TRYCREATE] No such folder\r\n"],
            'append with UID refused' => ['appendWithUid', ['Sent', 'x'], $append, "TAG1 NO [APPENDUID 1 2] no\r\n"],
            'append with UID bad'     => ['appendWithUid', ['Sent', 'x'], $append, "TAG1 BAD\r\n"],
            'copy refused'            => ['copy', ['Archive', 2], $copy, "TAG1 NO\r\n"],
            'copy with UID refused'   => ['copyWithUid', ['Archive', 2], $copy, "TAG1 NO [COPYUID 1 2 3] no\r\n"],
        ];
    }

    #[Test]
    public function readsTheWholeResponseBeforeTheNextCommand(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 COPY 2 \"Archive\"\r\n")
            ->reply("* 3 FETCH (FLAGS (\\Seen))\r\nTAG1 OK [COPYUID 5 2 9] Done\r\n")
            ->expect("TAG2 NOOP\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        static::assertSame([9, true], [$imap->copyWithUid('Archive', 2)?->uid(), $imap->noop()]);
    }

    #[Test]
    public function unselectsWhenTheServerOffersUnselect(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 UNSELECT\r\nTAG1 OK\r\n")
            ->expect("TAG2 UNSELECT\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->unselect());
    }

    #[Test]
    public function unselectsWithImap4Rev2Enabled(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 UNSELECT\r\n")
            ->reply("TAG4 NO No folder is selected\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        static::assertFalse($imap->unselect());
    }

    #[Test]
    public function refusesToUnselectWhenTheServerDoesNotOfferIt(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 MOVE\r\nTAG1 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer UNSELECT');

        $imap->unselect();
    }
}
