<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Exception as MimeException;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\PartInterface;
use Contenir\Mail\Mime\PartWriter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function array_map;
use function implode;
use function strtr;

/**
 * The body of a message: the MIME tree its builders assemble, a body set directly, and how both are written.
 */
#[CoversClass(Message::class)]
#[Group('unit')]
final class MessageBodyTest extends TestCase
{
    private const string FIXED_DATE = 'Date: Mon, 01 Jan 2024 00:00:00 +0000';

    /**
     * @param list<string> $steps
     */
    #[DataProvider('builderTreeProvider')]
    #[Test]
    public function buildersAssembleMimeTree(array $steps, string $expected): void
    {
        $message = $this->build($this->makeMessage(), $steps);

        static::assertSame($expected, self::describe(self::mimeBody($message)));
    }

    /**
     * @param list<string> $steps
     */
    #[DataProvider('builderContentTypeProvider')]
    #[Test]
    public function buildersSetContentTypeOfMessage(array $steps, string $expected): void
    {
        $message = $this->build($this->makeMessage(), $steps);
        $body    = self::mimeBody($message);
        $values  = $body instanceof Multipart ? ['{boundary}' => $body->getBoundary()] : [];

        static::assertSame(
            strtr($expected, $values),
            $message->getHeaders()->get('Content-Type')?->getFieldValue(),
        );
    }

    #[DataProvider('charsetProvider')]
    #[Test]
    public function textBuilderDeclaresCharsetOfText(string $method, string $expected): void
    {
        $message = $this->makeMessage()->{$method}("caf\xE9", 'ISO-8859-1');

        static::assertSame($expected, $message->getHeaders()->get('Content-Type')?->getFieldValue());
    }

    #[Test]
    public function textBuilderEncodesTextInItsCharset(): void
    {
        $message = $this->makeMessage()->setText("caf\xE9", 'ISO-8859-1');

        static::assertSame('caf=E9', $message->getBodyText());
    }

    #[Test]
    public function htmlBuilderReplacesEarlierHtml(): void
    {
        $message = $this->makeMessage()->setHtml('<p>first</p>')->setHtml('<p>second</p>');

        static::assertSame('<p>second</p>', self::mimeBody($message)->getContent());
    }

    #[Test]
    public function textBuilderReplacesEarlierText(): void
    {
        $message = $this->makeMessage()->setText('first')->setText('second');

        static::assertSame('second', self::mimeBody($message)->getContent());
    }

    #[Test]
    public function embeddingResourceWithoutContentIdIsRejected(): void
    {
        $this->expectException(MimeException\InvalidArgumentException::class);
        $this->expectExceptionMessage('An embedded part needs a Content-ID for the HTML to refer to');

        $this->makeMessage()->embed(Attachment::fromString('PNG', 'logo.png', 'image/png'));
    }

    #[Test]
    public function writingEmbeddedResourceWithoutHtmlIsRejected(): void
    {
        $message = $this->makeMessage()
            ->setText('Hello')
            ->embed(Attachment::inline('PNG', id: 'logo', type: 'image/png'));

        $this->expectException(MimeException\RuntimeException::class);
        $this->expectExceptionMessage('Embedded resources need an HTML body to refer to them');

        $message->toString();
    }

    #[Test]
    public function builtBodyIsTheSameTreeOnEveryCall(): void
    {
        $message = $this->makeMessage()->setText('Hello')->setHtml('<p>Hello</p>');

        static::assertSame($message->getBody(), $message->getBody());
    }

    #[Test]
    public function headersAndBodyTextShareTheBuiltBoundary(): void
    {
        $message = $this->makeMessage()->setText('Hello')->setHtml('<p>Hello</p>');

        $contentType = $message->getHeaders()->get('Content-Type');
        static::assertInstanceOf(ContentType::class, $contentType);
        static::assertStringEndsWith("--{$contentType->getParameter('boundary')}--", $message->getBodyText());
    }

    #[DataProvider('builderStepProvider')]
    #[Test]
    public function anotherBuilderCallBuildsANewTree(string $step): void
    {
        $message = $this->makeMessage()->setText('Hello')->setHtml('<p>Hello</p>');
        $before  = $message->getBody();

        $this->build($message, [$step]);

        static::assertNotSame($before, $message->getBody());
    }

