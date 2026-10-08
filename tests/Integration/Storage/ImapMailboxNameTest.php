<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Storage;

use Contenir\Mail\Protocol\Imap as ImapProtocol;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\Imap;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_map;
use function getenv;
use function in_array;
use function iterator_to_array;

/**
 * Folder names outside ASCII, and with "&", against Dovecot: written in
 * modified UTF-7 to an IMAP4rev1 server, or as UTF-8 when it enables
 * IMAP4rev2 or UTF8=ACCEPT (contenir/contenir-mail#14).
 */
#[CoversClass(Imap::class)]
#[CoversClass(ImapProtocol::class)]
#[Group('integration')]
final class ImapMailboxNameTest extends TestCase
{
    private const string NAME = 'Entwürfe & R&D 日本語';

    private ?Imap $mailbox = null;

    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_ENABLED')) {
            static::markTestSkipped('Contenir_Mail IMAP tests are not enabled');
        }

        $this->mailbox = new Imap([
            'host'     => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_HOST'),
            'user'     => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_USER'),
            'password' => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_PASSWORD'),
        ]);
    }

    protected function tearDown(): void
    {
        if (null === $this->mailbox) {
            return;
        }

        $names = $this->folderNames();
        if (in_array(self::NAME, $names, strict: true)) {
            $this->mailbox->removeFolder(self::NAME);
        }

        $this->mailbox->close();
    }

    /**
     * @return list<string>
     */
    private function folderNames(): array
    {
        $tree = new RecursiveIteratorIterator(
            $this->mailbox?->getFolders() ?? throw new LogicException('No mailbox'),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        return array_map(
            static fn(Folder $folder): string => $folder->getGlobalName(),
            iterator_to_array($tree, preserve_keys: false),
        );
    }

    #[Test]
    public function createsListsAndSelectsAFolderWithAnInternationalName(): void
    {
        $this->mailbox?->createFolder(self::NAME);
        $this->mailbox?->selectFolder(self::NAME);

        static::assertSame(
            [true, self::NAME, 0],
            [
                in_array(self::NAME, $this->folderNames(), strict: true),
                $this->mailbox?->getCurrentFolder(),
                $this->mailbox?->countMessages(),
            ],
        );
    }
}
