<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\HeaderName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;
use function str_repeat;

#[CoversClass(HeaderName::class)]
#[Group('unit')]
final class HeaderNameTest extends TestCase
{
    /**
     * Data for filter name
     */
    public static function getFilterNames(): array
    {
        return [
            ['Subject', 'Subject'],
            ['Subject:', 'Subject'],
            [':Subject:', 'Subject'],
            ['Subject' . chr(32), 'Subject'],
            ['Subject' . chr(33), 'Subject' . chr(33)],
            ['Subject' . chr(126), 'Subject' . chr(126)],
            ['Subject' . chr(127), 'Subject'],
            ["Sub\x00ject\n", 'Subject'],
            ['Sübject', 'Sbject'],
        ];
    }

    #[Test]
    #[DataProvider('getFilterNames')]
    #[Group('ZF2015-04')]
    public function filterName(string $name, string $expected): void
    {
        HeaderName::assertValid($expected);
        static::assertSame($expected, HeaderName::filter($name));
    }

    public static function validateNames(): array
    {
        return [
            ['Subject', 'assertTrue'],
            ['Subject:', 'assertFalse'],
            [':Subject:', 'assertFalse'],
            ['Subject' . chr(32), 'assertFalse'],
            ['Subject' . chr(33), 'assertTrue'],
            ['Subject' . chr(126), 'assertTrue'],
            ['Subject' . chr(127), 'assertFalse'],
            ['', 'assertFalse'],
            ["Subject\n", 'assertFalse'],
            [chr(33), 'assertTrue'],
            [chr(126) . 'Subject', 'assertTrue'],
            [chr(127) . 'Subject', 'assertFalse'],
        ];
    }

    #[Test]
    #[DataProvider('validateNames')]
    #[Group('ZF2015-04')]
    public function validateName(string $name, string $assertion): void
    {
        $this->{$assertion}(HeaderName::isValid($name));
    }

    public static function assertNames(): array
    {
        return [
            ['Subject:'],
            [':Subject:'],
            ['Subject' . chr(32)],
            ['Subject' . chr(127)],
        ];
    }

    #[Test]
    #[DataProvider('assertNames')]
    #[Group('ZF2015-04')]
    public function assertValidRaisesExceptionForInvalidNames(string $name): void
    {
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('Invalid');
        HeaderName::assertValid($name);
    }

    #[Test]
    public function acceptsNameOfMaximumLength(): void
    {
        $this->expectNotToPerformAssertions();

        HeaderName::assertLength(str_repeat('X', HeaderName::MAX_LENGTH));
    }

    #[Test]
    public function rejectsNameLongerThanMaximumLength(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Header name must be at most 997 characters');

        HeaderName::assertLength(str_repeat('X', HeaderName::MAX_LENGTH + 1));
    }
}
