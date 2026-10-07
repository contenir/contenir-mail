<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Validator;

use Contenir\Mail\Validator\EmailAddressValidator;
use Contenir\Mail\Validator\HostnameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(EmailAddressValidator::class)]
#[Group('unit')]
final class EmailAddressValidatorTest extends TestCase
{
    #[DataProvider('validAddressProvider')]
    #[Test]
    public function acceptsAddress(string $email): void
    {
        static::assertTrue((new EmailAddressValidator())->isValid($email));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('invalidAddressProvider')]
    #[Test]
    public function rejectsAddressWithMessages(string $email, array $expected): void
    {
        $validator = new EmailAddressValidator();

        static::assertFalse($validator->isValid($email));
        static::assertSame($expected, $validator->getMessages());
    }

    #[Test]
    public function clearsMessagesFromPreviousValidation(): void
    {
        $validator = new EmailAddressValidator();
        $validator->isValid('not an address');

        $validator->isValid('user@example.com');

        static::assertSame([], $validator->getMessages());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validAddressProvider(): array
    {
        return [
            'plain'                                  => ['user@example.com'],
            'local network host'                     => ['user@localhost'],
            'dot-atom'                               => ['first.last@example.com'],
            'every atext character'                  => ["a!#$%&'*/=?^_`{|}~+-z@example.com"],
            'quoted string'                          => ['"quo ted"@example.com'],
            'quoted pair'                            => ['"a\\"b"@example.com'],
            'UTF-8 local part'                       => ['üser@example.com'],
            'internationalised host'                 => ['user@münchen.de'],
            'unconvertible host accepted as written' => ['user@' . str_repeat('a', times: 64) . '.com'],
            '64 character local part'                => [str_repeat('l', times: 64) . '@example.com'],
        ];
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function invalidAddressProvider(): array
    {
        return [
            'no at-sign'                           => ['plain', [EmailAddressValidator::INVALID_FORMAT]],
            'empty local part'                     => ['@example.com', [EmailAddressValidator::INVALID_FORMAT]],
            'consecutive dots'                     => ['a..b@example.com', [EmailAddressValidator::INVALID_FORMAT]],
            'local part too long'                  => [
                str_repeat('l', times: 65) . '@example.com',
                [EmailAddressValidator::LENGTH_EXCEEDED],
            ],
            'host too long'                        => [
                'user@' . str_repeat('a.', times: 128),
                [EmailAddressValidator::LENGTH_EXCEEDED],
            ],
            'IP address host'                      => [
                'user@192.0.2.1',
                [
                    "'192.0.2.1' is not a valid hostname for the email address",
                    HostnameValidator::IP_ADDRESS_NOT_ALLOWED,
                ],
            ],
            'invalid host'                         => [
                'user@my host',
                [
                    "'my host' is not a valid hostname for the email address",
                    HostnameValidator::INVALID_HOSTNAME,
                ],
            ],
            'local part too long and invalid host' => [
                str_repeat('l', times: 65) . '@my host',
                [
                    EmailAddressValidator::LENGTH_EXCEEDED,
                    "'my host' is not a valid hostname for the email address",
                    HostnameValidator::INVALID_HOSTNAME,
                ],
            ],
            'text before quoted string'            => [
                'a"b c"@example.com',
                [
                    "'a\"b c\"' can not be matched against dot-atom format",
                    "'a\"b c\"' can not be matched against quoted-string format",
                    "'a\"b c\"' is not a valid local part for the email address",
                ],
            ],
            'text after quoted string'             => [
                '"b c"a@example.com',
                [
                    "'\"b c\"a' can not be matched against dot-atom format",
                    "'\"b c\"a' can not be matched against quoted-string format",
                    "'\"b c\"a' is not a valid local part for the email address",
                ],
            ],
            'invalid local part'                   => [
                'a b@example.com',
                [
                    "'a b' can not be matched against dot-atom format",
                    "'a b' can not be matched against quoted-string format",
                    "'a b' is not a valid local part for the email address",
                ],
            ],
        ];
    }
}
