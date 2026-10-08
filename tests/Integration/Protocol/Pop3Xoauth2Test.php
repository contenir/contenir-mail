<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Pop3\Xoauth2\Microsoft;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Tests\Integration\TestAsset\RecordingConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function getenv;
use function implode;
use function substr;

/**
 * POP3 XOAUTH2 against Dovecot, which checks the token as the user's secret.
 */
#[CoversClass(Microsoft::class)]
#[Group('integration')]
final class Pop3Xoauth2Test extends TestCase
{
    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POP3_ENABLED')) {
            static::markTestSkipped('Contenir_Mail POP3 tests are not enabled');
        }
    }

    private static function pop3(): Microsoft
    {
        return new Microsoft(
            new ConnectionConfig((string) getenv('TESTS_CONTENIR_MAIL_POP3_HOST'), security: Security::StartTls),
        );
    }

    #[Test]
    public function logsInWithAnAccessToken(): void
    {
        $pop3 = self::pop3();
        $pop3->login('test', (string) getenv('TESTS_CONTENIR_MAIL_POP3_PASSWORD'));

        $messages = null;
        $octets   = null;
        $pop3->status($messages, $octets);

        static::assertIsNumeric($messages);
    }

    /**
     * Dovecot refuses with a challenge, which must be answered with an empty response
     * before the final -ERR; the transcript shows the exchange was finished.
     */
    #[Test]
    public function finishesTheExchangeWhenTheTokenIsRefused(): void
    {
        $connection = new RecordingConnection();
        $pop3       = new Microsoft(connection: $connection);
        $pop3->connect(
            new ConnectionConfig((string) getenv('TESTS_CONTENIR_MAIL_POP3_HOST'), security: Security::StartTls),
        );

        try {
            $pop3->login('test', 'wrong');
            static::fail('The wrong token was accepted');
        } catch (ExceptionInterface $e) {
            $transcript = $connection->transcript();
            static::assertSame(
                ['The server refused the access token', 'C: ', 'S: -ERR [AUTH] Authentication failed.'],
                [
                    substr($e->getMessage(), offset: 0, length: 35),
                    $transcript[count($transcript) - 2] ?? '',
                    $transcript[count($transcript) - 1] ?? '',
                ],
                implode("\n", $transcript),
            );
        }
    }
}