    #[Test]
    public function bodySetDirectlyTakesPrecedenceOverBuilders(): void
    {
        $message = $this->makeMessage()->setText('Hello')->setBody('plain');

        static::assertSame('plain', $message->getBody());
    }

    #[Test]
    public function buildersDoNotReplaceBodySetDirectly(): void
    {
        $message = $this->makeMessage()->setBody('plain')->setText('Hello');

        static::assertSame('plain', $message->getBody());
    }

    #[Test]
    public function clearingBodySetDirectlyReturnsToBuilders(): void
    {
        $message = $this->makeMessage()->setText('Hello')->setBody('plain')->setBody(null);

        static::assertSame('Hello', self::mimeBody($message)->getContent());
    }

    #[DataProvider('nonMimeBodyProvider')]
    #[Test]
    public function nonMimeBodyAddsNoMimeVersion(string|Stringable|null $body): void
    {
        $message = $this->makeMessage()->setBody($body);

        static::assertFalse($message->getHeaders()->has('MIME-Version'));
    }

    #[DataProvider('nonMimeBodyProvider')]
    #[Test]
    public function nonMimeBodyAddsNoContentType(string|Stringable|null $body): void
    {
        $message = $this->makeMessage()->setBody($body);

        static::assertFalse($message->getHeaders()->has('Content-Type'));
    }

    #[Test]
    public function mimeHeadersAreNotStoredOnMessage(): void
    {
        $message = $this->makeMessage()->setText('Hello');
        $message->getHeaders();

        $message->setBody('plain');

        static::assertSame(self::FIXED_DATE . "\r\n", $message->getHeaders()->toString());
    }

    #[Test]
    public function headersSetOnMessageAreKeptAsGivenWithMimeBody(): void
    {
        $headers = new Headers(new GenericHeader('X-Test', 'value'));

        $this->makeMessage()->setHeaders($headers)->setText('Hello')->getHeaders();

        static::assertSame("X-Test: value\r\n", $headers->toString());
    }

    #[Test]
    public function bodyContentTypeReplacesStoredContentType(): void
    {
        $message = $this->makeMessage()
            ->setHeader(new ContentType('text/plain'))
            ->setBody(
                new Multipart(MultipartType::Alternative, [Part::text('foo'), Part::html('<b>foo</b>')], 'foo-bar'),
            );

        static::assertSame(
            ['multipart/alternative; boundary="foo-bar"'],
            $this->fieldValues($message, 'Content-Type'),
        );
    }

    #[Test]
    public function singlePartMimeBodySetsMimeVersion(): void
    {
        $message = $this->makeMessage()->setBody(Part::html('<b>foo</b>'));

        static::assertSame('1.0', $message->getHeaders()->get('MIME-Version')?->getFieldValue());
    }

    #[Test]
    public function singlePartMimeBodySetsContentTypeOfPart(): void
    {
        $message = $this->makeMessage()->setBody(new Part('<b>foo</b>', type: 'text/html'));

        static::assertSame('text/html', $message->getHeaders()->get('Content-Type')?->getFieldValue());
    }

    #[Test]
    public function singlePartUtf8MimeBodySetsContentHeadersOfPart(): void
    {
        $message = $this->makeMessage()->setBody(Part::text('UTF-8 TestString: AaÜüÄäÖöß', 'utf-8'));

        static::assertStringContainsString(
            "Content-Type: text/plain;\r\n charset=\"utf-8\"\r\nContent-Transfer-Encoding: quoted-printable\r\n",
            $message->getHeaders()->toString(),
        );
    }

    #[Test]
    public function singlePartAttachmentBodySetsItsDisposition(): void
    {
        $message = $this->makeMessage()->setBody(Attachment::fromString('abc', 'a.txt', 'text/plain'));

        static::assertSame(
            'attachment; filename="a.txt"',
            $message->getHeaders()->get('Content-Disposition')?->getFieldValue(),
        );
    }

