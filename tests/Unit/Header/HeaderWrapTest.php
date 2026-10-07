<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Bcc;
use Contenir\Mail\Header\HeaderWrap;
use Contenir\Mail\Header\UnstructuredInterface;
use Contenir\Mail\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function iconv_mime_decode;
use function str_repeat;
use function strlen;
use function substr;
use function wordwrap;

use const ICONV_MIME_DECODE_CONTINUE_ON_ERROR;

#[CoversClass(\Contenir\Mail\Header\HeaderWrap::class)]
class HeaderWrapTest extends TestCase
{
    #[Test]
    public function wrapUnstructuredHeaderAscii(): void
    {
        $string = str_repeat('foobarblahblahblah baz bat', 4);
        $header = $this->createMock(UnstructuredInterface::class);
        $header->expects($this->any())
            ->method('getEncoding')
            ->willReturn('ASCII');
        $expected = wordwrap($string, 78, "\r\n ");

        $test = HeaderWrap::wrap($string, $header);
        static::assertSame($expected, $test);
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-258
     */
    #[Test]
    public function wrapUnstructuredHeaderMime(): void
    {
        $string = str_repeat('foobarblahblahblah baz bat', 3);
        $header = $this->createMock(UnstructuredInterface::class);
        $header->expects($this->any())
            ->method('getEncoding')
            ->willReturn('UTF-8');
        $expected =
            "=?UTF-8?Q?foobarblahblahblah=20baz=20batfoobarblahblahblah=20baz=20?=\r\n"
            . ' =?UTF-8?Q?batfoobarblahblahblah=20baz=20bat?=';

        $test = HeaderWrap::wrap($string, $header);
        static::assertSame($expected, $test);
        static::assertSame($string, iconv_mime_decode($test, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
    }

    #[Test]
    public function wrapUnknownHeaderType(): void
    {
        $header = new Bcc('test@example.org');
        $value  = 'value unmodified by wrap function';
        static::assertSame($value, HeaderWrap::wrap($value, $header));
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-359
     */
    #[Test]
    public function mimeEncoding(): void
    {
        $string   = 'Umlauts: ä';
        $expected = '=?UTF-8?Q?Umlauts:=20=C3=A4?=';

        $test = HeaderWrap::mimeEncodeValue($string, 'UTF-8', 78);
        static::assertSame($expected, $test);
        static::assertSame($string, iconv_mime_decode($test, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8'));
    }

    #[Test]
    public function mimeDecoding(): void
    {
        $expected = str_repeat('foobarblahblahblah baz bat', 3);
        $encoded  = "=?UTF-8?Q?foobarblahblahblah=20baz=20batfoobarblahblahblah=20baz=20?=\r\n"
        . ' =?UTF-8?Q?batfoobarblahblahblah=20baz=20bat?=';

        $decoded = HeaderWrap::mimeDecodeValue($encoded);

        static::assertSame($expected, $decoded);
    }

    /**
     * Test that header lazy-loading doesn't break later header access
     * because undocumented behavior in iconv_mime_decode()
     *
     * @see https://github.com/zendframework/zend-mail/pull/187
     */
    #[Test]
    public function mimeDecodeBreakageBug(): void
    {
        $headerValue =
            'v=1; a=rsa-sha25; c=relaxed/simple; d=example.org; h='
            . "\r\n\t"
            . 'content-language:content-type:content-type:in-reply-to';
        $headers = "DKIM-Signature: {$headerValue}";

        $message = new Storage\Message(['headers' => $headers, 'content' => 'irrelevant']);
        $headers = $message->getHeaders();
        // calling toString will lazy load all headers
        // and would break DKIM-Signature header access
        $headers->toString();

        $header = $headers->get('DKIM-Signature');
        static::assertSame(
            'v=1; a=rsa-sha25; c=relaxed/simple; d=example.org;'
                . ' h= content-language:content-type:content-type:in-reply-to',
            $header->getFieldValue(),
        );
    }

    /**
     * Test that fails with HeaderWrap::canBeEncoded at lowest level:
     *   iconv_mime_encode(): Unknown error (7)
     *
     * which can be triggered as:
     *   $header = new GenericHeader($name, $value);
     */
    #[Test]
    public function canBeEncoded(): void
    {
        // @codingStandardsIgnoreStart
        $value = '[#77675] New Issue:xxxxxxxxx xxxxxxx xxxxxxxx xxxxxxxxxxxxx xxxxxxxxxx xxxxxxxx, tähtaeg xx.xx, xxxx';
        // @codingStandardsIgnoreEnd
        $res = HeaderWrap::canBeEncoded($value);
        static::assertTrue($res);
    }

    #[Test]
    public function multilineWithMultibyteSplitAcrossCharacter(): void
    {
        $originalValue = 'аф';

        static::assertSame(strlen($originalValue), 4);

        $part1 = base64_encode(substr($originalValue, 0, 3));
        $part2 = base64_encode(substr($originalValue, 3));

        $header = '=?utf-8?B?' . $part1 . '?==?utf-8?B?' . $part2 . '?=';

        static::assertSame(
            $originalValue,
            HeaderWrap::mimeDecodeValue($header),
        );
    }
}
