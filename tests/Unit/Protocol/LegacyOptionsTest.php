<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\LegacyOptions;
use Contenir\Mail\Protocol\Security;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyOptions::class)]
#[Group('unit')]
final class LegacyOptionsTest extends TestCase
{
    #[DataProvider('securityProvider')]
    #[Test]
    public function readsTheSslSetting(string|bool|Security|null $ssl, Security $expected): void
    {
        static::assertSame($expected, LegacyOptions::security($ssl));
    }

    /**
     * @return array<string, array{string|bool|Security|null, Security}>
     */
    public static function securityProvider(): array
    {
        return [
            'null is the STARTTLS default' => [null, Security::StartTls],
            'laminas tls is STARTTLS'      => ['tls', Security::StartTls],
            'starttls'                     => ['StartTLS', Security::StartTls],
            'laminas ssl is implicit TLS'  => ['SSL', Security::Tls],
            'false is plain text'          => [false, Security::None],
            'empty string is plain text'   => ['', Security::None],
            'none'                         => ['NONE', Security::None],
            'a Security case'              => [Security::None, Security::None],
        ];
    }

    #[DataProvider('unknownProvider')]
    #[Test]
    public function refusesSettingsThatWouldSilentlyMeanPlainText(string|bool $ssl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown connection security');

        LegacyOptions::security($ssl);
    }

    /**
     * @return array<string, array{string|bool}>
     */
    public static function unknownProvider(): array
    {
        return [
            'true'     => [true],
            'misspelt' => ['ssl3'],
            'on'       => ['on'],
        ];
    }

    #[Test]
    public function buildsTheConnectionSettings(): void
    {
        static::assertEquals(
            new ConnectionConfig(
                host: 'mail.example.com',
                port: 1143,
                security: Security::Tls,
                verifyPeer: false,
                timeout: 7,
            ),
            LegacyOptions::config('mail.example.com', 1143, 'ssl', false, 7),
        );
    }

    #[DataProvider('defaultPortProvider')]
    #[Test]
    public function leavesThePortToTheProtocolDefault(?int $port): void
    {
        static::assertNull(LegacyOptions::config('mail.example.com', $port, false, true, 30)->port);
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function defaultPortProvider(): array
    {
        return [
            'null' => [null],
            'zero' => [0],
        ];
    }
}
