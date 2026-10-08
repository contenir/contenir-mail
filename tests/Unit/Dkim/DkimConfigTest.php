<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Algorithm;
use Contenir\Mail\Dkim\Canonicalization;
use Contenir\Mail\Dkim\DkimConfig;
use Contenir\Mail\Dkim\PrivateKey;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Tests\Unit\TestAsset\DkimKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function str_repeat;
use function strlen;

#[CoversClass(DkimConfig::class)]
#[Group('unit')]
final class DkimConfigTest extends TestCase
{
    #[Test]
    public function hasSafeDefaults(): void
    {
        $config = self::config();

        static::assertSame(
            [
                'algorithm'              => Algorithm::Ed25519Sha256,
                'headers'                => DkimConfig::DEFAULT_HEADERS,
                'headerCanonicalization' => Canonicalization::Relaxed,
                'bodyCanonicalization'   => Canonicalization::Relaxed,
                'identity'               => null,
                'bodyLength'             => false,
                'timestamp'              => true,
                'expiresAfter'           => null,
            ],
            [
                'algorithm'              => $config->algorithm,
                'headers'                => $config->headers,
                'headerCanonicalization' => $config->headerCanonicalization,
                'bodyCanonicalization'   => $config->bodyCanonicalization,
                'identity'               => $config->identity,
                'bodyLength'             => $config->bodyLength,
                'timestamp'              => $config->timestamp,
                'expiresAfter'           => $config->expiresAfter,
            ],
        );
    }

    #[Test]
    public function signsTheUsualHeadersByDefault(): void
    {
        static::assertSame(
            [
                'From',
                'To',
                'Cc',
                'Subject',
                'Date',
                'Message-ID',
                'Reply-To',
                'In-Reply-To',
                'References',
                'MIME-Version',
                'Content-Type',
                'Content-Transfer-Encoding',
            ],
            DkimConfig::DEFAULT_HEADERS,
        );
    }

    #[Test]
    public function takesAlgorithmFromRsaKey(): void
    {
        $config = new DkimConfig('example.com', 'mail', PrivateKey::fromPem(DkimKeys::RFC8463_RSA_PEM));

        static::assertSame(Algorithm::RsaSha256, $config->algorithm);
    }

    #[Test]
    public function acceptsAlgorithmMatchingTheKey(): void
    {
        $config = new DkimConfig('example.com', 'mail', self::key(), algorithm: Algorithm::Ed25519Sha256);

        static::assertSame(Algorithm::Ed25519Sha256, $config->algorithm);
    }

    #[Test]
    public function refusesAlgorithmOtherThanTheKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The DKIM algorithm is rsa-sha256, but the private key is for ed25519-sha256');

