<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_decode;
use function fclose;
use function file_get_contents;
use function fopen;
use function quoted_printable_decode;
use function realpath;
use function stream_get_contents;

class PartTest extends TestCase
{
    /**
     * MIME part test object
     *
     * @var Mime\Part
     */
    protected $part;

    /** @var string */
    protected $testText;

    protected function setUp(): void
    {
        $this->testText =
            'safdsafsa�lg ��gd�� sd�jg�sdjg�ld�gksd�gj�sdfg�dsj'
            . '�gjsd�gj�dfsjg�dsfj�djs�g kjhdkj fgaskjfdh gksjhgjkdh gjhfsdghdhgksdjhg';
        $this->part              = new Mime\Part($this->testText);
        $this->part->encoding    = Mime\Mime::ENCODING_BASE64;
        $this->part->type        = 'text/plain';
        $this->part->filename    = 'test.txt';
        $this->part->disposition = 'attachment';
        $this->part->charset     = 'iso8859-1';
        $this->part->id          = '4711';
    }

    #[Test]
    public function headers()
    {
        $expectedHeaders = [
            'Content-Type: text/plain',
            'Content-Transfer-Encoding: ' . Mime\Mime::ENCODING_BASE64,
            'Content-Disposition: attachment',
            'filename="test.txt"',
            'charset=iso8859-1',
            'Content-ID: <4711>',
        ];

        $actual = $this->part->getHeaders();

        foreach ($expectedHeaders as $expected) {
            static::assertStringContainsString($expected, $actual);
        }
    }

    #[Test]
    public function contentEncoding()
    {
        // Test with base64 encoding
        $content = $this->part->getContent();
        static::assertSame($this->testText, base64_decode($content));
        // Test with quotedPrintable Encoding:
        $this->part->encoding = Mime\Mime::ENCODING_QUOTEDPRINTABLE;
        $content              = $this->part->getContent();
        static::assertSame($this->testText, quoted_printable_decode($content));
        // Test with 8Bit encoding
        $this->part->encoding = Mime\Mime::ENCODING_8BIT;
        $content              = $this->part->getContent();
        static::assertSame($this->testText, $content);
    }

    #[Test]
    public function streamEncoding()
    {
        $testfile = realpath(__FILE__);
        $original = file_get_contents($testfile);

        // Test Base64
        $fp = fopen($testfile, 'rb');
        static::assertIsResource($fp);
        $part           = new Mime\Part($fp);
        $part->encoding = Mime\Mime::ENCODING_BASE64;
        $fp2            = $part->getEncodedStream();
        static::assertIsResource($fp2);
        $encoded = stream_get_contents($fp2);
        fclose($fp);
        static::assertSame(base64_decode($encoded), $original);

        // test QuotedPrintable
        $fp = fopen($testfile, 'rb');
        static::assertIsResource($fp);
        $part           = new Mime\Part($fp);
        $part->encoding = Mime\Mime::ENCODING_QUOTEDPRINTABLE;
        $fp2            = $part->getEncodedStream();
        static::assertIsResource($fp2);
        $encoded = stream_get_contents($fp2);
        fclose($fp);
        static::assertSame(quoted_printable_decode($encoded), $original);
    }

    #[Test]
    #[Group('Laminas-1491')]
    public function getRawContentFromPart()
    {
        static::assertSame($this->testText, $this->part->getRawContent());
    }

    /**
     * @link https://github.com/zendframework/zf2/issues/5428
     */
    #[Test]
    #[Group('5428')]
    public function contentEncodingWithStreamReadTwiceINaRow()
    {
        $testfile = realpath(__FILE__);
        $original = file_get_contents($testfile);

        $fp                       = fopen($testfile, 'rb');
        $part                     = new Mime\Part($fp);
        $part->encoding           = Mime\Mime::ENCODING_BASE64;
        $contentEncodedFirstTime  = $part->getContent();
        $contentEncodedSecondTime = $part->getContent();
        static::assertSame($contentEncodedFirstTime, $contentEncodedSecondTime);
        fclose($fp);

        $fp                       = fopen($testfile, 'rb');
        $part                     = new Mime\Part($fp);
        $part->encoding           = Mime\Mime::ENCODING_QUOTEDPRINTABLE;
        $contentEncodedFirstTime  = $part->getContent();
        $contentEncodedSecondTime = $part->getContent();
        static::assertSame($contentEncodedFirstTime, $contentEncodedSecondTime);
        fclose($fp);
    }

    #[Test]
    public function settersGetters()
    {
        $part = new Mime\Part();
        $part->setContent($this->testText)
            ->setEncoding(Mime\Mime::ENCODING_8BIT)
            ->setType('text/plain')
            ->setFilename('test.txt')
            ->setDisposition('attachment')
            ->setCharset('iso8859-1')
            ->setId('4711')
            ->setBoundary('frontier')
            ->setLocation('fiction1/fiction2')
            ->setLanguage('en')
            ->setIsStream(false)
            ->setFilters(['foo'])
            ->setDescription('foobar');

        static::assertSame($this->testText, $part->getContent());
        static::assertSame(Mime\Mime::ENCODING_8BIT, $part->getEncoding());
        static::assertSame('text/plain', $part->getType());
        static::assertSame('test.txt', $part->getFileName());
        static::assertSame('attachment', $part->getDisposition());
        static::assertSame('iso8859-1', $part->getCharset());
        static::assertSame('4711', $part->getId());
        static::assertSame('frontier', $part->getBoundary());
        static::assertSame('fiction1/fiction2', $part->getLocation());
        static::assertSame('en', $part->getLanguage());
        static::assertSame(false, $part->isStream());
        static::assertSame(['foo'], $part->getFilters());
        static::assertSame('foobar', $part->getDescription());
    }

    /** @psalm-return array<string, array{0: mixed}> */
    public static function invalidContentTypes(): array
    {
        return [
            'null'       => [null],
            'false'      => [false],
            'true'       => [true],
            'zero'       => [0],
            'int'        => [1],
            'zero-float' => [0.0],
            'float'      => [1.1],
            'array'      => [['string']],
            'object'     => [(object) ['content' => 'string']],
        ];
    }

    /**
     * @param mixed $content
     */
    #[Test]
    #[DataProvider('invalidContentTypes')]
    public function constructorRaisesInvalidArgumentExceptionForInvalidContentTypes($content)
    {
        $this->expectException(Mime\Exception\InvalidArgumentException::class);
        new Mime\Part($content);
    }

    /**
     * @param mixed $content
     */
    #[Test]
    #[DataProvider('invalidContentTypes')]
    public function setContentRaisesInvalidArgumentExceptionForInvalidContentTypes($content)
    {
        $part = new Mime\Part();
        $this->expectException(Mime\Exception\InvalidArgumentException::class);
        $part->setContent($content);
    }
}
