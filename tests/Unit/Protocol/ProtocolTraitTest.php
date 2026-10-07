<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ProtocolTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProtocolTraitTest extends TestCase
{
    #[Test]
    public function tls12Version(): void
    {
        $mock = new class {
            use ProtocolTrait;
        };

        static::assertNotEmpty(
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT & $mock->getCryptoMethod(),
            'TLSv1.2 must be present in crypto method list',
        );
    }
}
