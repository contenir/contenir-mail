<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage;
use Contenir\Mail\Tests\Integration\TestAsset\RecordingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function count;
use function getenv;
use function implode;
use function random_bytes;
use function str_starts_with;

/**
 * POP3 SCRAM-SHA-256 against Dovecot, which derives the SCRAM keys from the static passdb's password.
 */
#[CoversClass(Pop3::class)]
#[CoversClass(Storage\Pop3::class)]
#[Group('integration')]
final class Pop3ScramTest extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POP3_ENABLED')) {
            static::markTestSkipped('Contenir_Mail POP3 tests are not enabled');
        }
    }

    private static function config(): ConnectionConfig
    {
        return new ConnectionConfig((string) getenv('TESTS_CONTENIR_MAIL_POP3_HOST'), security: Security::StartTls);
    }

    private static function password(): string
    {
        return (string) getenv('TESTS_CONTENIR_MAIL_POP3_PASSWORD');
    }

    #[Test]
    public function opensTheMailboxWithScramSha256(): void
    {
        $mailbox = new Storage\Pop3([
            'host' => (string) getenv('TESTS_CONTENIR_MAIL_POP3_HOST'),
            'auth' => ['type' => 'scram-sha-256', 'username' => 'test', 'password' => self::password()],
        ]);

        static::assertIsInt($mailbox->countMessages());
    }

    /**
     * Dovecot proves it knows the password in a "+" continuation, which the client checks and
     * answers with an empty response before "+OK".
     */
    #[Test]
    public function checksTheServersProofBeforeSigningIn(): void
    {
        $connection = new RecordingConnection();
        $pop3       = new Pop3(connection: $connection);
        $pop3->connect(self::config());
        $pop3->authenticate(new ScramSha256('test', self::password()));

        $transcript = $connection->transcript();
        static::assertSame(
            [true, 'C: ', true],
            [
                str_starts_with($transcript[count($transcript) - 3] ?? '', 'S: + '),
                $transcript[count($transcript) - 2] ?? '',
                str_starts_with($transcript[count($transcript) - 1] ?? '', 'S: +OK'),
            ],
            implode("\n", $transcript),
        );
    }

    #[Test]
    public function refusesTheWrongPassword(): void
    {
        $pop3 = new Pop3(self::config());

        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('last request failed: [AUTH] Authentication failed.');

        $pop3->authenticate(new ScramSha256('test', bin2hex(random_bytes(8))));
    }
}
