<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\CharsetConverter;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\BodySelector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function implode;
use function sprintf;
use function strlen;
use function substr;

#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(BodySelector::class)]
#[CoversClass(CharsetConverter::class)]
#[Group('unit')]
final class BodyTest extends TestCase
{
    #[Test]
    #[DataProvider('bodiesProvider')]
    public function findsTextAndHtmlBodies(string $raw, ?string $text, ?string $html): void
    {
        $part    = Part::fromString($raw);
        $message = Message::fromString($raw);

        static::assertSame($text, $part->getTextBody());
        static::assertSame($html, $part->getHtmlBody());
        static::assertSame($text, $message->getTextBody());
        static::assertSame($html, $message->getHtmlBody());
    }

    /**
     * @return array<string, array{string, ?string, ?string}>
     */
    public static function bodiesProvider(): array
    {
        $alternative = self::message('multipart/alternative', [
            self::leaf('text/plain', 'Plain'),
            self::leaf('text/html', '<p>Rich</p>'),
        ]);

        return [
            'single plain part'                  => [self::leaf('text/plain', 'Hello'), 'Hello', null],
            'single html part'                   => [self::leaf('text/html', '<b>Hi</b>'), null, '<b>Hi</b>'],
            'no Content-Type is plain text'      => ["Subject: x\r\n\r\nBare", 'Bare', null],
            'upper-case media type'              => [self::leaf('TEXT/Plain', 'Loud'), 'Loud', null],
            'alternative'                        => [$alternative, 'Plain', '<p>Rich</p>'],
            'later alternative of a type wins'   => [
                self::message('multipart/alternative', [
                    self::leaf('text/plain', 'First'),
                    self::leaf('text/plain', 'Second'),
                ]),
                'Second',
                null,
            ],
            'mixed with attachments'             => [
                self::message('multipart/mixed', [
                    self::leaf('application/pdf', 'PDF', "Content-Disposition: attachment; filename=a.pdf\r\n"),
                    $alternative,
                ]),
                'Plain',
                '<p>Rich</p>',
            ],
            'text attachment is skipped'         => [
                self::message('multipart/mixed', [
                    self::leaf('text/plain', 'notes', "Content-Disposition: ATTACHMENT; filename=n.txt\r\n"),
                    self::leaf('text/plain', 'Body'),
                ]),
                'Body',
                null,
            ],
            'inline text part is a body'         => [
                self::message('multipart/mixed', [
                    self::leaf('text/plain', 'Inline', "Content-Disposition: inline\r\n"),
                ]),
                'Inline',
                null,
            ],
            'only a text attachment'             => [
                self::leaf('text/plain', 'notes', "Content-Disposition: attachment; filename=n.txt\r\n"),
                null,
                null,
            ],
            'html in related inside alternative' => [
                self::message('multipart/alternative', [
                    self::leaf('text/plain', 'Plain'),
                    self::message('multipart/related', [
                        self::leaf('text/html', '<img src="cid:a">'),
                        self::leaf('image/png', 'PNG', "Content-ID: <a>\r\n"),
                    ]),
                ]),
                'Plain',
                '<img src="cid:a">',
            ],
            'related inside mixed'               => [
                self::message('multipart/mixed', [
                    self::message('multipart/related', [
                        self::leaf('text/html', '<p>Only</p>'),
                    ]),
                ]),
                null,
                '<p>Only</p>',
            ],
            'attached multipart is skipped'      => [
                self::message('multipart/mixed', [
                    self::message(
                        'multipart/alternative',
                        [
                            self::leaf('text/plain', 'Hidden'),
                        ],
                        "Content-Disposition: attachment\r\n",
                    ),
                ]),
                null,
                null,
            ],
            'no text part'                       => [
                self::message('multipart/mixed', [self::leaf('image/png', 'PNG')]),
                null,
                null,
            ],
            'other text subtype is not a body'   => [self::leaf('text/calendar', 'BEGIN:VCALENDAR'), null, null],
        ];
    }

    #[Test]
    #[DataProvider('encodedProvider')]
    public function decodesTransferEncodingAndCharset(string $headers, string $body, string $expected): void
    {
        $part = Part::fromString("{$headers}\r\n{$body}");

        static::assertSame($expected, $part->getTextBody());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function encodedProvider(): array
    {
        $type     = "Content-Type: text/plain; charset=%s\r\n";
        $encoding = "Content-Transfer-Encoding: %s\r\n";

        return [
            'quoted-printable Latin-1'  => [
                sprintf($type . $encoding, 'ISO-8859-1', 'quoted-printable'),
                'caf=E9',
                'café',
            ],
            'base64 Latin-1'            => [
                sprintf($type . $encoding, 'iso-8859-1', 'base64'),
                base64_encode("caf\xE9"),
                'café',
            ],
            'raw Windows-1252'          => [sprintf($type, 'windows-1252'), "\x93hi\x94", '“hi”'],
            'KOI8-R'                    => [sprintf($type, 'koi8-r'), "\xF0\xD2\xC9\xD7\xC5\xD4", 'Привет'],
            'Shift_JIS'                 => [sprintf($type, 'Shift_JIS'), "\x93\xfa\x96\x7b", '日本'],
            'base64 UTF-8'              => [
                sprintf($type . $encoding, 'UTF-8', 'base64'),
                base64_encode('Grüße'),
                'Grüße',
            ],
            'undeclared charset scrubs' => ['Content-Type: text/plain' . "\r\n", "caf\xE9", "caf\u{FFFD}"],
            'unknown charset scrubs'    => [sprintf($type, 'x-unknown'), "caf\xE9", "caf\u{FFFD}"],
            'disallowed charset scrubs' => [sprintf($type, 'ISO-2022-CN-EXT'), "caf\xE9", "caf\u{FFFD}"],
            'wrong charset scrubs'      => [sprintf($type, 'UTF-16LE'), "\x00\xD8", "\x00\u{FFFD}"],
            'invalid UTF-8 scrubs'      => [sprintf($type, 'UTF-8'), "a\xFFb", "a\u{FFFD}b"],
        ];
    }

    /**
     * @param list<string> $parts
     */
    private static function message(string $type, array $parts, string $headers = ''): string
    {
        // The subtype names the boundary, so nested multiparts of different types do not share one
        $boundary = 'b-' . substr($type, strlen('multipart/'));

        return (
            "Content-Type: {$type}; boundary=\"{$boundary}\"\r\n"
                . $headers
                . "\r\n--{$boundary}\r\n"
                . implode("\r\n--{$boundary}\r\n", $parts)
                . "\r\n--{$boundary}--\r\n"
        );
    }

    private static function leaf(string $type, string $body, string $headers = ''): string
    {
        return "Content-Type: {$type}\r\n" . $headers . "\r\n" . $body;
    }
}
