<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Storage;

use Contenir\Mail\Protocol\Imap as ImapProtocol;
use Contenir\Mail\Storage\Idle\MessageCountChanged;
use Contenir\Mail\Storage\Imap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;

/**
 * IDLE (RFC 2177) against Dovecot: mail appended from a second connection is
 * reported as MessageCountChanged, and the mailbox is usable once the loop is left
 * (contenir/contenir-mail#52).
 *
 * The message is appended before the loop starts, as one process cannot append
 * while it waits: Dovecot reports changes since the last command when IDLE
 * starts, and within its mailbox_idle_check_interval otherwise.
 */
#[CoversClass(Imap::class)]
#[CoversClass(ImapProtocol::class)]
#[Group('integration')]
#[Group('slow')]
final class ImapIdleTest extends TestCase
{
    private ?Imap $listener = null;

    private ?Imap $writer = null;

    private int $appended = 0;

    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_ENABLED')) {
            static::markTestSkipped('Contenir_Mail IMAP tests are not enabled');
        }

        $this->listener = new Imap(self::settings());
        $this->writer   = new Imap(self::settings());
    }

    protected function tearDown(): void
    {
        if (0 !== $this->appended) {
            $this->writer?->removeMessage($this->appended);
        }

        $this->listener?->close();
        $this->writer?->close();
    }

    /**
     * @return array<string, string>
     */
    private static function settings(): array
    {
        return [
            'host'     => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_HOST'),
            'user'     => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_USER'),
            'password' => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_PASSWORD'),
        ];
    }

    #[Test]
    public function reportsMailAppendedByAnotherConnection(): void
    {
        $before = $this->listener?->countMessages() ?? 0;
        $this->writer?->appendMessage("Subject: Idle\r\n\r\nHello\r\n");
        $this->appended = $before + 1;

        $count = null;
        foreach ($this->listener?->idle(timeout: 40) ?? [] as $event) {
            if (! $event instanceof MessageCountChanged) {
                continue;
            }

            $count = $event->count;

            break;
        }

        static::assertSame(
            [$before + 1, 'Idle'],
            [$count, $this->listener?->getMessage($before + 1)->getSubject()],
        );
    }
}
