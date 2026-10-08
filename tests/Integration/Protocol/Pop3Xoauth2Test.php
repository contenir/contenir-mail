<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Protocol;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Pop3\Xoauth2\Microsoft;
use Contenir\Mail\Protocol\Security;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getenv;

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

    #[Test]
    public function refusesAWrongAccessToken(): void
    {
        $this->expectException(ExceptionInterface::class);

        self::pop3()->login('test', 'wrong');
    }
}
