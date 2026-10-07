<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Security;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Security::class)]
#[Group('unit')]
final class SecurityTest extends TestCase
{
    #[Test]
    #[DataProvider('legacyProvider')]
    public function readsLaminasSslSetting(string|bool|null $ssl, Security $expected): void
    {
        static::assertSame($expected, Security::fromLegacy($ssl));
    }

    /**
     * @return array<string, array{string|bool|null, Security}>
     */
    public static function legacyProvider(): array
    {
        return [
            '"ssl" is TLS from the start' => ['ssl', Security::Tls],
            '"SSL" in capitals'           => ['SSL', Security::Tls],
            '"tls" is STARTTLS'           => ['tls', Security::StartTls],
            '"TLS" in capitals'           => ['TLS', Security::StartTls],
            '"starttls"'                  => ['starttls', Security::StartTls],
            'omitted takes the default'   => [null, Security::StartTls],
            'false is plain'              => [false, Security::None],
            'empty string is plain'       => ['', Security::None],
            '"none" is plain'             => ['none', Security::None],
        ];
    }

    #[Test]
    #[DataProvider('unknownLegacyProvider')]
    public function rejectsUnknownSettingRatherThanConnectingWithoutTls(string|bool $ssl, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Unknown connection security {$shown}; expected \"ssl\", \"tls\", \"none\" or false",
        );

        Security::fromLegacy($ssl);
    }

    /**
     * @return array<string, array{string|bool, string}>
     */
    public static function unknownLegacyProvider(): array
    {
        return [
            'misspelt' => ['tsl', "'tsl'"],
            'true'     => [true, 'true'],
            'version'  => ['tlsv1.2', "'tlsv1.2'"],
        ];
    }
}