    #[Test]
    public function bodyTextOfSinglePartIsItsEncodedContent(): void
    {
        $message = $this->makeMessage()->setText('Grüße');

        static::assertSame('Gr=C3=BC=C3=9Fe', $message->getBodyText());
    }

    #[Test]
    public function multipartMimeBodySetsMimeVersion(): void
    {
        $message = $this->makeMessage()->setBody($this->makeMultipartBody());

        static::assertSame('1.0', $message->getHeaders()->get('MIME-Version')?->getFieldValue());
    }

    #[Test]
    public function multipartMimeBodySetsContentTypeWithBoundary(): void
    {
        $message = $this->makeMessage()->setBody($this->makeMultipartBody());

        static::assertSame(
            "Content-Type: multipart/alternative;\r\n boundary=\"foo-bar\"",
            $message->getHeaders()->get('Content-Type')?->toString(),
        );
    }

    #[Test]
    public function bodyTextOfMultipartIsPreambleThenWrittenParts(): void
    {
        $body = $this->makeMultipartBody();

        static::assertSame(
            "This is a multi-part message in MIME format.\r\n\r\n" . PartWriter::body($body),
            $this->makeMessage()->setBody($body)->getBodyText(),
        );
    }

    #[Test]
    public function writesPinnedMultipartBodyText(): void
    {
        $message = $this->makeMessage()->setBody($this->makeMultipartBody());

        static::assertSame(
            "This is a multi-part message in MIME format.\r\n"
                . "\r\n"
                . "--foo-bar\r\n"
                . "Content-Type: text/plain;\r\n"
                . " charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "foo\r\n"
                . "--foo-bar\r\n"
                . "Content-Type: text/html;\r\n"
                . " charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "<b>foo</b>\r\n"
                . '--foo-bar--',
            $message->getBodyText(),
        );
    }

    #[Test]
    public function writesPinnedNestedMultipartBodyText(): void
    {
        $message = $this->makeMessage()->setBody($this->makeNestedMultipartBody());

        static::assertSame(
            "This is a multi-part message in MIME format.\r\n"
                . "\r\n"
                . "--outer\r\n"
                . "Content-Type: multipart/alternative;\r\n"
                . " boundary=\"foo-bar\"\r\n"
                . "\r\n"
                . "--foo-bar\r\n"
                . "Content-Type: text/plain;\r\n"
                . " charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "foo\r\n"
                . "--foo-bar\r\n"
                . "Content-Type: text/html;\r\n"
                . " charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . "<b>foo</b>\r\n"
                . "--foo-bar--\r\n"
                . "--outer\r\n"
                . "Content-Type: text/plain\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"a.txt\"\r\n"
                . "\r\n"
                . "YWJj\r\n"
                . '--outer--',
            $message->getBodyText(),
        );
    }

    #[Test]
    public function writesMimeHeadersWithMultipartBody(): void
    {
        $message = $this->makeMessage()->setBody($this->makeMultipartBody());

        static::assertSame(
            self::FIXED_DATE
                . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: multipart/alternative;\r\n"
                . " boundary=\"foo-bar\"\r\n"
                . "\r\n"
                . $message->getBodyText(),
            $message->toString(),
        );
    }

