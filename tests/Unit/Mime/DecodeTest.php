<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Decode;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(Decode::class)]
#[Group('unit')]
final class DecodeTest extends TestCase
{
    #[Test]
    public function readsMessageWithoutHeadersAsBody(): void
    {
        $headers = null;
        $body    = null;
        Decode::splitMessage('This is a message body', $headers, $body);

        static::assertSame('This is a message body', $body);
    }

    #[Test]
    public function givesNoHeadersForMessageWithoutHeaders(): void
    {
        $headers = null;
        $body    = null;
        Decode::splitMessage('This is a message body', $headers, $body);

        static::assertCount(0, $headers);
    }

    #[DataProvider('messageProvider')]
    #[Test]
    public function splitsHeadersFromBody(string $message, string $eol, string $subject, string $expectedBody): void
    {
        $headers = null;
        $body    = null;
        Decode::splitMessage($message, $headers, $body, $eol);

        static::assertSame([$subject, $expectedBody], [$headers?->get('Subject')?->getFieldValue(), $body]);
    }

    #[Test]
    public function readsTextWithColonLaterOnAsBody(): void
    {
        $headers = null;
        $body    = null;
        Decode::splitMessage("Hello world: no header\r\n\r\nx", $headers, $body);

        static::assertSame("Hello world: no header\r\n\r\nx", $body);
    }

    #[Test]
    public function splitsHeadersObject(): void
    {
        $headers = null;
        $body    = null;
        Decode::splitMessage(Headers::fromString('Subject: x'), $headers, $body);

        static::assertSame('x', $headers?->get('Subject')?->getFieldValue());
    }

    #[DataProvider('mimeProvider')]
    #[Test]
    public function splitsMultipartBody(string $body, array $expected): void
    {
        static::assertSame($expected, Decode::splitMime($body, 'b'));
    }

    /**
     * Malformed MIME: a missing closing boundary is reported rather than read past.
     */
    #[Test]
    public function refusesMultipartWithoutClosingBoundary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not a valid Mime Message: End Missing');

        Decode::splitMime("--b\n\nx", 'b');
    }

    #[Test]
    public function refusesEmptyBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The boundary may not be empty');

        Decode::splitMime("--\n--\n----\n", '');
    }

    /**
     * Resource exhaustion: a multipart may hold only so many parts.
     */
    #[Test]
    public function refusesTooManyParts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A multipart may hold at most 1000 parts');

        Decode::splitMime(str_repeat("--b\nx\n", Decode::MAX_PARTS + 1) . '--b--', 'b');
    }

    #[Test]
    public function splitsUpToTheLimit(): void
    {
        static::assertCount(Decode::MAX_PARTS, Decode::splitMime(
            str_repeat("--b\nx\n", Decode::MAX_PARTS) . '--b--',
            'b',
        ));
    }

    #[Test]
    public function splitsPartsIntoHeadersAndBodies(): void
    {
        $parts = Decode::splitMessageStruct("--b\r\nSubject: one\r\n\r\nfirst\r\n--b--", 'b');

        static::assertSame('first', $parts[0]['body'] ?? null);
    }

    #[Test]
    public function givesNullForBodyWithoutParts(): void
    {
        static::assertNull(Decode::splitMessageStruct('', 'xxx'));
    }

    #[DataProvider('headerFieldProvider')]
    #[Test]
    public function splitsHeaderField(?string $wanted, string $firstName, string|array|null $expected): void
    {
        static::assertSame($expected, Decode::splitHeaderField('foo; x=y; Y="x"', $wanted, $firstName));
    }

    #[Test]
    public function splitsContentType(): void
    {
        static::assertSame(
            ['type' => 'text/plain', 'charset' => 'utf-8'],
            Decode::splitContentType('text/plain; charset=utf-8'),
        );
    }

    #[Test]
    public function readsQuotedContentType(): void
    {
        static::assertSame('text/plain', Decode::splitContentType('"text/plain"', 'type'));
    }

    #[Test]
    public function refusesBrokenContinuation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a valid header field');
        $this->expectExceptionCode(0);

        Decode::splitHeaderField('a; name*1=x');
    }

    #[Test]
    public function decodesEncodedWords(): void
    {
        static::assertSame(
            '"Peter Müller" <peter@example.com>',
            Decode::decodeQuotedPrintable('=?UTF-8?Q?"Peter M=C3=BCller"?= <peter@example.com>'),
        );
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function messageProvider(): array
    {
        return [
            'crlf'               => ["Subject: a\r\n\r\nbody\r\n", "\r\n", 'a', "body\r\n"],
            'lf'                 => ["Subject: a\n\nbody", "\r\n", 'a', 'body'],
            'lf asked'           => ["Subject: a\n\nbody", "\n", 'a', 'body'],
            'crlf when lf asked' => ["Subject: a\r\n\r\nbody", "\n", 'a', 'body'],
            'no body'            => ["Subject: a\r\nTo: b@example.com", "\r\n", 'a', ''],
            'no body lf'         => ["Subject: a\nTo: b@example.com", "\r\n", 'a', ''],
            'folded'             => ["Subject: a\r\n b\r\n\r\nx", "\r\n", 'a b', 'x'],
            'own line end'       => ["Subject: a\r\rbody", "\r", 'a', 'body'],
        ];
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function mimeProvider(): array
    {
        return [
            'crlf'           => ["pre\r\n--b\r\none\r\n--b\r\ntwo\r\n--b--\r\nepi", ['one', 'two']],
            'lf'             => ["--b\none\n--b\ntwo\n--b--", ['one', 'two']],
            'keeps inner cr' => ["--b\r\na\rb\r\n--b--", ["a\rb"]],
            'padding'        => ["--b \t\r\none\r\n--b-- ", ['one']],
            'prefix only'    => ["--b\nx\n--bx\n--b--", ["x\n--bx"]],
            'no boundary'    => ['just text', []],
            'empty part'     => ["--b\n--b--", ['']],
        ];
    }

    /**
     * @return array<string, array{string|null, string, string|array<string, string>|null}>
     */
    public static function headerFieldProvider(): array
    {
        return [
            'all'        => [null, '0', ['0' => 'foo', 'x' => 'y', 'y' => 'x']],
            'named'      => ['x', '0', 'y'],
            'name case'  => ['Y', '0', 'x'],
            'first'      => ['foo', 'foo', 'foo'],
            'first case' => ['FOO', 'Foo', 'foo'],
            'missing'    => ['z', '0', null],
        ];
    }
}
