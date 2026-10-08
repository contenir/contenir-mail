<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Storage;

use Contenir\Mail\Protocol\Imap as ImapProtocol;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\SpecialUse;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_sum;
use function getenv;

/**
 * Special-use folders, namespaces and folder status against Dovecot, whose
 * configuration marks "Trash" as \Trash (contenir/contenir-mail#52).
 */
#[CoversClass(Imap::class)]
#[CoversClass(ImapProtocol::class)]
#[Group('integration')]
final class ImapFolderMetadataTest extends TestCase
{
    private const string TRASH = 'Trash';

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

        if ($this->mailbox->getFolders()->hasFolder(self::TRASH)) {
            $this->mailbox->removeFolder(self::TRASH);
        }

        $this->mailbox->close();
    }

    private function mailbox(): Imap
    {
        return $this->mailbox ?? throw new LogicException('No mailbox');
    }

    #[Test]
    public function findsTheTrashFolderByItsSpecialUse(): void
    {
        if (! $this->mailbox()->getFolders()->hasFolder(self::TRASH)) {
            $this->mailbox()->createFolder(self::TRASH);
        }

        static::assertSame(self::TRASH, $this->mailbox()->getSpecialFolder(SpecialUse::Trash)?->getGlobalName());
    }

    #[Test]
    public function readsThePersonalNamespace(): void
    {
        $personal = $this->mailbox()->getNamespaces()?->personal[0] ?? null;

        static::assertSame(['', '/'], [$personal?->prefix, $personal?->delimiter]);
    }

    #[Test]
    public function readsTheStatusOfTheInbox(): void
    {
        static::assertSame($this->mailbox()->countMessages(), $this->mailbox()->getFolderStatus('INBOX')->messageCount);
    }

    #[Test]
    public function readsTheSizeOfTheInboxWhenTheServerCanSay(): void
    {
        $size = $this->mailbox()->getFolderSize('INBOX');
        if (null === $size) {
            static::markTestSkipped('The server offers neither STATUS=SIZE nor IMAP4rev2');
        }

        static::assertSame(array_sum($this->mailbox()->getSizes()), $size);
    }
}
