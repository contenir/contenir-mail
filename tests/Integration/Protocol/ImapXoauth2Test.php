<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage;
use Contenir\Mail\Tests\TestAsset\Protocol\RecordingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function count;
use function getenv;
use function implode;
use function random_bytes;
use function str_contains;
use function str_starts_with;

/**
 * IMAP XOAUTH2 against Dovecot, which checks the token as the user's secret.
 */
#[CoversClass(Imap::class)]
#[CoversClass(Storage\Imap::class)]
#[Group('integration')]
final class ImapXoauth2Test extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_ENABLED')) {
            static::markTestSkipped('Contenir_Mail IMAP tests are not enabled');
        }
    }

    private static function host(): string
    {
        return (string) getenv('TESTS_CONTENIR_MAIL_IMAP_HOST');
    }

    #[Test]
    public function opensTheMailboxWithAnAccessToken(): void
    {
        $mailbox = new Storage\Imap([
            'host' => self::host(),
            'auth' => ['username' => 'test', 'access_token' => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_PASSWORD')],
        ]);

        static::assertSame('INBOX', $mailbox->getCurrentFolder());
    }

    /**
     * Dovecot refuses with a challenge, which must be answered with an empty response
     * before the tagged NO; the transcript shows the exchange was finished.
     */
    #[Test]
    public function finishesTheExchangeWhenTheTokenIsRefused(): void
    {
        $connection = new RecordingConnection();
        $imap       = new Imap(connection: $connection);
        $imap->connect(new ConnectionConfig(self::host(), security: Security::StartTls));

        try {
            $imap->authenticate(new Xoauth2('test', bin2hex(random_bytes(8))));
            static::fail('The wrong token was accepted');
        } catch (ExceptionInterface $e) {
            $transcript = $connection->transcript();
            $last       = $transcript[count($transcript) - 1] ?? '';
            static::assertSame(
                [true, 'C: ', true],
                [
                    str_starts_with($e->getMessage(), 'The server refused the access token'),
                    $transcript[count($transcript) - 2] ?? '',
                    str_starts_with($last, 'S: TAG') && str_contains($last, ' NO '),
                ],
                implode("\n", $transcript),
            );
        }
    }
}
