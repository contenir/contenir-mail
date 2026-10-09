<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Message;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Pop3\Xoauth2\Microsoft;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Tests\Integration\TestAsset\Mailbox;
use Contenir\Mail\Tests\Integration\TestAsset\RecordingConnection;
use Contenir\Mail\Tests\Integration\TestAsset\Servers;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function bin2hex;
use function count;
use function implode;
use function random_bytes;

/**
 * XOAUTH2 against Dovecot's oauth2 passdb, which asks a token introspection
 * endpoint (RFC 7662) whether the token is active and whose it is, as a
 * production server asks its identity provider.
 */
#[CoversClass(Microsoft::class)]
#[CoversClass(XOAuth2::class)]
#[Group('integration')]
final class OAuthIntrospectionTest extends TestCase
{
    protected function setUp(): void
    {
        Servers::skipUnlessRunning();
    }

    private static function pop3Config(): ConnectionConfig
    {
        return new ConnectionConfig(Servers::HOST, Servers::STRICT_POP3, Security::StartTls);
    }

    private static function transport(#[SensitiveParameter] string $token): Smtp
    {
        return new Smtp([
            'host' => Servers::HOST,
            'port' => Servers::STRICT_SUBMISSION,
            'auth' => ['type' => 'xoauth2', 'username' => Servers::USER, 'access_token' => $token],
        ]);
    }

    private static function message(string $subject): Message
    {
        return (new Message())->setFrom('sender@example.org')
            ->setTo('inbox@example.org')
            ->setSubject($subject)
            ->setText('Sent with an introspected token');
    }

    #[Test]
    public function pop3LogsInWithActiveToken(): void
    {
        $pop3 = new Microsoft(self::pop3Config());
        $pop3->login(Servers::USER, Servers::ACCESS_TOKEN);

        static::assertSame([], $pop3->getList());
    }

    /**
     * The endpoint calls the token inactive; the exchange must still be finished, with the server's own reply.
     */
    #[Test]
    public function pop3FinishesExchangeWhenTokenIsInactive(): void
    {
        $connection = new RecordingConnection();
        $pop3       = new Microsoft(connection: $connection);
        $pop3->connect(self::pop3Config());

        try {
            $pop3->login(Servers::USER, 'revoked-token');
            static::fail('The inactive token was accepted');
        } catch (ExceptionInterface) {
            $transcript = $connection->transcript();
            static::assertSame(
                ['C: ', 'S: -ERR [AUTH] Authentication failed.'],
                [$transcript[count($transcript) - 2] ?? '', $transcript[count($transcript) - 1] ?? ''],
                implode("\n", $transcript),
            );
        }
    }

    #[Test]
    public function smtpDeliversWithActiveToken(): void
    {
        $subject = 'introspected ' . bin2hex(random_bytes(4));
        self::transport(Servers::ACCESS_TOKEN)->send(self::message($subject));

        static::assertSame($subject, Mailbox::delivered('inbox', $subject)?->getSubject());
    }

    #[Test]
    public function smtpRefusesInactiveToken(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('5.7.8 Error: authentication failed');

        self::transport('revoked-token')->send(self::message('refused'));
    }
}
