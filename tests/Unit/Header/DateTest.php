<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\Exception;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Date::class)]
#[Group('unit')]
final class DateTest extends TestCase
{
    private const string RFC2822 = 'Tue, 01 Jul 2003 10:52:37 +0200';

    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame('Date', $this->makeDate()->getFieldName());
    }

    #[Test]
    public function rendersRfc2822FieldValue(): void
    {
        static::assertSame(self::RFC2822, $this->makeDate()->getFieldValue());
    }

    #[Test]
    public function rendersRfc2822EncodedFieldValue(): void
    {
        static::assertSame(self::RFC2822, $this->makeDate()->getEncodedFieldValue());
    }

    #[Test]
    public function rendersHeaderLine(): void
    {
        static::assertSame('Date: ' . self::RFC2822, $this->makeDate()->toString());
    }

    #[Test]
    public function exposesDateAsImmutable(): void
    {
        $date = new DateTime(self::RFC2822);

        static::assertSame(
            self::RFC2822,
            (new Date($date))->getDate()
                ->format(DateTimeInterface::RFC2822),
        );
    }

    #[Test]
    public function isNotAffectedByLaterChangesToMutableDate(): void
    {
        $date   = new DateTime(self::RFC2822);
        $header = new Date($date);
        $date->modify('+1 day');

        static::assertSame(self::RFC2822, $header->getFieldValue());
    }

    #[DataProvider('realWorldDateProvider')]
    #[Test]
    public function parsesRealWorldDate(string $headerLine, string $expected): void
    {
        static::assertSame($expected, Date::fromString($headerLine)->getFieldValue());
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        Date::fromString($headerLine);
    }

    #[DataProvider('unparseableDateProvider')]
    #[Test]
    public function rejectsUnparseableDate(string $headerLine, string $message): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Date::fromString($headerLine);
    }

    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for Date string');

        Date::fromString('Foo: bar');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function realWorldDateProvider(): array
    {
        return [
            'RFC 5322'                     => ['Date: Tue, 1 Jul 2003 10:52:37 +0200', self::RFC2822],
            'trailing zone comment'        => ['Date: Tue, 1 Jul 2003 10:52:37 +0200 (CEST)', self::RFC2822],
            'lower-case header name'       => ['date: Tue, 1 Jul 2003 10:52:37 +0200', self::RFC2822],
            'folded value'                 => ["Date: Tue, 1 Jul 2003\r\n 10:52:37 +0200", self::RFC2822],
            'no day of week, GMT'          => ['Date: 1 Jul 2003 10:52:37 GMT', 'Tue, 01 Jul 2003 10:52:37 +0000'],
            'obsolete two-digit year, EDT' => [
                'Date: Tue, 01 Jul 03 10:52:37 EDT',
                'Tue, 01 Jul 2003 10:52:37 -0400',
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'     => ["Date: xxx yyy\n"],
            'cr-lf'       => ["Date: xxx yyy\r\n"],
            'cr-lf twice' => ["Date: xxx yyy\r\n\r\n"],
            'multiline'   => ["Date: xxx\r\ny\r\nyy"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unparseableDateProvider(): array
    {
        return [
            'words'        => ['Date: xxx yyy', 'Invalid Date header value "xxx yyy"'],
            'empty'        => ['Date: ', 'Invalid Date header value ""'],
            'comment only' => ['Date: (CEST)', 'Invalid Date header value ""'],
        ];
    }

    private function makeDate(): Date
    {
        return new Date(new DateTimeImmutable(self::RFC2822));
    }
}
