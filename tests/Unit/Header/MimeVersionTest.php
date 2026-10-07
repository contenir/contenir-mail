<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\MimeVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MimeVersion::class)]
#[Group('unit')]
final class MimeVersionTest extends TestCase
{
    #[Test]
    public function defaultsToVersionOne(): void
    {
        static::assertSame('1.0', (new MimeVersion())->getVersion());
    }

    #[Test]
    public function rendersDefaultHeaderLine(): void
    {
        static::assertSame('MIME-Version: 1.0', (new MimeVersion())->toString());
    }

    #[Test]
    public function reportsFieldName(): void
    {
        static::assertSame('MIME-Version', (new MimeVersion())->getFieldName());
    }

    #[Test]
    public function keepsVersionItWasGiven(): void
    {
        static::assertSame('2.0', (new MimeVersion('2.0'))->getFieldValue());
    }

    #[Test]
    public function rendersVersionAsEncodedFieldValue(): void
    {
        static::assertSame('2.0', (new MimeVersion('2.0'))->getEncodedFieldValue());
    }

    #[DataProvider('invalidVersionProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsInvalidVersion(string $version): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid MIME-Version value detected');

        new MimeVersion($version);
    }

    #[DataProvider('headerLineProvider')]
    #[Test]
    public function parsesVersionFromString(string $headerLine, string $expected): void
    {
        static::assertSame($expected, MimeVersion::fromString($headerLine)->getVersion());
    }

    #[DataProvider('injectedHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function rejectsHeaderLineWithLineBreaksInValue(string $headerLine): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        MimeVersion::fromString($headerLine);
    }

    #[Test]
    public function rejectsHeaderLineOfAnotherHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for MIME-Version string');

        MimeVersion::fromString('Foo: bar');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidVersionProvider(): array
    {
        return [
            'no decimal'       => ['1'],
            'several decimals' => ['1.0.0'],
            'letters'          => ['X.Y'],
            'leading word'     => ['Version 1.0'],
            'zero major'       => ['0.9'],
            'empty'            => [''],
            'trailing cr-lf'   => ["1.0\r\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function headerLineProvider(): array
    {
        return [
            'conventional name'       => ['MIME-Version: 1.0', '1.0'],
            'other version'           => ['MIME-Version: 2.0', '2.0'],
            'lower-case name'         => ['mime-version: 1.0', '1.0'],
            'no separator'            => ['MIMEVersion: 1.0', '1.0'],
            'underscore separator'    => ['MIME_Version: 1.0', '1.0'],
            'unreadable falls back'   => ['MIME-Version: garbage', '1.0'],
            'empty falls back'        => ['MIME-Version: ', '1.0'],
            'comment falls back'      => ['MIME-Version: 2.0 (produced by MetaSend)', '1.0'],
            'leading zero falls back' => ['MIME-Version: 01.0', '1.0'],
            'trailing whitespace'     => ["MIME-Version: 2.0 \t", '2.0'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedHeaderLineProvider(): array
    {
        return [
            'newline'     => ["MIME-Version: 5.0\nbar"],
            'cr-lf'       => ["MIME-Version: 2.0\r\n"],
            'cr-lf twice' => ["MIME-Version: 3\r\n\r\n.1"],
            'multiline'   => ["MIME-Version: baz\r\nbar\r\nbau"],
        ];
    }
}