        new DkimConfig('example.com', 'mail', self::key(), algorithm: Algorithm::RsaSha256);
    }

    #[Test]
    #[DataProvider('validNames')]
    public function acceptsDomainAndSelector(string $name): void
    {
        $config = new DkimConfig($name, $name, self::key());

        static::assertSame([$name, $name], [$config->domain, $config->selector]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validNames(): array
    {
        return [
            'one label'     => ['mail'],
            'two labels'    => ['example.com'],
            'digits'        => ['2026'],
            'hyphen inside' => ['mail-2026.example.com'],
            'upper case'    => ['Example.COM'],
            'label of 63'   => [str_repeat('a', times: 63) . '.com'],
            'one character' => ['a.b'],
        ];
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function refusesInvalidDomain(string $name, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The DKIM domain \"{$shown}\" must be an ASCII domain name of letters, digits, hyphens and dots, "
                . 'without white space, semicolons or control characters',
        );

        new DkimConfig($name, 'mail', self::key());
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function refusesInvalidSelector(string $name, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The DKIM selector \"{$shown}\" must be an ASCII domain name");

        new DkimConfig('example.com', $name, self::key());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty'               => ['', ''],
            'semicolon'           => ['example.com; h=from', 'example.com; h=from'],
            'space'               => ['example .com', 'example .com'],
            'line break'          => ["example.com\r\nBcc: x", 'example.com\r\nBcc: x'],
            'tab'                 => ["example\t.com", 'example\t.com'],
            'leading hyphen'      => ['-example.com', '-example.com'],
            'trailing hyphen'     => ['example-.com', 'example-.com'],
            'trailing dot'        => ['example.com.', 'example.com.'],
            'leading dot'         => ['.example.com', '.example.com'],
            'empty label'         => ['example..com', 'example..com'],
            'label of 64'         => [str_repeat('a', times: 64) . '.com', str_repeat('a', times: 64) . '.com'],
            'underscore'          => ['mail_2026', 'mail_2026'],
            'non-ASCII'           => ['exämple.com', 'exämple.com'],
            'trailing line break' => ["example.com\n", 'example.com\n'],
        ];
    }

    #[Test]
    public function acceptsKeyRecordNameOf253Characters(): void
    {
        $domain = self::longDomain(240);
        $config = new DkimConfig($domain, 'a', self::key());

        static::assertSame($domain, $config->domain);
    }

    #[Test]
    public function refusesKeyRecordNameLongerThan253Characters(): void
    {
        $domain = self::longDomain(241);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The DKIM key record name \"a._domainkey.{$domain}\" is longer than the 253 characters DNS allows",
        );

        new DkimConfig($domain, 'a', self::key());
    }

    #[Test]
    #[DataProvider('validHeaders')]
    public function acceptsHeaders(array $headers): void
    {
        static::assertSame($headers, (new DkimConfig('example.com', 'mail', self::key(), headers: $headers))->headers);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function validHeaders(): array
    {
        return [
            'From alone'       => [['From']],
            'from, lower case' => [['from', 'subject']],
            'longest name'     => [['From', str_repeat('X', DkimConfig::MAX_HEADER_NAME_LENGTH)]],
            'name of one'      => [['From', 'X']],
        ];
    }

    /**
     * @param list<string> $headers
     */
    #[Test]
    #[DataProvider('invalidHeaders')]
    public function refusesHeaders(array $headers, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new DkimConfig('example.com', 'mail', self::key(), headers: $headers);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function invalidHeaders(): array
    {
        $tooLong = str_repeat('X', DkimConfig::MAX_HEADER_NAME_LENGTH + 1);

        return [
            'without From'   => [['To', 'Subject'], 'The signed headers must include From (RFC 6376, section 5.4)'],
            'none'           => [[], 'The signed headers must include From (RFC 6376, section 5.4)'],
            'From twice'     => [
                ['From', 'from'],
                'A header is listed more than once to be signed; each instance of a listed header is signed',
            ],
            'Bcc'            => [
                ['From', 'Bcc'],
                'Bcc cannot be signed: transports remove it before sending, so the signature would not verify',
            ],
            'DKIM-Signature' => [
                ['From', 'dkim-signature'],
                'DKIM-Signature cannot be listed among the headers it signs',
            ],
            'colon'          => [
                ['From', 'To:Cc'],
                'The signed header name "To:Cc" must be 1 to 76 printable US-ASCII characters, without a colon',
            ],
            'empty name'     => [['From', ''], 'The signed header name "" must be 1 to 76'],
            'space'          => [['From', 'X Tag'], 'The signed header name "X Tag" must be'],
            'control'        => [['From', "X\nTag"], 'The signed header name "X\nTag" must be'],
            'line break end' => [['From', "Subject\n"], 'The signed header name "Subject\n" must be'],
            'too long'       => [['From', $tooLong], "The signed header name \"{$tooLong}\" must be"],
        ];
    }

    #[Test]
    #[DataProvider('validIdentities')]
    public function acceptsIdentityAtTheDomainOrASubdomain(string $identity): void
    {
        static::assertSame(
            $identity,
            (new DkimConfig('Example.com', 'mail', self::key(), identity: $identity))->identity,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validIdentities(): array
    {
        return [
            'domain only'      => ['@example.com'],
            'user'             => ['joe@example.com'],
            'subdomain'        => ['@news.example.com'],
            'other case'       => ['joe@EXAMPLE.com'],
            'dotted user'      => ['joe.sixpack+news@example.com'],
            'specials in user' => ["!#$%&'*+/?^_`{|}~-@example.com"],
        ];
    }

    #[Test]
    #[DataProvider('invalidIdentities')]
    public function refusesIdentityOutsideTheDomain(string $identity, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "The DKIM identity \"{$shown}\" must be an address at \"example.com\" or a subdomain of it, such as \"@example.com\"",
        );

        new DkimConfig('Example.com', 'mail', self::key(), identity: $identity);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidIdentities(): array
    {
        return [
            'no at sign'            => ['example.com', 'example.com'],
            'other domain'          => ['joe@example.org', 'joe@example.org'],
            'suffix, not subdomain' => ['joe@badexample.com', 'joe@badexample.com'],
            'parent domain'         => ['joe@com', 'joe@com'],
            'equals sign'           => ['a=b@example.com', 'a=b@example.com'],
            'semicolon'             => ['a;b@example.com', 'a;b@example.com'],
            'space'                 => ['a b@example.com', 'a b@example.com'],
            'line break'            => ["a\r\n@example.com", 'a\r\n@example.com'],
            'line break in host'    => ["joe@example.com\n", 'joe@example.com\n'],
            'invalid host'          => ['joe@-x.example.com', 'joe@-x.example.com'],
            'empty'                 => ['', ''],
        ];
    }

    #[Test]
    public function acceptsExpiryOfOneSecond(): void
    {
        static::assertSame(1, (new DkimConfig('example.com', 'mail', self::key(), expiresAfter: 1))->expiresAfter);
    }

    #[Test]
    public function refusesExpiryUnderOneSecond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A DKIM signature must expire at least one second after signing; received 0');

        new DkimConfig('example.com', 'mail', self::key(), expiresAfter: 0);
    }

    #[Test]
    public function readsEverySettingFromIterable(): void
    {
        $config = DkimConfig::fromIterable([
            'domain'                  => 'example.com',
            'selector'                => 'mail',
            'privateKey'              => DkimKeys::RFC8463_ED25519_SEED,
            'algorithm'               => 'ED25519-SHA256',
            'headers'                 => 'From To Subject',
            'header_canonicalization' => 'simple',
            'body-canonicalization'   => 'simple',
            'identity'                => '@example.com',
            'body_length'             => 'yes',
            'timestamp'               => 'no',
            'expires_after'           => '3600',
        ]);

        static::assertSame(
            [
                'example.com',
                'mail',
                DkimKeys::RFC8463_ED25519_RECORD,
                ['From', 'To', 'Subject'],
                Canonicalization::Simple,
                Canonicalization::Simple,
                '@example.com',
                true,
                false,
                3600,
            ],
            [
                $config->domain,
                $config->selector,
                $config->privateKey->dnsRecord(),
                $config->headers,
                $config->headerCanonicalization,
                $config->bodyCanonicalization,
                $config->identity,
                $config->bodyLength,
                $config->timestamp,
                $config->expiresAfter,
            ],
        );
    }

    #[Test]
    public function readsDefaultsFromIterable(): void
    {
        $config = DkimConfig::fromIterable([
            'domain'      => 'example.com',
            'selector'    => 'mail',
            'private_key' => DkimKeys::RFC8463_ED25519_SEED,
        ]);

        static::assertEquals(self::config(), $config);
    }

    #[Test]
    public function readsPemKeyFromIterable(): void
    {
        $config = DkimConfig::fromIterable([
            'domain'                 => 'example.com',
            'selector'               => 'mail',
            'private_key'            => (string) file_get_contents(DkimKeys::path('test-only-rsa-2048-encrypted.pem')),
            'private_key_passphrase' => DkimKeys::TEST_PASSPHRASE,
        ]);

        static::assertSame(DkimKeys::TEST_RSA_RECORD, $config->privateKey->dnsRecord());
    }

    #[Test]
    public function readsKeyFileFromIterable(): void
    {
        $config = DkimConfig::fromIterable([
            'domain'                 => 'example.com',
            'selector'               => 'mail',
            'private_key_path'       => DkimKeys::path('test-only-rsa-2048-encrypted.pem'),
            'private_key_passphrase' => DkimKeys::TEST_PASSPHRASE,
        ]);

        static::assertSame(DkimKeys::TEST_RSA_RECORD, $config->privateKey->dnsRecord());
    }

    #[Test]
    #[DataProvider('keySettings')]
    public function refusesAnythingButOneKeySetting(array $settings): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            DkimConfig::class . ': give the private key as exactly one of "private_key" and "private_key_path"',
        );

        DkimConfig::fromIterable(['domain' => 'example.com', 'selector' => 'mail', ...$settings]);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function keySettings(): array
    {
        return [
            'neither' => [[]],
            'both'    => [[
                'private_key'      => DkimKeys::RFC8463_ED25519_SEED,
                'private_key_path' => DkimKeys::path('test-only-ed25519.key'),
            ]],
        ];
    }

    #[Test]
    public function refusesRsaSha1FromIterable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DKIM algorithm rsa-sha1 is refused');

        DkimConfig::fromIterable([
            'domain'      => 'example.com',
            'selector'    => 'mail',
            'private_key' => DkimKeys::RFC8463_ED25519_SEED,
            'algorithm'   => 'rsa-sha1',
        ]);
    }

    #[Test]
    #[DataProvider('requiredSettings')]
    public function requiresDomainAndSelector(string $missing): void
    {
        $settings = ['domain' => 'example.com', 'selector' => 'mail', 'private_key' => DkimKeys::RFC8463_ED25519_SEED];
        unset($settings[$missing]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(DkimConfig::class . ": option \"{$missing}\" is required");

        DkimConfig::fromIterable($settings);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredSettings(): array
    {
        return [
            'domain'   => ['domain'],
            'selector' => ['selector'],
        ];
    }

    private static function config(): DkimConfig
    {
        return new DkimConfig('example.com', 'mail', self::key());
    }

    private static function key(): PrivateKey
    {
        return PrivateKey::ed25519(DkimKeys::RFC8463_ED25519_SEED);
    }

    /**
     * A domain of labels of 63 characters and fewer, of the given length.
     */
    private static function longDomain(int $length): string
    {
        $domain = '';
        while ((strlen($domain) + 64) <= $length) {
            $domain .= str_repeat('a', times: 63) . '.';
        }

        return $domain . str_repeat('b', $length - strlen($domain));
    }
}
