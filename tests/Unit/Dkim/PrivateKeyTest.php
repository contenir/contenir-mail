<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Algorithm;
use Contenir\Mail\Dkim\PrivateKey;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Tests\Unit\TestAsset\DkimKeys;
use Contenir\Mail\Tests\Unit\TestAsset\DkimVerifier;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function base64_encode;
use function chmod;
use function chunk_split;
use function file_get_contents;
use function file_put_contents;
use function hash;
use function openssl_pkey_get_private;
use function openssl_verify;
use function print_r;
use function serialize;
use function sodium_crypto_sign_secretkey;
use function sodium_crypto_sign_seed_keypair;
use function sodium_crypto_sign_verify_detached;
use function str_contains;
use function str_repeat;
use function substr;

use const OPENSSL_ALGO_SHA256;

#[CoversClass(PrivateKey::class)]
#[Group('unit')]
final class PrivateKeyTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    #[Test]
    #[DataProvider('pemKeys')]
    public function readsPemKeyWithItsAlgorithmAndRecord(
        string $file,
        ?string $passphrase,
        Algorithm $algorithm,
        string $record,
    ): void {
        $key = PrivateKey::fromPem((string) file_get_contents(DkimKeys::path($file)), $passphrase);

        static::assertSame([$algorithm, $record], [$key->algorithm, $key->dnsRecord()]);
    }

    /**
     * @return array<string, array{string, string|null, Algorithm, string}>
     */
    public static function pemKeys(): array
    {
        return [
            'RSA'           => ['test-only-rsa-2048.pem', null, Algorithm::RsaSha256, DkimKeys::TEST_RSA_RECORD],
            'encrypted RSA' => [
                'test-only-rsa-2048-encrypted.pem',
                DkimKeys::TEST_PASSPHRASE,
                Algorithm::RsaSha256,
                DkimKeys::TEST_RSA_RECORD,
            ],
            'Ed25519'       => ['test-only-ed25519.pem', null, Algorithm::Ed25519Sha256, DkimKeys::TEST_ED25519_RECORD],
        ];
    }

    #[Test]
    public function accepts1024BitRsaKey(): void
    {
        static::assertSame(DkimKeys::RFC8463_RSA_RECORD, PrivateKey::fromPem(DkimKeys::RFC8463_RSA_PEM)->dnsRecord());
    }

    #[Test]
    public function refusesRsaKeyUnder1024Bits(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The DKIM RSA key has 512 bits; at least 1024 are required, and 2048 or more are recommended',
        );

        PrivateKey::fromFile(DkimKeys::path('test-only-rsa-512.pem'));
    }

    #[Test]
    public function refusesKeyThatIsNeitherRsaNorEd25519(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The DKIM private key must be an RSA or an Ed25519 key');

        PrivateKey::fromFile(DkimKeys::path('test-only-ec-p256.pem'));
    }

    #[Test]
    #[DataProvider('unreadablePem')]
    public function refusesPemItCannotRead(string $pem, ?string $passphrase): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The DKIM private key could not be read; give a PEM private key and, if it is encrypted, its passphrase',
        );

        PrivateKey::fromPem($pem, $passphrase);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function unreadablePem(): array
    {
        $encrypted = (string) file_get_contents(DkimKeys::path('test-only-rsa-2048-encrypted.pem'));

        return [
            'not a key'          => ["-----BEGIN PRIVATE KEY-----\nnot a key\n-----END PRIVATE KEY-----\n", null],
            'wrong passphrase'   => [$encrypted, 'not-the-passphrase'],
            'missing passphrase' => [$encrypted, null],
        ];
    }

    #[Test]
    public function readsOpenSslKey(): void
    {
        $key = openssl_pkey_get_private((string) file_get_contents(DkimKeys::path('test-only-rsa-2048.pem')));
        static::assertNotFalse($key);

        static::assertSame(DkimKeys::TEST_RSA_RECORD, PrivateKey::fromOpenSsl($key)->dnsRecord());
    }

    #[Test]
    #[DataProvider('keyFiles')]
    public function readsKeyFile(string $file, ?string $passphrase, string $record): void
    {
        static::assertSame($record, PrivateKey::fromFile(DkimKeys::path($file), $passphrase)->dnsRecord());
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function keyFiles(): array
    {
        return [
            'RSA PEM'                 => ['test-only-rsa-2048.pem', null, DkimKeys::TEST_RSA_RECORD],
            'encrypted RSA PEM'       => [
                'test-only-rsa-2048-encrypted.pem',
                DkimKeys::TEST_PASSPHRASE,
                DkimKeys::TEST_RSA_RECORD,
            ],
            'Ed25519 PEM'             => ['test-only-ed25519.pem', null, DkimKeys::TEST_ED25519_RECORD],
            'Ed25519 base64, newline' => ['test-only-ed25519.key', null, DkimKeys::TEST_ED25519_RECORD],
        ];
    }

    #[Test]
    public function readsKeyFileWithColonInItsName(): void
    {
        $path = "{$this->directory}/mail:2026.key";
        file_put_contents($path, DkimKeys::RFC8463_ED25519_SEED);

        static::assertSame(DkimKeys::RFC8463_ED25519_RECORD, PrivateKey::fromFile($path)->dnsRecord());
    }

    #[Test]
    #[DataProvider('streamWrappers')]
    public function refusesStreamWrapperPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The DKIM private key must be a local file, not a stream wrapper URL; received \"{$path}\"",
        );

        PrivateKey::fromFile($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function streamWrappers(): array
    {
        return [
            'php'        => ['php://memory'],
            'phar'       => ['phar://key.phar/key.pem'],
            'two letter' => ['ftp://example.com/key.pem'],
            'with digit' => ['s3://bucket/key.pem'],
        ];
    }

    #[Test]
    public function refusesMissingFileWithoutDetail(): void
    {
        $path = "{$this->directory}/missing.pem";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unable to read the DKIM private key file \"{$path}\"");

        $this->expectExceptionMessageMatches('/"$/');

        PrivateKey::fromFile($path);
    }

    #[Test]
    public function refusesDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unable to read the DKIM private key file \"{$this->directory}\"");

        PrivateKey::fromFile($this->directory);
    }

    #[Test]
    public function refusesUnreadableFileGivingTheReason(): void
    {
        $path = "{$this->directory}/unreadable.pem";
        file_put_contents($path, DkimKeys::RFC8463_ED25519_SEED);
        chmod($path, permissions: 0o000);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unable to read the DKIM private key file \"{$path}\": file_get_contents(");

        PrivateKey::fromFile($path);
    }

    #[Test]
    #[DataProvider('ed25519Keys')]
    public function readsEd25519Key(string $key): void
    {
        static::assertSame(DkimKeys::RFC8463_ED25519_RECORD, PrivateKey::fromEd25519($key)->dnsRecord());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ed25519Keys(): array
    {
        $seed   = (string) base64_decode(DkimKeys::RFC8463_ED25519_SEED, strict: true);
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));

        return [
            'raw seed'          => [$seed],
            'base64 seed'       => [DkimKeys::RFC8463_ED25519_SEED],
            'raw secret key'    => [$secret],
            'base64 secret key' => [base64_encode($secret)],
        ];
    }

    #[Test]
    public function ed25519KeyHasItsAlgorithm(): void
    {
        static::assertSame(
            Algorithm::Ed25519Sha256,
            PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED)->algorithm,
        );
    }

    #[Test]
    #[DataProvider('wrongLengths')]
    public function refusesEd25519KeyOfWrongLength(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'An Ed25519 DKIM key must be a 32-byte seed or a 64-byte secret key, raw or in base64',
        );

        PrivateKey::fromEd25519($key);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wrongLengths(): array
    {
        return [
            'empty'                  => [''],
            '31 bytes'               => [str_repeat('a', times: 31)],
            '33 bytes'               => [str_repeat('a', times: 33)],
            '63 bytes'               => [str_repeat('a', times: 63)],
            '65 bytes'               => [str_repeat('a', times: 65)],
            '44 characters, not b64' => [str_repeat('*', times: 44)],
            'base64 of 33 bytes'     => [base64_encode(str_repeat('a', times: 33))],
        ];
    }

    #[Test]
    public function refusesEd25519SecretKeyWhosePublicHalfDoesNotMatch(): void
    {
        $seed = (string) base64_decode(DkimKeys::RFC8463_ED25519_SEED, strict: true);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The Ed25519 DKIM secret key does not hold the public key of its seed; it is corrupt',
        );

        PrivateKey::fromEd25519($seed . str_repeat("\0", times: 32));
    }

    #[Test]
    public function signsEd25519OverTheSha256OfTheData(): void
    {
        $key       = PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED);
        $signature = $key->sign('data');
        $public    = (string) base64_decode(
            DkimVerifier::tags(DkimKeys::RFC8463_ED25519_RECORD)['p'] ?? '',
            strict: true,
        );

        static::assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            hash('sha256', data: 'data', binary: true),
            $public,
        ));
    }

    #[Test]
    public function signsRsaWithSha256(): void
    {
        $signature = PrivateKey::fromPem(DkimKeys::RFC8463_RSA_PEM)->sign('data');
        $public    = "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(DkimVerifier::tags(DkimKeys::RFC8463_RSA_RECORD)['p'] ?? '', length: 64, separator: "\n")
        . "-----END PUBLIC KEY-----\n";

        static::assertSame(1, openssl_verify('data', $signature, $public, OPENSSL_ALGO_SHA256));
    }

    #[Test]
    #[DataProvider('allKeys')]
    public function hidesKeyFromDebugOutput(PrivateKey $key, string $algorithm): void
    {
        static::assertSame(['algorithm' => $algorithm, 'key' => '[hidden]'], $key->__debugInfo());
    }

    /**
     * @return array<string, array{PrivateKey, string}>
     */
    public static function allKeys(): array
    {
        return [
            'RSA'     => [PrivateKey::fromPem(DkimKeys::RFC8463_RSA_PEM), 'rsa-sha256'],
            'Ed25519' => [PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED), 'ed25519-sha256'],
        ];
    }

    #[Test]
    public function printsNoKeyMaterial(): void
    {
        $seed = (string) base64_decode(DkimKeys::RFC8463_ED25519_SEED, strict: true);

        static::assertFalse(str_contains(
            print_r(PrivateKey::fromEd25519($seed), return: true),
            substr($seed, offset: 0, length: 8),
        ));
    }

    #[Test]
    public function cannotBeSerialized(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(PrivateKey::class . ' cannot be serialized');

        serialize(PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED));
    }
}
