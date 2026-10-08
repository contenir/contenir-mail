<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Storage;
use Contenir\Mail\Tests\Integration\TestAsset\RecordingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_search;
use function bin2hex;
use function count;
use function getenv;
use function implode;
use function random_bytes;
use function str_contains;
use function str_starts_with;

/**
 * IMAP SCRAM-SHA-256 against Dovecot, which derives the SCRAM keys from the static passdb's password.
 */
#[CoversClass(Imap::class)]
#[CoversClass(Storage\Imap::class)]
#[Group('integration')]
final class ImapScramTest extends TestCase
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

    private static function password(): string
    {
        return (string) getenv('TESTS_CONTENIR_MAIL_IMAP_PASSWORD');
    }

    #[Test]
    public function opensTheMailboxWithScramSha256(): void
    {
        $mailbox = new Storage\Imap([
            'host' => self::host(),
            'auth' => ['type' => 'scram-sha-256', 'username' => 'test', 'password' => self::password()],
        ]);

        static::assertSame('INBOX', $mailbox->getCurrentFolder());
    }

    /**
     * Dovecot proves it knows the password in a continuation, which the client checks and answers
     * with an empty response before the tagged OK.
     */
    #[Test]
    public function checksTheServersProofBeforeSigningIn(): void
    {
        $connection = new RecordingConnection();
        $imap       = new Imap(connection: $connection);
        $imap->connect(new ConnectionConfig(self::host(), security: Security::StartTls));
        $imap->authenticate(new ScramSha256('test', self::password()));

        $transcript = $connection->transcript();
        $answer     = (int) array_search('C: ', $transcript, strict: true);
        $last       = $transcript[count($transcript) - 1] ?? '';
        static::assertSame(
            [true, true],
            [
                str_starts_with($transcript[$answer - 1] ?? '', 'S: + '),
                str_starts_with($last, 'S: TAG') && str_contains($last, ' OK '),
            ],
            implode("\n", $transcript),
        );
    }

    #[Test]
    public function refusesTheWrongPassword(): void
    {
        $imap = new Imap(new ConnectionConfig(self::host(), security: Security::StartTls));

        try {
            $imap->authenticate(new ScramSha256('test', bin2hex(random_bytes(8))));
            static::fail('The wrong password was accepted');
        } catch (ExceptionInterface $e) {
            static::assertStringContainsString('[AUTHENTICATIONFAILED]', $e->getMessage());
        }
    }
}
