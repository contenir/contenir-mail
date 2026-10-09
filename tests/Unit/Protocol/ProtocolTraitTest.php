<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ProtocolTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

#[CoversTrait(ProtocolTrait::class)]
#[Group('unit')]
final class ProtocolTraitTest extends TestCase
{
    /**
     * An object using the trait.
     */
    private static function protocol(): object
    {
        return new class {
            use ProtocolTrait;
        };
    }

    #[Test]
    public function offersOnlyTls12And13(): void
    {
        static::assertSame(
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            self::protocol()->getCryptoMethod(),
        );
    }

    #[Test]
    public function validatesCertificatesByDefault(): void
    {
        static::assertTrue(self::protocol()->validateCert());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function skipsCertificateValidationWhenAsked(): void
    {
        static::assertFalse(self::protocol()->setNoValidateCert(true)->validateCert());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function validatesCertificatesAgainWhenAsked(): void
    {
        static::assertTrue(self::protocol()->setNoValidateCert(true)->setNoValidateCert(false)->validateCert());
    }
}
