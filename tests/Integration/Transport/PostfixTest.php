<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Transport;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Message;
use Contenir\Mail\Storage;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function bin2hex;
use function getenv;
use function quoted_printable_decode;
use function random_bytes;
use function rtrim;
use function usleep;

/**
 * Submits to a real Postfix, which checks the login against Dovecot and
 * delivers to Dovecot over LMTP; the message is then read back over IMAP.
 */
#[CoversClass(Smtp::class)]
#[Group('integration')]
final class PostfixTest extends TestCase
{
    private const string MAILBOX = 'inbox';

    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POSTFIX_ENABLED')) {
            static::markTestSkipped('Contenir_Mail Postfix tests are not enabled');
        }
    }

    /**
     * @param array<string, string> $auth
     */
    private static function transport(array $auth): Smtp
    {
        return new Smtp([
            'host' => (string) getenv('TESTS_CONTENIR_MAIL_POSTFIX_HOST'),
            'port' => (string) getenv('TESTS_CONTENIR_MAIL_POSTFIX_PORT'),
            'auth' => $auth,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function plain(#[SensitiveParameter] string $secret): array
    {
        return ['type' => 'plain', 'username' => 'test', 'password' => $secret];
    }

    private static function secret(): string
    {
        return (string) getenv('TESTS_CONTENIR_MAIL_IMAP_PASSWORD');
    }

    /**
     * The delivered message with this subject, waiting up to five seconds for Postfix to hand it over.
     */
    private static function delivered(string $subject): ?Storage\Message
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $mailbox = new Storage\Imap([
                'host'     => (string) getenv('TESTS_CONTENIR_MAIL_IMAP_HOST'),
                'user'     => self::MAILBOX,
                'password' => self::secret(),
            ]);
            foreach ($mailbox as $message) {
                if ($message->getSubject() === $subject) {
                    return $message;
                }
            }

            $mailbox->close();
            usleep(100_000);
        }

        return null;
    }

    private static function message(string $subject): Message
    {
        return (new Message())->setFrom('jo@example.org', 'Jö Bloggs')
            ->setTo(self::MAILBOX . '@example.org')
            ->setSubject($subject)
            ->setText("Grüße\r\n.leading dot\r\n");
    }

    #[Test]
    public function deliversThroughPostfixWithPlainLogin(): void
    {
        $subject = 'plain ' . bin2hex(random_bytes(4));
        self::transport(self::plain(self::secret()))->send(self::message($subject));

        $received = self::delivered($subject);

        static::assertSame(
            ['Jö Bloggs', "Grüße\r\n.leading dot"],
            [
                $received?->getFrom()->first()?->getName(),
                rtrim(quoted_printable_decode((string) $received?->getContent())),
            ],
        );
    }

    #[Test]
    public function deliversThroughPostfixWithXoauth2(): void
    {
        $subject = 'xoauth2 ' . bin2hex(random_bytes(4));
        self::transport(['type' => 'xoauth2', 'username' => 'test', 'access_token' => self::secret()])
            ->send(self::message($subject));

        static::assertSame($subject, self::delivered($subject)?->getSubject());
    }

    /**
     * Postfix relays the exchange to Dovecot, whose server-final arrives in a 334 that the client checks.
     */
    #[Test]
    public function deliversThroughPostfixWithScramSha256(): void
    {
        $subject = 'scram ' . bin2hex(random_bytes(4));
        self::transport(['type' => 'scram-sha-256', 'username' => 'test', 'password' => self::secret()])
            ->send(self::message($subject));

        static::assertSame($subject, self::delivered($subject)?->getSubject());
    }

    #[Test]
    public function refusesWrongPasswordWithScramSha256(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('5.7.8 Error: authentication failed');

        self::transport(['type' => 'scram-sha-256', 'username' => 'test', 'password' => bin2hex(random_bytes(8))])
            ->send(self::message('refused proof'));
    }

    /**
     * Postfix refuses a token passed on to Dovecot with 535 at once, with no challenge first.
     */
    #[Test]
    public function refusesWrongAccessToken(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->expectExceptionMessage('5.7.8 Error: authentication failed');

        self::transport(['type' => 'xoauth2', 'username' => 'test', 'access_token' => bin2hex(random_bytes(8))])
            ->send(self::message('refused token'));
    }

    #[Test]
    public function refusesWrongPassword(): void
    {
        $this->expectException(ExceptionInterface::class);

        self::transport(self::plain('wrong'))->send(self::message('refused'));
    }
}