    #[Test]
    public function writesMimeHeadersWithBuiltBody(): void
    {
        $message = $this->makeMessage()->setText('Hello');

        static::assertSame(
            self::FIXED_DATE
                . "\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain;\r\n"
                . " charset=\"UTF-8\"\r\n"
                . "Content-Transfer-Encoding: quoted-printable\r\n"
                . "\r\n"
                . 'Hello',
            $message->toString(),
        );
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function builderTreeProvider(): array
    {
        return [
            'text only'                            => [['text'], 'text/plain'],
            'HTML only'                            => [['html'], 'text/html'],
            'text and HTML'                        => [
                ['text', 'html'],
                'multipart/alternative(text/plain, text/html)',
            ],
            'HTML and embedded'                    => [['html', 'embed'], 'multipart/related(text/html, image/png)'],
            'text, HTML and embedded'              => [
                ['text', 'html', 'embed'],
                'multipart/alternative(text/plain, multipart/related(text/html, image/png))',
            ],
            'text, HTML, embedded and attachments' => [
                ['attach', 'text', 'embed', 'html', 'attach csv'],
                'multipart/mixed(multipart/alternative(text/plain, multipart/related(text/html, image/png)), '
                    . 'application/pdf, text/csv)',
            ],
            'text and attachment'                  => [
                ['text', 'attach'],
                'multipart/mixed(text/plain, application/pdf)',
            ],
            'attachments only'                     => [
                ['attach', 'attach csv'],
                'multipart/mixed(application/pdf, text/csv)',
            ],
        ];
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function builderContentTypeProvider(): array
    {
        return [
            'text only'                            => [['text'], 'text/plain; charset="UTF-8"'],
            'HTML only'                            => [['html'], 'text/html; charset="UTF-8"'],
            'text and HTML'                        => [
                ['text', 'html'],
                'multipart/alternative; boundary="{boundary}"',
            ],
            'HTML and embedded'                    => [
                ['html', 'embed'],
                'multipart/related; boundary="{boundary}"; type="text/html"',
            ],
            'text, HTML, embedded and attachments' => [
                ['text', 'html', 'embed', 'attach'],
                'multipart/mixed; boundary="{boundary}"',
            ],
            'attachments only'                     => [['attach'], 'multipart/mixed; boundary="{boundary}"'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function builderStepProvider(): array
    {
        return [
            'text'   => ['text'],
            'HTML'   => ['html'],
            'embed'  => ['embed'],
            'attach' => ['attach'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function charsetProvider(): array
    {
        return [
            'text' => ['setText', 'text/plain; charset="ISO-8859-1"'],
            'HTML' => ['setHtml', 'text/html; charset="ISO-8859-1"'],
        ];
    }

    /**
     * @return array<string, array{string|Stringable|null}>
     */
    public static function nonMimeBodyProvider(): array
    {
        return [
            'no body'           => [null],
            'string'            => ['plain'],
            'stringable object' => [new TestAsset\StringSerializableObject('plain')],
        ];
    }

    private function makeMultipartBody(): Multipart
    {
        return new Multipart(MultipartType::Alternative, [Part::text('foo'), Part::html('<b>foo</b>')], 'foo-bar');
    }

    private function makeNestedMultipartBody(): Multipart
    {
        return new Multipart(
            MultipartType::Mixed,
            [$this->makeMultipartBody(), Attachment::fromString('abc', 'a.txt', 'text/plain')],
            'outer',
        );
    }

    /**
     * @param list<string> $steps
     */
    private function build(Message $message, array $steps): Message
    {
        foreach ($steps as $step) {
            match ($step) {
                'text'       => $message->setText('Hello'),
                'html'       => $message->setHtml('<p>Hello <img src="cid:logo"></p>'),
                'embed'      => $message->embed(Attachment::inline('PNG', 'logo', 'image/png')),
                'attach csv' => $message->attach(Attachment::fromString('a,b', 'data.csv', 'text/csv')),
                default      => $message->attach(Attachment::fromString('%PDF', 'report.pdf', 'application/pdf')),
            };
        }

        return $message;
    }

    private static function mimeBody(Message $message): PartInterface
    {
        $body = $message->getBody();
        static::assertInstanceOf(PartInterface::class, $body);

        return $body;
    }

    /**
     * The tree as its content types, children in brackets.
     */
    private static function describe(PartInterface $part): string
    {
        $contentType = $part->getHeaders()->get('Content-Type');
        static::assertInstanceOf(ContentType::class, $contentType);
        if (! $part->isMultipart()) {
            return $contentType->getType();
        }

        $children = array_map(self::describe(...), $part->getParts());

        return $contentType->getType() . '(' . implode(', ', $children) . ')';
    }

    private function makeMessage(): Message
    {
        return new Message(clock: new TestAsset\FixedClock(new DateTimeImmutable('2024-01-01T00:00:00Z')));
    }

    /**
     * @return list<string>
     */
    private function fieldValues(Message $message, string $name): array
    {
        return array_map(
            static fn($header): string => $header->getFieldValue(),
            $message->getHeaders()->all($name),
        );
    }
}
