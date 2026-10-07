<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

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
            'false'                       => [false, Security::None],
            'null'                        => [null, Security::None],
            'empty string'                => ['', Security::None],
            'true'                        => [true, Security::None],
        ];
    }
}
