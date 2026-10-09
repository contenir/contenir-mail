<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const INF;

#[CoversClass(Imap::class)]
#[Group('unit')]
final class FetchTest extends TestCase
{
    private static function imap(string $request, string $response): Imap
    {
        return ScriptedServer::imap(ScriptedServer::imapGreeting()->expect($request)->reply($response)->hangUp());
    }

    #[Test]
    public function fetchesOneItemOfOneMessage(): void
    {
        $imap = self::imap("TAG1 FETCH 1 (RFC822.SIZE)\r\n", "* 1 FETCH (RFC822.SIZE 4423)\r\nTAG1 OK\r\n");

        static::assertSame('4423', $imap->fetch('RFC822.SIZE', 1));
    }

    #[Test]
    public function ignoresOtherMessagesAndResponsesWhenFetchingOne(): void
    {
        $imap = self::imap(
            "TAG1 FETCH 2 (RFC822.SIZE)\r\n",
            "* 3 EXISTS\r\n* 1 FETCH (RFC822.SIZE 10)\r\n* 2 FETCH garbage\r\n* 2\r\n"
                . "* 2 FETCH (RFC822.SIZE 20)\r\n* 4 FETCH (FLAGS ())\r\nTAG1 OK\r\n",
        );

        static::assertSame('20', $imap->fetch('RFC822.SIZE', 2));
    }

    #[Test]
    public function readsTheWholeResponseBeforeReturningOneMessage(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 FETCH 1 (UID)\r\n")
            ->reply("* 1 FETCH (UID 5)\r\n* 1 FETCH (FLAGS ())\r\nTAG1 OK\r\n")
            ->expect("TAG2 NOOP\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->fetch('UID', 1);

        static::assertTrue($imap->noop());
    }

    #[Test]
    public function readsLiteralsWhileSkippingTheRestOfTheResponse(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 FETCH 1 (UID)\r\n")
            ->reply("* 1 FETCH (UID 5)\r\n* 2 FETCH (BODY {9}\r\nTAG1 OK\r\n)\r\nTAG1 OK\r\n")
            ->expect("TAG2 NOOP\r\n")
            ->reply("TAG2 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->fetch('UID', 1);

        static::assertTrue($imap->noop());
    }

    #[Test]
    public function findsTheWantedItemAmongOthers(): void
    {
        $imap = self::imap(
            "TAG1 FETCH 1 (RFC822.SIZE)\r\n",
            "* 1 FETCH (UID 5 FLAGS () RFC822.SIZE 10)\r\nTAG1 OK\r\n",
        );

        static::assertSame('10', $imap->fetch('RFC822.SIZE', 1));
    }

    #[Test]
    public function returnsNullWhenTheWantedItemIsMissing(): void
    {
        $imap = self::imap("TAG1 FETCH 1 (RFC822.SIZE)\r\n", "* 1 FETCH (UID 5)\r\nTAG1 OK\r\n");

        static::assertNull($imap->fetch('RFC822.SIZE', 1));
    }

    #[Test]
    public function returnsNullWhenTheWantedItemHasNoValue(): void
    {
        $imap = self::imap("TAG1 FETCH 1 (RFC822.SIZE)\r\n", "* 1 FETCH (RFC822.SIZE)\r\nTAG1 OK\r\n");

        static::assertNull($imap->fetch('RFC822.SIZE', 1));
    }

    #[Test]
    public function fetchesSeveralItemsOfOneMessage(): void
    {
        $imap = self::imap(
            "TAG1 FETCH 1 (FLAGS RFC822.HEADER)\r\n",
            "* 1 FETCH (FLAGS (\\Seen) RFC822.HEADER {12}\r\nSubject: x\r\n)\r\nTAG1 OK\r\n",
        );

        static::assertSame(
            ['FLAGS' => ['\\Seen'], 'RFC822.HEADER' => "Subject: x\r\n"],
            $imap->fetch(['FLAGS', 'RFC822.HEADER'], 1),
        );
    }

    #[Test]
    public function skipsItemNamesThatAreNotStrings(): void
    {
        $imap = self::imap("TAG1 FETCH 1 (FLAGS UID)\r\n", "* 1 FETCH ((odd) 1 UID 5 FLAGS)\r\nTAG1 OK\r\n");

        static::assertSame(['UID' => '5', 'FLAGS' => null], $imap->fetch(['FLAGS', 'UID'], 1));
    }

    #[Test]
    public function fetchesOneItemOfARange(): void
    {
        $imap = self::imap(
            "TAG1 FETCH 1:2 (RFC822.SIZE)\r\n",
            "* 1 FETCH (RFC822.SIZE 10)\r\n* 2 FETCH (RFC822.SIZE 20)\r\nTAG1 OK\r\n",
        );

        static::assertSame([1 => '10', 2 => '20'], $imap->fetch('RFC822.SIZE', 1, 2));
    }

    #[Test]
    public function fetchesToTheLastMessage(): void
    {
        $imap = self::imap("TAG1 FETCH 3:* (UID)\r\n", "* 3 FETCH (UID 30)\r\nTAG1 OK\r\n");

        static::assertSame([3 => '30'], $imap->fetch('UID', 3, INF));
    }

    #[Test]
    public function fetchesAListOfMessages(): void
    {
        $imap = self::imap(
            "TAG1 FETCH 1,3:4 (UID FLAGS)\r\n",
            "* 1 FETCH (UID 10 FLAGS ())\r\n* 3 FETCH (UID 30 FLAGS ())\r\nTAG1 OK\r\n",
        );

        static::assertSame(
            [1 => ['UID' => '10', 'FLAGS' => []], 3 => ['UID' => '30', 'FLAGS' => []]],
            $imap->fetch(['UID', 'FLAGS'], [1, '3:4']),
        );
    }

    #[Test]
    public function fetchesNothingFromAnEmptyRange(): void
    {
        $imap = self::imap("TAG1 FETCH 1:* (UID)\r\n", "TAG1 OK\r\n");

        static::assertSame([], $imap->fetch('UID', 1, INF));
    }

    #[Test]
    public function fetchesByUidWithTheUidLast(): void
    {
        $imap = self::imap(
            "TAG1 UID FETCH 7 (FLAGS)\r\n",
            "* 3 FETCH (FLAGS (\\Seen) UID 6)\r\n* 4 FETCH (FLAGS (\\Draft) UID 7)\r\nTAG1 OK\r\n",
        );

        static::assertSame(['\\Draft'], $imap->fetch('FLAGS', 7, null, true));
    }

    #[Test]
    public function fetchesByUidWithTheUidFirst(): void
    {
        $imap = self::imap(
            "TAG1 UID FETCH 7 (FLAGS)\r\n",
            "* 4 FETCH (UID 7 FLAGS (\\Draft))\r\nTAG1 OK\r\n",
        );

        static::assertSame(['\\Draft'], $imap->fetch('FLAGS', 7, null, true));
    }

    #[Test]
    public function ignoresAFetchByUidWithoutTheUid(): void
    {
        $imap = self::imap("TAG1 UID FETCH 7 (FLAGS)\r\n", "* 7 FETCH (FLAGS (\\Draft))\r\nTAG1 OK\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the single id was not found in response');

        $imap->fetch('FLAGS', 7, null, true);
    }

    #[Test]
    public function failsWhenTheSingleMessageIsNotInTheResponse(): void
    {
        $imap = self::imap("TAG1 FETCH 99 (UID)\r\n", "TAG1 OK\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the single id was not found in response');

        $imap->fetch('UID', 99);
    }
}
