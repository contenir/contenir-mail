<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\CommandRefusedException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Imap\UidMapping;
use Contenir\Mail\Protocol\Imap\UidSet;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The UIDs of appended, copied and moved messages (RFC 4315, UIDPLUS), and leaving a
 * folder without expunging it (RFC 3691, UNSELECT) (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[CoversClass(UidMapping::class)]
#[CoversClass(UidSet::class)]
#[Group('unit')]
final class ReturningUidsTest extends TestCase
{
    /**
     * A client whose first command must be $request, answered with $response.
     */
    private static function imap(string $request, string $response): Imap
    {
        return ScriptedServer::imap(ScriptedServer::imapGreeting()->expect($request)->reply($response)->hangUp());
    }

    /**
     * A client of a server offering MOVE, whose second command must be $request, answered with $response.
     */
    private static function imapOfferingMove(string $request, string $response): Imap
    {
        return ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 CAPABILITY\r\n")
                ->reply("* CAPABILITY IMAP4rev1 MOVE\r\nTAG1 OK\r\n")
                ->expect($request)
                ->reply($response)
                ->hangUp(),
        );
    }

    #[Test]
    public function returnsTheUidTheServerGaveTheAppendedMessage(): void
    {
        $imap = self::imap(
            "TAG1 APPEND \"Sent\" (\\Seen) \"x\"\r\n",
            "* 4 EXISTS\r\nTAG1 OK [APPENDUID 38505 3955] APPEND completed\r\n",
        );

        static::assertEquals(new UidMapping(38_505, [], [3955]), $imap->appendReturningUids('Sent', 'x', ['\\Seen']));
    }

    #[Test]
    public function returnsTheUidsTheServerGaveTheCopies(): void
    {
        $imap = self::imap(
            "TAG1 COPY 2:4 \"Archive\"\r\n",
            "TAG1 OK [COPYUID 38505 304,319:320 3956:3958] Done\r\n",
        );

        static::assertEquals(
            new UidMapping(38_505, [304, 319, 320], [3956, 3957, 3958]),
            $imap->copyReturningUids('Archive', 2, 4),
        );
    }

    /**
     * MOVE sends COPYUID in an untagged OK before the EXPUNGE responses (RFC 6851, section 4.3).
     */
    #[Test]
    public function returnsTheUidsTheServerGaveTheMovedMessages(): void
    {
        $imap = self::imapOfferingMove(
            "TAG2 MOVE 2:3 \"Archive\"\r\n",
            "* OK [COPYUID 38505 304:305 3956:3957] Moved\r\n* 2 EXPUNGE\r\n* 2 EXPUNGE\r\nTAG2 OK Done\r\n",
        );

        static::assertEquals(
            new UidMapping(38_505, [304, 305], [3956, 3957]),
            $imap->moveReturningUids('Archive', 2, 3),
        );
    }

    #[Test]
    public function keepsTheFirstUidsTheServerReports(): void
    {
        $imap = self::imapOfferingMove(
            "TAG2 MOVE 2 \"Archive\"\r\n",
            "* OK [COPYUID 1 304 3956] Moved\r\n* OK [COPYUID 2 305 3957] Again\r\n* 2 EXPUNGE\r\nTAG2 OK Done\r\n",
        );

        static::assertSame(3956, $imap->moveReturningUids('Archive', 2)?->uid());
    }

    #[Test]
    public function readsTheUidsOfAMoveFromItsTaggedReply(): void
    {
        $imap = self::imapOfferingMove("TAG2 MOVE 2 \"Archive\"\r\n", "TAG2 OK [COPYUID 7 304 3956] Done\r\n");

        static::assertSame(3956, $imap->moveReturningUids('Archive', 2)?->uid());
    }

    #[Test]
    public function refusesToMoveWhenTheServerDoesNotOfferIt(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer MOVE');

        ScriptedServer::imap($server)->moveReturningUids('Archive', 2);
    }

    /**
     * @param 'appendReturningUids'|'copyReturningUids' $method
     * @param list<mixed> $arguments
     */
    #[DataProvider('withoutUidsProvider')]
    #[Test]
    public function returnsNoUidsWhenTheServerSendsNone(
        string $method,
        array $arguments,
        string $request,
        string $reply,
    ): void {
        static::assertNull(self::imap($request, $reply)->{$method}(...$arguments));
    }

    /**
     * @return array<string, array{string, list<mixed>, string, string}>
     */
    public static function withoutUidsProvider(): array
    {
        return [
            'append without a code'                => [
                'appendReturningUids',
                ['Sent', 'x'],
                "TAG1 APPEND \"Sent\" \"x\"\r\n",
                "TAG1 OK done\r\n",
            ],
            'append with a bad code'               => [
                'appendReturningUids',
                ['Sent', 'x'],
                "TAG1 APPEND \"Sent\" \"x\"\r\n",
                "TAG1 OK [APPENDUID 0 1] done\r\n",
            ],
            'copy without a code'                  => [
                'copyReturningUids',
                ['Archive', 2],
                "TAG1 COPY 2 \"Archive\"\r\n",
                "TAG1 OK\r\n",
            ],
            'copy with another code'               => [
                'copyReturningUids',
                ['Archive', 2],
                "TAG1 COPY 2 \"Archive\"\r\n",
                "* OK [UIDNEXT 9]\r\nTAG1 OK [READ-WRITE]\r\n",
            ],
            'copy with a code in another response' => [
                'copyReturningUids',
                ['Archive', 2],
                "TAG1 COPY 2 \"Archive\"\r\n",
                "* NO [COPYUID 1 2 3] not this\r\nTAG1 OK\r\n",
            ],
        ];
    }

    /**
     * @param 'append'|'copy' $method
     * @param list<mixed> $arguments
     */
    #[DataProvider('succeededProvider')]
    #[Test]
    public function reportsSuccess(string $method, array $arguments, string $request, string $reply): void
    {
        static::assertTrue(self::imap($request, $reply)->{$method}(...$arguments));
    }

    /**
     * @return array<string, array{string, list<mixed>, string, string}>
     */
    public static function succeededProvider(): array
    {
        return [
            'append' => ['append', ['Sent', 'x'], "TAG1 APPEND \"Sent\" \"x\"\r\n", "TAG1 OK done\r\n"],
            'copy'   => ['copy', ['Archive', 2], "TAG1 COPY 2 \"Archive\"\r\n", "TAG1 OK [COPYUID 1 2 3] done\r\n"],
        ];
    }

    #[Test]
    public function reportsASuccessfulMove(): void
    {
        $imap = self::imapOfferingMove("TAG2 MOVE 2 \"Archive\"\r\n", "* 2 EXPUNGE\r\nTAG2 OK Done\r\n");

        static::assertTrue($imap->move('Archive', 2));
    }

    /**
     * @param 'append'|'copy' $method
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
            'append refused'      => ['append', ['Sent', 'x'], $append, "TAG1 NO [TRYCREATE] No such folder\r\n"],
            'append with a code'  => ['append', ['Sent', 'x'], $append, "TAG1 NO [APPENDUID 1 2] no\r\n"],
            'append bad'          => ['append', ['Sent', 'x'], $append, "TAG1 BAD\r\n"],
            'copy refused'        => ['copy', ['Archive', 2], $copy, "TAG1 NO\r\n"],
            'copy after its code' => ['copy', ['Archive', 2], $copy, "* OK [COPYUID 1 2 3] x\r\nTAG1 NO no\r\n"],
        ];
    }

    #[Test]
    public function reportsARefusedMove(): void
    {
        $imap = self::imapOfferingMove("TAG2 MOVE 2 \"Archive\"\r\n", "TAG2 NO [TRYCREATE] No such folder\r\n");

        static::assertFalse($imap->move('Archive', 2));
    }

    /**
     * @param 'appendReturningUids'|'copyReturningUids' $method
     * @param list<mixed> $arguments
     */
    #[DataProvider('refusedReturningUidsProvider')]
    #[Test]
    public function throwsWhenTheServerRefusesToReturnUids(
        string $method,
        array $arguments,
        string $request,
        string $reply,
        string $message,
    ): void {
        $imap = self::imap($request, $reply);

        $this->expectException(CommandRefusedException::class);
        $this->expectExceptionMessage($message);

        $imap->{$method}(...$arguments);
    }

    /**
     * @return array<string, array{string, list<mixed>, string, string, string}>
     */
    public static function refusedReturningUidsProvider(): array
    {
        $append = "TAG1 APPEND \"Sent\" \"x\"\r\n";
        $copy   = "TAG1 COPY 2 \"Archive\"\r\n";

        return [
            'append refused' => [
                'appendReturningUids',
                ['Sent', 'x'],
                $append,
                "TAG1 NO [APPENDUID 1 2] no\r\n",
                'The server refused APPEND',
            ],
            'append bad'     => [
                'appendReturningUids',
                ['Sent', 'x'],
                $append,
                "TAG1 BAD\r\n",
                'The server refused APPEND',
            ],
            'copy refused'   => [
                'copyReturningUids',
                ['Archive', 2],
                $copy,
                "* OK [COPYUID 1 2 3] x\r\nTAG1 NO no\r\n",
                'The server refused COPY',
            ],
        ];
    }

    #[Test]
    public function throwsWhenTheServerRefusesTheMove(): void
    {
        $imap = self::imapOfferingMove("TAG2 MOVE 2 \"Archive\"\r\n", "TAG2 NO [TRYCREATE] No such folder\r\n");

        $this->expectException(CommandRefusedException::class);
        $this->expectExceptionMessage('The server refused MOVE');

        $imap->moveReturningUids('Archive', 2);
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

        static::assertSame([9, true], [$imap->copyReturningUids('Archive', 2)?->uid(), $imap->noop()]);
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

    /**
     * Servers advertise more once signed in: Dovecot lists UNSELECT, MOVE and SORT only after LOGIN.
     */
    #[Test]
    public function readsTheCapabilitiesAgainAfterLogin(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK Logged in\r\n")
            ->expect("TAG3 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 UNSELECT\r\nTAG3 OK\r\n")
            ->expect("TAG4 UNSELECT\r\n")
            ->reply("TAG4 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        static::assertSame([true, true], [$imap->unselect(), $server->isScriptComplete()]);
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
