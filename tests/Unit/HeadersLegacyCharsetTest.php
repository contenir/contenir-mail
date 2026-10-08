<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Header\From;
use Contenir\Mail\Header\HeaderParser;
use Contenir\Mail\Headers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Headers holding raw bytes in a legacy charset rather than UTF-8, which
 * laminas-mail refused with "Invalid header value detected"
 * (laminas/laminas-mail#58, #234 and #263).
 */
#[CoversClass(Headers::class)]
#[CoversClass(HeaderParser::class)]
#[Group('unit')]
final class HeadersLegacyCharsetTest extends TestCase
{
    #[Test]
    #[DataProvider('legacyValueProvider')]
    public function readsLegacyBytesAsWindows1252(string $raw, string $expected): void
    {
        static::assertSame(
            $expected,
            Headers::fromString("Subject: {$raw}\r\n")->get('Subject')?->getFieldValue(),
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function legacyValueProvider(): array
    {
        return [
            'Latin-1 letter'                     => ["caf\xE9", 'café'],
            'Latin-1 word'                       => ["Gr\xFC\xDFe", 'Grüße'],
            'truncated UTF-8 sequence'           => ["Gr\xC3", 'GrÃ'],
            'overlong UTF-8 encoding'            => ["\xC0\xAF", 'À¯'],
            'Windows-1252 quotes'                => ["\x93ok\x94", '“ok”'],
            'byte Windows-1252 leaves undefined' => ["a\x81b", "a\u{FFFD}b"],
        ];
    }

    #[Test]
    public function readsLegacyDisplayNameAsAnAddressHeader(): void
    {
        $from = Headers::fromString("From: J\xF6 <jo@example.org>\r\n")->get('From');

        static::assertInstanceOf(From::class, $from);
        static::assertSame('Jö', $from->getAddressList()->get('jo@example.org')?->getName());
    }

    #[Test]
    public function writesLegacyHeaderBackAsEncodedWord(): void
    {
        static::assertSame(
            "Subject: =?UTF-8?Q?caf=C3=A9?=\r\n",
            Headers::fromString("Subject: caf\xE9\r\n")->toString(),
        );
    }
}
