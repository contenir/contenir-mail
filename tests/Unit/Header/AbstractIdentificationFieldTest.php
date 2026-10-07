<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\AbstractIdentificationField;
use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\InReplyTo;
use Contenir\Mail\Header\References;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractIdentificationField::class)]
#[CoversClass(InReplyTo::class)]
#[CoversClass(References::class)]
#[Group('unit')]
final class AbstractIdentificationFieldTest extends TestCase
{
    /**
     * @param class-string<AbstractIdentificationField> $className
     */
    #[DataProvider('fieldNameProvider')]
    #[Test]
    public function reportsFieldName(string $className, string $expected): void
    {
        static::assertSame($expected, (new $className('a@example.com'))->getFieldName());
    }

    /**
     * @param class-string<AbstractIdentificationField> $className
     * @param list<string> $ids
     */
    #[DataProvider('reversibleHeaderLineProvider')]
    #[Test]
    public function rendersHeaderLine(string $className, string $headerLine, array $ids): void
    {
        static::assertSame($headerLine, (new $className(...$ids))->toString());
    }

    /**
     * @param class-string<AbstractIdentificationField> $className
     * @param list<string> $ids
     */
    #[DataProvider('reversibleHeaderLineProvider')]
    #[DataProvider('parsedHeaderLineProvider')]
    #[Test]
    public function parsesIdsFromString(string $className, string $headerLine, array $ids): void
    {
        static::assertSame($ids, $className::fromString($headerLine)->getIds());
    }

    #[Test]
    public function readsIdsInAngleBracketsOnOneLine(): void
    {
        static::assertSame(
            '<a@example.com> <b@example.com>',
            (new References('a@example.com', 'b@example.com'))->getFieldValue(),
        );
    }

    #[Test]
    public function writesEachIdOnItsOwnFoldedLine(): void
    {
        static::assertSame(
            "<a@example.com>\r\n <b@example.com>",
            (new References('a@example.com', 'b@example.com'))->getEncodedFieldValue(),
        );
    }

    #[Test]
    public function stripsAngleBracketsFromGivenIds(): void
    {
        static::assertSame(['a@example.com'], (new InReplyTo('<a@example.com>'))->getIds());
    }

    #[Test]
    public function holdsNoIdsWhenGivenNone(): void
    {
        static::assertSame('References: ', (new References())->toString());
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('trailingLineBreakProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsTrailingLineBreakInId(array $ids): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');

        new References(...$ids);
    }

    /**
     * @param class-string<AbstractIdentificationField> $className
     * @param list<string> $ids
     */
    #[DataProvider('invalidIdProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsInvalidId(string $className, array $ids): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');

        new $className(...$ids);
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        References::fromString($headerLine);
    }

    /**
     * @param class-string<AbstractIdentificationField> $className
     */
    #[DataProvider('otherHeaderLineProvider')]
    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(string $className, string $headerLine, string $message): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $className::fromString($headerLine);
    }

    /**
     * @return array<string, array{class-string<AbstractIdentificationField>, string}>
     */
    public static function fieldNameProvider(): array
    {
        return [
            'In-Reply-To' => [InReplyTo::class, 'In-Reply-To'],
            'References'  => [References::class, 'References'],
        ];
    }

    /**
     * @return array<string, array{class-string<AbstractIdentificationField>, string, list<string>}>
     */
    public static function reversibleHeaderLineProvider(): array
    {
        return [
            'single reference'  => [
                References::class,
                'References: <1234@local.machine.example>',
                ['1234@local.machine.example'],
            ],
            'folded references' => [
                References::class,
                "References: <1234@local.machine.example>\r\n <3456@example.net>",
                ['1234@local.machine.example', '3456@example.net'],
            ],
            'in-reply-to'       => [InReplyTo::class, 'In-Reply-To: <3456@example.net>', ['3456@example.net']],
        ];
    }

    /**
     * @return array<string, array{class-string<AbstractIdentificationField>, string, list<string>}>
     */
    public static function parsedHeaderLineProvider(): array
    {
        return [
            'space separated'        => [
                References::class,
                'References: <1234@local.machine.example> <3456@example.net>',
                ['1234@local.machine.example', '3456@example.net'],
            ],
            'adjacent brackets'      => [
                References::class,
                'References: <a@example.com><b@example.com>',
                ['a@example.com', 'b@example.com'],
            ],
            'comma separated'        => [
                References::class,
                'References: <a@example.com>, <b@example.com>',
                ['a@example.com', 'b@example.com'],
            ],
            'bare comma separated'   => [
                References::class,
                'References: a@example.com,b@example.com',
                ['a@example.com', 'b@example.com'],
            ],
            'lower-case header name' => [References::class, 'references: <a@example.com>', ['a@example.com']],
            'empty'                  => [References::class, 'References: ', []],
            'several in-reply-to'    => [
                InReplyTo::class,
                'In-Reply-To: <a@example.com> <b@example.com>',
                ['a@example.com', 'b@example.com'],
            ],
        ];
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function trailingLineBreakProvider(): array
    {
        return [
            'first id'  => [["a@example.com\r\n", 'b@example.com']],
            'second id' => [['a@example.com', "b@example.com\r\n"]],
        ];
    }

    /**
     * @return array<string, array{class-string<AbstractIdentificationField>, list<string>}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'cr-lf inside reference'    => [References::class, ["1234@local\r\n.machine.example"]],
            'header injected in second' => [
                References::class,
                ['a@example.com', "b@example.com\r\nBcc: x@example.net"],
            ],
            'cr-lf inside in-reply-to'  => [InReplyTo::class, ["3456@example\r\n.net"]],
            'space'                     => [References::class, ['a b@example.com']],
            'empty'                     => [References::class, ['']],
            'only angle brackets'       => [InReplyTo::class, ['<>']],
            'inner angle bracket'       => [References::class, ['a<b@example.com']],
            'non-ASCII'                 => [References::class, ['á@example.com']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'   => ["References: <a@example.com>\n<b@example.com>"],
            'cr-lf'     => ["References: <a@example.com>\r\n<b@example.com>"],
            'multiline' => ["References: <a@example.com>\r\nBcc: x@example.net\r\n"],
        ];
    }

    /**
     * @return array<string, array{class-string<AbstractIdentificationField>, string, string}>
     */
    public static function otherHeaderLineProvider(): array
    {
        return [
            'unrelated header for references'  => [
                References::class,
                'Foo: bar',
                'Invalid header line for "References" string',
            ],
            'unrelated header for in-reply-to' => [
                InReplyTo::class,
                'Foo: bar',
                'Invalid header line for "In-Reply-To" string',
            ],
            'references line for in-reply-to'  => [
                InReplyTo::class,
                'References: <a@example.com>',
                'Invalid header line for "In-Reply-To" string',
            ],
        ];
    }
}
