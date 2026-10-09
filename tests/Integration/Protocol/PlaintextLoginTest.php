<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Tests\Integration\TestAsset\RecordingConnection;
use Contenir\Mail\Tests\Integration\TestAsset\Servers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function implode;
use function str_contains;

/**
 * Dovecot set up as in production, refusing passwords before TLS: IMAP
 * advertises LOGINDISABLED, and POP3 refuses USER.
 */
#[CoversClass(Imap::class)]
#[CoversClass(Pop3::class)]
#[Group('integration')]
final class PlaintextLoginTest extends TestCase
{
    protected function setUp(): void
    {
        Servers::skipUnlessRunning();
    }

    private static function config(int $port, Security $security): ConnectionConfig
    {
        return new ConnectionConfig(Servers::HOST, $port, $security);
    }

    /**
     * The lines of the conversation that carry the password.
     *
     * @return list<string>
     */
    private static function passwordLines(RecordingConnection $connection): array
    {
        return array_values(array_filter(
            $connection->transcript(),
            static fn(string $line): bool => str_contains($line, Servers::PASSWORD),
        ));
    }

    #[Test]
    public function imapKeepsPasswordWhenServerAdvertisesLoginDisabled(): void
    {
        $connection = new RecordingConnection();
        $imap       = new Imap(connection: $connection);
        $imap->connect(self::config(Servers::STRICT_IMAP, Security::None));

        try {
            $imap->login(Servers::USER, Servers::PASSWORD);
            static::fail('LOGIN was sent despite LOGINDISABLED');
        } catch (RuntimeException $e) {
            static::assertSame(
                [
                    'The server does not allow LOGIN on this connection (LOGINDISABLED); connect with TLS or STARTTLS',
                    [],
                ],
                [$e->getMessage(), self::passwordLines($connection)],
                implode("\n", $connection->transcript()),
            );
        }
    }

    #[Test]
    public function imapLogsInAfterStartTls(): void
    {
        $imap = new Imap(self::config(Servers::STRICT_IMAP, Security::StartTls));

        static::assertTrue($imap->login(Servers::USER, Servers::PASSWORD));
    }

    /**
     * Dovecot refuses USER before TLS, so the password must never follow.
     */
    #[Test]
    public function pop3KeepsPasswordWhenServerRefusesUser(): void
    {
        $connection = new RecordingConnection();
        $pop3       = new Pop3(connection: $connection);
        $pop3->connect(self::config(Servers::STRICT_POP3, Security::None));

        try {
            $pop3->login(Servers::USER, Servers::PASSWORD);
            static::fail('The server accepted USER and PASS without TLS');
        } catch (RuntimeException $e) {
            $refusal = str_contains($e->getMessage(), 'Plaintext authentication disallowed');
            static::assertSame(
                [true, []],
                [$refusal, self::passwordLines($connection)],
                $e->getMessage() . "\n" . implode("\n", $connection->transcript()),
            );
        }
    }

    #[Test]
    public function pop3LogsInAfterStls(): void
    {
        $pop3 = new Pop3(self::config(Servers::STRICT_POP3, Security::StartTls));
        $pop3->login(Servers::USER, Servers::PASSWORD);

        static::assertSame([], $pop3->getList());
    }
}
