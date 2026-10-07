<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Validator;

use Contenir\Mail\Validator\DomainName;
use Contenir\Mail\Validator\HostnameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(HostnameValidator::class)]
#[CoversClass(DomainName::class)]
#[Group('unit')]
final class HostnameValidatorTest extends TestCase
{
    #[DataProvider('validForBothProvider')]
    #[Test]
    public function acceptsNameForEmailAddress(string $hostname): void
    {
        static::assertTrue(HostnameValidator::forEmailAddress()->isValid($hostname));
    }

    #[DataProvider('validForBothProvider')]
    #[DataProvider('validForConnectionOnlyProvider')]
    #[Test]
    public function acceptsNameForConnection(string $hostname): void
    {
        static::assertTrue(HostnameValidator::forConnection()->isValid($hostname));
    }

    #[DataProvider('invalidForBothProvider')]
    #[Test]
    public function rejectsNameForEmailAddress(string $hostname): void
    {
        $validator = HostnameValidator::forEmailAddress();

        static::assertFalse($validator->isValid($hostname));
        static::assertSame([HostnameValidator::INVALID_HOSTNAME], $validator->getMessages());
    }

    #[DataProvider('invalidForBothProvider')]
    #[Test]
    public function rejectsNameForConnection(string $hostname): void
    {
        $validator = HostnameValidator::forConnection();

        static::assertFalse($validator->isValid($hostname));
        static::assertSame([HostnameValidator::INVALID_HOSTNAME], $validator->getMessages());
    }

    #[DataProvider('ipAddressProvider')]
    #[Test]
    public function rejectsIpAddressForEmailAddress(string $hostname): void
    {
        $validator = HostnameValidator::forEmailAddress();

        static::assertFalse($validator->isValid($hostname));
        static::assertSame([HostnameValidator::IP_ADDRESS_NOT_ALLOWED], $validator->getMessages());
    }

    #[DataProvider('ipAddressProvider')]
    #[Test]
    public function acceptsIpAddressForConnection(string $hostname): void
    {
        static::assertTrue(HostnameValidator::forConnection()->isValid($hostname));
    }

    #[DataProvider('validForConnectionOnlyProvider')]
    #[Test]
    public function rejectsUriOnlyNameForEmailAddress(string $hostname): void
    {
        static::assertFalse(HostnameValidator::forEmailAddress()->isValid($hostname));
    }

    #[Test]
    public function rejectsNonStringWithTypeMessage(): void
    {
        $validator = HostnameValidator::forConnection();

        static::assertFalse($validator->isValid(null));
        static::assertSame([HostnameValidator::INVALID_TYPE], $validator->getMessages());
    }

    #[Test]
    public function clearsMessagesFromPreviousValidation(): void
    {
        $validator = HostnameValidator::forEmailAddress();
        $validator->isValid('bad host');

        $validator->isValid('example.com');

        static::assertSame([], $validator->getMessages());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validForBothProvider(): array
    {
        return [
            'domain'                                => ['example.com'],
            'single label'                          => ['localhost'],
            'single character labels'               => ['a.b'],
            'one character TLD'                     => ['foo.c'],
            'trailing dot'                          => ['example.com.'],
            'single label with trailing dot'        => ['a.'],
            'IPv4 with a letter, as a name'         => ['1.2.3.4x'],
            'mixed case'                            => ['UPPER.Example.COM'],
            'leading dash, as local network name'   => ['-foo.com'],
            'double dash, as local network name'    => ['ab--cd.com'],
            'punycode'                              => ['xn--mnchen-3ya.de'],
            '63 character label'                    => [str_repeat('a', times: 63) . '.com'],
            'numeric label'                         => ['123.com'],
            'partial IPv4'                          => ['1.2.3'],
            'underscore below registrable domain'   => ['a_b.example.com'],
            'underscore in service label'           => ['_dmarc.example.com'],
            'internationalised'                     => ['münchen.de'],
            'internationalised TLD'                 => ['пример.рф'],
            'four character internationalised name' => ['é.ab'],
            'punycode label after underscore label' => ['a_b.xn--mnchen-3ya.de'],
            '253 character name with underscore'    => ['a_b.' . str_repeat('abcdefghi.', times: 24) . 'abcde.com'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validForConnectionOnlyProvider(): array
    {
        return [
            'underscore in single label'           => ['my_host'],
            'URI sub-delims'                       => ['a!b'],
            'percent-encoded octet'                => ['foo%20bar'],
            'underscore in registrable domain'     => ['x.a_b.com'],
            'empty label'                          => ['foo..com'],
            'leading dash after underscore label'  => ['a_b.-x.com'],
            'trailing dash after underscore label' => ['a_b.x-.com'],
            'double dash after underscore label'   => ['a_b.ab--c.com'],
            '254 character name with underscore'   => ['a_b.' . str_repeat('abcdefghi.', times: 24) . 'abcdef.com'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidForBothProvider(): array
    {
        return [
            'empty'                                              => [''],
            'only a dot'                                         => ['.'],
            'empty trailing label'                               => ['foo.com..'],
            'space'                                              => ['my host'],
            'line break'                                         => ["invalid\r\nhost name"],
            'port'                                               => ['host:25'],
            'path'                                               => ['host/path'],
            'IPv6 literal in brackets'                           => ['[::1]'],
            'IPv6 with a letter beyond f'                        => ['::g'],
            'unconvertible internationalised label'              => ['a.' . str_repeat('é', times: 64) . '.com'],
            'internationalised label with dash at the end'       => ['bé-.com'],
            'internationalised label with misplaced double dash' => ['ab--é.com'],
            'internationalised name with numeric TLD'            => ['é.a.123'],
            'internationalised single label'                     => ['é'],
            'three character internationalised name'             => ['é.é'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ipAddressProvider(): array
    {
        return [
            'IPv4'             => ['192.0.2.1'],
            'IPv6'             => ['2001:db8::1'],
            'IPv6 loopback'    => ['::1'],
            'upper-case IPv6'  => ['FE80::1'],
            'IPv4-mapped IPv6' => ['::ffff:192.0.2.1'],
        ];
    }
}
