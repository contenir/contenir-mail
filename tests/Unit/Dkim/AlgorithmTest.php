<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Algorithm;
use Contenir\Mail\Dkim\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Algorithm::class)]
#[Group('unit')]
final class AlgorithmTest extends TestCase
{
    #[Test]
    #[DataProvider('names')]
    public function readsAlgorithmByNameInAnyCase(string $name, Algorithm $expected): void
    {
        static::assertSame($expected, Algorithm::fromName($name));
    }

    /**
     * @return array<string, array{string, Algorithm}>
     */
    public static function names(): array
    {
        return [
            'rsa-sha256'         => ['rsa-sha256', Algorithm::RsaSha256],
            'rsa-sha256 upper'   => ['RSA-SHA256', Algorithm::RsaSha256],
            'ed25519-sha256'     => ['ed25519-sha256', Algorithm::Ed25519Sha256],
            'ed25519-sha256 mix' => ['Ed25519-SHA256', Algorithm::Ed25519Sha256],
        ];
    }

    #[Test]
    #[DataProvider('sha1')]
    public function refusesRsaSha1(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'DKIM algorithm rsa-sha1 is refused, as RFC 8301 forbids signing with SHA-1; use rsa-sha256 or ed25519-sha256',
        );

        Algorithm::fromName($name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sha1(): array
    {
        return [
            'lower case' => ['rsa-sha1'],
            'upper case' => ['RSA-SHA1'],
        ];
    }

    #[Test]
    public function refusesUnknownAlgorithmNamingItWithControlCharactersEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown DKIM algorithm "Md5\n"; expected rsa-sha256 or ed25519-sha256');

        Algorithm::fromName("Md5\n");
    }
}
