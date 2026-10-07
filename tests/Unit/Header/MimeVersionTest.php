<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Header\MimeVersion::class)]
class MimeVersionTest extends TestCase
{
    #[Test]
    public function settingManually(): void
    {
        $version = '2.0';
        $mime    = new Header\MimeVersion();
        $mime->setVersion($version);
        static::assertSame($version, $mime->getFieldValue());
    }

    #[Test]
    public function defaultVersion(): void
    {
        $mime = new Header\MimeVersion();
        static::assertSame('1.0', $mime->getVersion());
        static::assertSame('MIME-Version: 1.0', $mime->toString());
    }

    public static function headerLines(): array
    {
        return [
            'newline'   => ["MIME-Version: 5.0\nbar"],
            'cr-lf'     => ["MIME-Version: 2.0\r\n"],
            'cr-lf-wsp' => ["MIME-Version: 3\r\n\r\n.1"],
            'multiline' => ["MIME-Version: baz\r\nbar\r\nbau"],
        ];
    }

    #[Test]
    #[DataProvider('headerLines')]
    #[Group('ZF2015-04')]
    public function fromStringRaisesExceptionOnDetectionOfCrlfInjection(string $header): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $mime = Header\MimeVersion::fromString($header);
    }

    public static function invalidVersions(): array
    {
        return [
            'no-decimal'    => ['1'],
            'multi-decimal' => ['1.0.0'],
            'alpha'         => ['X.Y'],
            'non-alnum'     => ['Version 1.0'],
        ];
    }

    #[Test]
    #[DataProvider('invalidVersions')]
    #[Group('ZF2015-04')]
    public function raisesExceptionOnInvalidVersionFromSetVersion(string $value): void
    {
        $header = new Header\MimeVersion();
        $this->expectException(Exception\InvalidArgumentException::class);
        $header->setVersion($value);
    }

    #[Test]
    public function fromStringRaisesExceptionOnInvalidHeader(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for MIME-Version string');
        Header\MimeVersion::fromString('Foo: bar');
    }

    #[Test]
    public function defaultEncoding(): void
    {
        $header = new Header\MimeVersion();
        static::assertSame('ASCII', $header->getEncoding());
    }

    #[Test]
    public function setEncodingHasNoEffect(): void
    {
        $header = new Header\MimeVersion();
        $header->setEncoding('UTF-8');
        static::assertSame('ASCII', $header->getEncoding());
    }

    public static function unconventionalHeaderLinesProvider(): array
    {
        return [
            // Description => [header line, expected value]
            'mimeversion'  => ['MIMEVersion: 1.0', '1.0'],
            'mime_version' => ['MIME_Version: 1.0', '1.0'],
        ];
    }

    #[Test]
    #[DataProvider('unconventionalHeaderLinesProvider')]
    public function fromStringHandlesUnconventionalNames(string $headerLine, string $expected): void
    {
        $header = Header\MimeVersion::fromString($headerLine);
        static::assertInstanceOf(Header\MimeVersion::class, $header);
        static::assertSame('MIME-Version', $header->getFieldName());
        static::assertSame($expected, $header->getFieldValue());
    }
}
