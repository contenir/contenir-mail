<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Imap\NamespaceEntry;
use Contenir\Mail\Imap\Namespaces;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\FolderStatus;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\ImapFolderTree;
use Contenir\Mail\Storage\SpecialUse;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\TestAsset\Protocol\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Describing folders: their special use (RFC 6154), the server's namespaces
 * (RFC 2342), and their status and size without selecting them (RFC 8438)
 * (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[CoversClass(ImapFolderTree::class)]
#[CoversClass(Folder::class)]
#[CoversClass(FolderStatus::class)]
#[CoversClass(SpecialUse::class)]
#[Group('unit')]
final class ImapFolderMetadataTest extends TestCase
{
    private const string LIST =
        "* LIST (\\HasNoChildren) \"/\" INBOX\r\n"
            . "* LIST (\\HasNoChildren \\sent) \"/\" \"Sent Items\"\r\n"
            . "* LIST (\\HasChildren \\Trash) \"/\" Trash\r\n"
            . "* LIST (\\HasNoChildren) \"/\" Trash/Old\r\n"
            . "* LIST (\\HasNoChildren \\Junk) \"/\" Junk\r\n"
            . "* LIST (\\HasNoChildren \\Junk) \"/\" Spam\r\n"
            . "* LIST (\\HasNoChildren \\Archive) \"/\" Archive/2024\r\n";

    /**
     * A server with INBOX selected; the next command is tagged TAG2.
     */
    private static function server(): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("* 3 EXISTS\r\nTAG1 OK [READ-WRITE]\r\n");
    }

    /**
     * The same server, asked for its capabilities; the next command is tagged TAG3.
     */
    private static function serverWith(string $capabilities): InMemoryConnection
    {
        return self::server()
            ->expect("TAG2 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 {$capabilities}\r\nTAG2 OK\r\n");
    }

    /**
     * A server listing the folders above; the next command is tagged TAG3.
     */
    private static function listingServer(): InMemoryConnection
    {
        return self::server()
            ->expect("TAG2 LIST \"\" \"*\"\r\n")
            ->reply(self::LIST . "TAG2 OK\r\n")
            ->hangUp();
    }

    private static function mailbox(InMemoryConnection $server): Imap
    {
        return new Imap(ScriptedServer::imap($server));
    }

    /**
     * @param non-empty-string $globalName
     */
    #[Test]
    #[DataProvider('specialFolderProvider')]
    public function findsAFolderByItsSpecialUse(SpecialUse $use, string $globalName): void
    {
        static::assertSame(
            $globalName,
            self::mailbox(self::listingServer())->getSpecialFolder($use)?->getGlobalName(),
        );
    }

    /**
     * @return array<string, array{SpecialUse, string}>
     */
    public static function specialFolderProvider(): array
    {
        return [
            'the sent folder, marked in lower case' => [SpecialUse::Sent, 'Sent Items'],
            'a folder with subfolders'              => [SpecialUse::Trash, 'Trash'],
            'a subfolder'                           => [SpecialUse::Archive, 'Archive/2024'],
            'the first of two'                      => [SpecialUse::Junk, 'Junk'],
        ];
    }

    #[Test]
    public function findsNoFolderWhenNoneHasTheUse(): void
    {
        static::assertNull(self::mailbox(self::listingServer())->getSpecialFolder(SpecialUse::Drafts));
    }

    #[Test]
    public function marksOnlyTheListedFolderWithItsUse(): void
    {
        $folders = self::mailbox(self::listingServer())->getFolders();

        static::assertSame(
            [null, SpecialUse::Archive, null, SpecialUse::Trash],
            [
                $folders->getFolder('Archive')->getSpecialUse(),
                $folders->getFolder('Archive')->getFolder('2024')->getSpecialUse(),
                $folders->getFolder('Trash')->getFolder('Old')->getSpecialUse(),
                $folders->getFolder('Trash')->getSpecialUse(),
            ],
        );
    }

    #[Test]
    public function keepsAnImpliedParentUnselectable(): void
    {
        $folders = self::mailbox(self::listingServer())->getFolders();

        static::assertSame(
            [false, true],
            [$folders->getFolder('Archive')->isSelectable(), $folders->getFolder('Trash')->isSelectable()],
        );
    }

    #[Test]
    public function readsTheServersNamespaces(): void
    {
        $server = self::serverWith('NAMESPACE')
            ->expect("TAG3 NAMESPACE\r\n")
            ->reply("* NAMESPACE ((\"\" \"/\")) NIL ((\"#shared/\" \"/\"))\r\nTAG3 OK\r\n")
            ->hangUp();

        static::assertEquals(
            new Namespaces(
                personal: [new NamespaceEntry('', '/')],
                otherUsers: [],
                shared: [new NamespaceEntry('#shared/', '/')],
            ),
            self::mailbox($server)->getNamespaces(),
        );
    }

    #[Test]
    public function readsTheSizeOfAFolderWhenTheServerOffersStatusSize(): void
    {
        $server = self::serverWith('STATUS=SIZE')
            ->expect("TAG3 STATUS \"Sent Items\" (SIZE)\r\n")
            ->reply("* STATUS \"Sent Items\" (SIZE 123456)\r\nTAG3 OK\r\n")
            ->hangUp();

        static::assertSame(123_456, self::mailbox($server)->getFolderSize(new Folder('Sent Items')));
    }

    #[Test]
    public function readsTheSizeOfAFolderWhenImap4Rev2IsEnabled(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 SELECT \"INBOX\"\r\n")
            ->reply("TAG4 OK\r\n")
            ->expect("TAG5 STATUS \"Archive\" (SIZE)\r\n")
            ->reply("* STATUS \"Archive\" (SIZE 42)\r\nTAG5 OK\r\n")
            ->hangUp();
        $protocol = ScriptedServer::imap($server);
        $protocol->login('jo', 'secret');

        static::assertSame(42, (new Imap($protocol))->getFolderSize('Archive'));
    }

    #[Test]
    public function hasNoFolderSizeWhenTheServerCannotSay(): void
    {
        $server = self::serverWith('SORT')->hangUp();

        static::assertNull(self::mailbox($server)->getFolderSize('Archive'));
        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function readsTheStatusOfAFolderWithItsSize(): void
    {
        $server = self::serverWith('STATUS=SIZE')
            ->expect("TAG3 STATUS \"Archive\" (MESSAGES UNSEEN UIDNEXT UIDVALIDITY SIZE)\r\n")
            ->reply("* STATUS \"Archive\" (MESSAGES 10 UNSEEN 2 UIDNEXT 31 UIDVALIDITY 38505 SIZE 2048)\r\nTAG3 OK\r\n")
            ->hangUp();

        static::assertEquals(
            new FolderStatus(
                messageCount: 10,
                unseenCount: 2,
                uidNext: 31,
                uidValidity: 38_505,
                size: 2048,
            ),
            self::mailbox($server)->getFolderStatus('Archive'),
        );
    }

    #[Test]
    public function readsTheStatusOfAFolderWithoutItsSize(): void
    {
        $server = self::serverWith('SORT')
            ->expect("TAG3 STATUS \"Archive\" (MESSAGES UNSEEN UIDNEXT UIDVALIDITY)\r\n")
            ->reply("* STATUS \"Archive\" (MESSAGES 10 UNSEEN 2 UIDNEXT 31 UIDVALIDITY 38505)\r\nTAG3 OK\r\n")
            ->hangUp();

        static::assertEquals(
            new FolderStatus(
                messageCount: 10,
                unseenCount: 2,
                uidNext: 31,
                uidValidity: 38_505,
                size: null,
            ),
            self::mailbox($server)->getFolderStatus(new Folder('Archive')),
        );
    }

    #[Test]
    public function reportsARefusedStatus(): void
    {
        $server = self::serverWith('STATUS=SIZE')
            ->expect("TAG3 STATUS \"Gone\" (SIZE)\r\n")
            ->reply("TAG3 NO [NONEXISTENT] No such mailbox\r\n")
            ->hangUp();
        $mailbox = self::mailbox($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read the status of the folder; it may not exist');

        $mailbox->getFolderSize('Gone');
    }

    #[Test]
    #[DataProvider('incompleteStatusProvider')]
    public function reportsAnItemTheServerLeftOut(string $values, string $missing): void
    {
        $server = self::serverWith('STATUS=SIZE')
            ->expect("TAG3 STATUS \"Archive\" (MESSAGES UNSEEN UIDNEXT UIDVALIDITY SIZE)\r\n")
            ->reply("* STATUS \"Archive\" ({$values})\r\nTAG3 OK\r\n")
            ->hangUp();
        $mailbox = self::mailbox($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server sent no {$missing} in the folder status");

        $mailbox->getFolderStatus('Archive');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function incompleteStatusProvider(): array
    {
        return [
            'messages'    => ['UNSEEN 2 UIDNEXT 31 UIDVALIDITY 7 SIZE 2048', 'MESSAGES'],
            'unseen'      => ['MESSAGES 10 UIDNEXT 31 UIDVALIDITY 7 SIZE 2048', 'UNSEEN'],
            'uidnext'     => ['MESSAGES 10 UNSEEN 2 UIDVALIDITY 7 SIZE 2048', 'UIDNEXT'],
            'uidvalidity' => ['MESSAGES 10 UNSEEN 2 UIDNEXT 31 SIZE 2048', 'UIDVALIDITY'],
            'size'        => ['MESSAGES 10 UNSEEN 2 UIDNEXT 31 UIDVALIDITY 7', 'SIZE'],
        ];
    }

    #[Test]
    public function refusesAFolderNameWithALineBreak(): void
    {
        $mailbox = self::mailbox(self::serverWith('STATUS=SIZE')->hangUp());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A folder name may not be empty or hold a line break or NUL');

        $mailbox->getFolderSize("Archive\r\nTAG9 DELETE INBOX");
    }
}
