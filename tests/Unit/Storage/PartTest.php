<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Header\HeaderName;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\PartWriter;
use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Decoder;
use Contenir\Mail\Storage\Part\Lines;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Storage\Part\Window;
use Contenir\Mail\Storage\TreeIterator;
use Contenir\Mail\Tests\TestAsset\Storage\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_keys;
use function array_map;
use function base64_encode;
use function chunk_split;
use function fclose;
use function fopen;
use function fwrite;
use function iterator_to_array;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function quoted_printable_encode;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function strlen;

#[CoversClass(Part::class)]
#[CoversClass(Content::class)]
#[CoversClass(Decoder::class)]
#[CoversClass(Lines::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[CoversClass(Window::class)]
#[CoversClass(TreeIterator::class)]
#[Group('unit')]
final class PartTest extends TestCase
{
    #[Test]
    public function readsHeaders(): void
    {
        static::assertSame(
            'Hello',
            Part::fromString("Subject: Hello\r\n\r\nbody")->getHeaders()->get('Subject')?->getFieldValue(),
        );
    }

    #[DataProvider('contentProvider')]
    #[Test]
    public function decodesContent(string $raw, string $expected): void
    {
        static::assertSame($expected, Part::fromString($raw)->getContent());
    }

    #[DataProvider('encodedContentProvider')]
    #[Test]
    public function keepsContentAsTransferredWithCrlfLineBreaks(string $raw, string $expected): void
    {
        static::assertSame($expected, Part::fromString($raw)->getEncodedContent());
    }

    #[DataProvider('contentTypeProvider')]
    #[Test]
    public function readsContentType(string $raw, string $expected): void
    {
        static::assertSame($expected, Part::fromString($raw)->getContentType());
    }

    #[Test]
    public function readsCharset(): void
    {
        static::assertSame(
            'ISO-8859-1',
            Part::fromString("Content-Type: text/plain; charset=ISO-8859-1\r\n\r\nx")->getCharset(),
        );
    }

    #[Test]
    public function hasNoCharsetWithoutContentType(): void
    {
        static::assertNull(Part::fromString("Subject: x\r\n\r\nx")->getCharset());
    }

    #[DataProvider('filenameProvider')]
    #[Test]
    public function readsFilenameAsWritten(string $raw, ?string $expected): void
    {
        static::assertSame($expected, Part::fromString($raw)->getFilename());
    }

    /**
     * Path traversal: an attachment's file name is reduced to a safe base name.
     */
    #[Test]
    public function reducesFilenameToSafeBaseName(): void
    {
        $part = Part::fromString("Content-Disposition: attachment; filename=\"../../.ssh/authorized_keys\"\r\n\r\nx");

        static::assertSame('authorized_keys', $part->getSafeFilename());
    }

    #[Test]
    public function hasNoSafeFilenameWithoutFilename(): void
    {
        static::assertNull(Part::fromString("Subject: x\r\n\r\nx")->getSafeFilename());
    }

    #[Test]
    public function splitsMultipartIntoParts(): void
    {
        $parts = Part::fromString(Fixtures::MULTIPART)->getParts();

        static::assertSame(
            ['first', '<p>second</p>'],
            array_map(static fn(Part $part): string => $part->getContent(), $parts),
        );
    }

    #[Test]
    public function readsPartHeaders(): void
    {
        static::assertSame('text/html', Part::fromString(Fixtures::MULTIPART)->getPart(2)->getContentType());
    }

    #[Test]
    public function countsParts(): void
    {
        static::assertSame(2, Part::fromString(Fixtures::MULTIPART)->countParts());
    }

    #[Test]
    public function splitsPartsOnce(): void
    {
        $part = Part::fromString(Fixtures::MULTIPART);

        static::assertSame($part->getParts()[0], $part->getParts()[0]);
    }

    #[Test]
    public function isMultipartWithBoundary(): void
    {
        static::assertTrue(Part::fromString(Fixtures::MULTIPART)->isMultipart());
    }

    #[Test]
    public function hasEmptyContentWhenMultipart(): void
    {
        static::assertSame('', Part::fromString(Fixtures::MULTIPART)->getContent());
    }

    #[Test]
    public function hasEmptyEncodedContentWhenMultipart(): void
    {
        static::assertSame('', Part::fromString(Fixtures::MULTIPART)->getEncodedContent());
    }

    #[Test]
    public function hasNoPartsWhenLeaf(): void
    {
        static::assertSame([], Part::fromString("Content-Type: text/plain\r\n\r\nx")->getParts());
    }

    #[Test]
    public function refusesPartNumberThatDoesNotExist(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no part 3');

        Part::fromString(Fixtures::MULTIPART)->getPart(3);
    }

    #[Test]
    public function refusesPartNumberZero(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no part 0');

        Part::fromString(Fixtures::MULTIPART)->getPart(0);
    }

    /**
     * Malformed MIME: a multipart that names no boundary is read as one leaf.
     */
    #[DataProvider('unsplittableMultipartProvider')]
    #[Test]
    public function readsMultipartWithoutUsableBoundaryAsLeaf(string $contentType): void
    {
        static::assertFalse(
            Part::fromString("Content-Type: {$contentType}\r\n\r\n--b\r\n\r\nx\r\n--b--")->isMultipart(),
        );
    }

    /**
     * Malformed MIME: a missing closing boundary ends the last part at the end of the body, without looping.
     */
    #[Test]
    public function endsLastPartAtEndWhenClosingBoundaryIsMissing(): void
    {
        $part = Part::fromString("Content-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\n\r\none\r\n--b\r\n\r\ntwo");

        static::assertSame('two', $part->getPart(2)->getContent());
    }

    #[Test]
    public function hasNoPartsWhenBoundaryNeverAppears(): void
    {
        static::assertSame(
            [],
            Part::fromString("Content-Type: multipart/mixed; boundary=b\r\n\r\njust text")->getParts(),
        );
    }

    #[Test]
    public function ignoresLinesThatOnlyStartWithTheBoundary(): void
    {
        $part = Part::fromString("Content-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\n\r\n--bx\r\n--b--");

        static::assertSame('--bx', $part->getPart(1)->getContent());
    }

    #[Test]
    public function acceptsTransportPaddingAfterBoundary(): void
    {
        $part = Part::fromString("Content-Type: multipart/mixed; boundary=b\r\n\r\n--b \t\r\n\r\nx\r\n--b-- \r\n");

        static::assertSame('x', $part->getPart(1)->getContent());
    }

    #[Test]
    public function readsMultipartWithBareLineFeeds(): void
    {
        $part = Part::fromString(
            "Content-Type: multipart/mixed; boundary=b\n\n--b\nContent-Type: text/html\n\n<p>x</p>\n--b--\n",
        );

        static::assertSame('<p>x</p>', $part->getPart(1)->getContent());
    }

    #[Test]
    public function ignoresBoundaryInsideALongLine(): void
    {
        $line = str_repeat('a', times: Content::CHUNK) . "--b\r\n";
        $part = Part::fromString("Content-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\n\r\n{$line}--b--");

        static::assertSame(1, $part->countParts());
    }

    /**
     * Resource exhaustion: parts may nest only so deep.
     */
    #[Test]
    public function refusesPartsNestedTooDeeply(): void
    {
        $part = Part::fromString(Fixtures::nested(Part::MAX_DEPTH + 1));
        for ($depth = 0; $depth < Part::MAX_DEPTH; ++$depth) {
            $part = $part->getPart(1);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parts may nest at most 32 deep');

        $part->getParts();
    }

    #[Test]
    public function readsPartsNestedToTheLimit(): void
    {
        $part = Part::fromString(Fixtures::nested(Part::MAX_DEPTH));
        for ($depth = 0; $depth < Part::MAX_DEPTH; ++$depth) {
            $part = $part->getPart(1);
        }

        static::assertSame('leaf', $part->getContent());
    }

    /**
     * Resource exhaustion: a multipart may hold only so many parts.
     */
    #[Test]
    public function refusesTooManyParts(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A multipart may hold at most 1000 parts');

        Part::fromString(Fixtures::manyParts(MultipartSplitter::MAX_PARTS + 1))->getParts();
    }

    #[Test]
    public function readsPartsUpToTheLimit(): void
    {
        static::assertSame(
            MultipartSplitter::MAX_PARTS,
            Part::fromString(Fixtures::manyParts(MultipartSplitter::MAX_PARTS))->countParts(),
        );
    }

    /**
     * Resource exhaustion: a header block is read only up to a size limit, however long its lines.
     */
    #[Test]
    public function refusesHeaderBlockLargerThanTheLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The header block is larger than 1048576 bytes');

        Part::fromString('X-Big: ' . str_repeat('a', times: MimeParser::MAX_HEADER_BYTES) . "\r\n\r\nbody");
    }

    #[Test]
    public function readsHeaderLineLongerThanAChunk(): void
    {
        $value = str_repeat('a', times: Content::CHUNK * 2);

        static::assertSame(
            $value,
            Part::fromString("X-Long: {$value}\r\n\r\nbody")->getHeaders()->get('X-Long')?->getFieldValue(),
        );
    }

    #[Test]
    public function readsBodyAfterHeaderLineLongerThanAChunk(): void
    {
        $value = str_repeat('a', times: Content::CHUNK * 2);

        static::assertSame('body', Part::fromString("X-Long: {$value}\r\n\r\nbody")->getContent());
    }

    #[Test]
    public function readsHeaderBlockOfExactlyTheLimit(): void
    {
        $header = 'X-Big: ' . str_repeat('a', times: MimeParser::MAX_HEADER_BYTES - 9) . "\r\n";

        static::assertSame('body', Part::fromString("{$header}\r\nbody")->getContent());
    }

    #[Test]
    public function readsTextWithColonLaterOnAsBody(): void
    {
        static::assertSame("Hello world: x\r\n\r\nbody", Part::fromString("Hello world: x\r\n\r\nbody")->getContent());
    }

    #[Test]
    public function acceptsBoundaryOfTheLengthLimit(): void
    {
        $boundary = str_repeat('b', times: 200);
        $part     = Part::fromString(
            "Content-Type: multipart/mixed; boundary={$boundary}\r\n\r\n--{$boundary}\r\n\r\nx\r\n--{$boundary}--",
        );

        static::assertTrue($part->isMultipart());
    }

    #[Test]
    public function decodesBase64WithPaddingInTheMiddle(): void
    {
        static::assertSame('ab', Part::fromString("Content-Transfer-Encoding: base64\r\n\r\nYWI=\r\n")->getContent());
    }

    #[Test]
    public function keepsQuotedFromLinesOfLazyContent(): void
    {
        static::assertSame('>From x', Content::lazy(static fn(): string => '>From x')->read());
    }

    #[Test]
    public function measuresLazyContentBeforeReadingIt(): void
    {
        static::assertSame(3, Content::lazy(static fn(): string => 'abc')->length());
    }

    #[Test]
    public function readsLinesOnlyWithinTheRange(): void
    {
        static::assertSame([0 => 'ab'], iterator_to_array(Lines::of(Content::fromString("ab\ncd")->slice(0, 2))));
    }

    #[Test]
    public function stopsReadingLinesWhereTheStreamEndsEarly(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: 'ab');

        static::assertSame([0 => 'ab'], iterator_to_array(Lines::of(Content::fromStream($stream, start: 0, end: 5))));
    }

    #[Test]
    public function wrapsMalformedHeaderError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read the message headers');
        $this->expectExceptionCode(0);

        Part::fromString(str_repeat('a', times: HeaderName::MAX_LENGTH + 1) . ": x\r\n\r\nbody");
    }

    #[Test]
    public function readsTextWithoutHeadersAsBody(): void
    {
        static::assertSame("just text\r\nmore", Part::fromString("just text\r\nmore")->getContent());
    }

    #[Test]
    public function hasNoHeadersWhenTextHasNone(): void
    {
        static::assertCount(0, Part::fromString("just text\r\nmore")->getHeaders());
    }

    #[Test]
    public function readsBodyAfterLeadingBlankLine(): void
    {
        static::assertSame('body', Part::fromString("\r\nbody")->getContent());
    }

    #[Test]
    public function hasEmptyBodyWhenThereIsNoBlankLine(): void
    {
        static::assertSame('', Part::fromString("Subject: x\r\nTo: a@example.com")->getContent());
    }

    #[Test]
    public function measuresBody(): void
    {
        static::assertSame(4, Part::fromString("Subject: x\r\n\r\nbody")->getSize());
    }

    /**
     * Forwarding: a part read from text is written back byte for byte.
     */
    #[Test]
    public function writesPartBackAsItWasRead(): void
    {
        static::assertSame(Fixtures::MULTIPART, Part::fromString(Fixtures::MULTIPART)->toString());
    }

    #[Test]
    public function writesBareLineFeedsAsCrlf(): void
    {
        static::assertSame("Subject: x\r\n\r\na\r\nb", Part::fromString("Subject: x\n\na\nb")->toString());
    }

    #[Test]
    public function writesReadPartsThroughPartWriter(): void
    {
        $written = PartWriter::body(Part::fromString(Fixtures::MULTIPART));

        static::assertStringContainsString("--b\r\nContent-Type: text/plain\r\n\r\nfirst\r\n--b\r\n", $written);
    }

    #[Test]
    public function iteratesPartsNumberedFromOne(): void
    {
        static::assertSame([1, 2], array_keys(iterator_to_array(Part::fromString(Fixtures::MULTIPART))));
    }

    #[Test]
    public function walksNestedParts(): void
    {
        $leaves = new RecursiveIteratorIterator(Part::fromString(Fixtures::nested(3)));

        static::assertSame(['leaf'], array_map(
            static fn(Part $part): string => $part->getContent(),
            iterator_to_array($leaves, preserve_keys: false),
        ));
    }

    #[Test]
    public function walksNestedPartsParentFirst(): void
    {
        $parts = new RecursiveIteratorIterator(
            Part::fromString(Fixtures::nested(3)),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        static::assertCount(3, iterator_to_array($parts, preserve_keys: false));
    }

    #[Test]
    public function hasNoChildrenPastTheEnd(): void
    {
        $iterator = Part::fromString(Fixtures::MULTIPART)->getIterator();
        $iterator->seek(1);
        $iterator->next();

        static::assertFalse($iterator->hasChildren());
    }

    #[Test]
    public function hasEmptyChildIteratorPastTheEnd(): void
    {
        $iterator = Part::fromString(Fixtures::MULTIPART)->getIterator();
        $iterator->seek(1);
        $iterator->next();

        static::assertCount(0, $iterator->getChildren());
    }

    #[Test]
    public function fetchesLazyContentOnce(): void
    {
        $calls   = 0;
        $content = Content::lazy(static function () use (&$calls): string {
            ++$calls;

            return 'body';
        });
        $part = new Part(new Headers(), $content);
        $part->getContent();
        $part->getSize();

        static::assertSame(1, $calls);
    }

    #[Test]
    public function doesNotFetchLazyContentForHeaders(): void
    {
        $calls = 0;
        $part  = new Part(
            Headers::fromString('Subject: x'),
            Content::lazy(static function () use (&$calls): string {
                ++$calls;

                return 'body';
            }),
        );
        $part->getHeaders();

        static::assertSame(0, $calls);
    }

    #[Test]
    public function slicesWithinTheContent(): void
    {
        static::assertSame('cd', Content::fromString('abcdef')->slice(2, 4)->read());
    }

    #[Test]
    public function clampsSliceToTheContent(): void
    {
        static::assertSame('ef', Content::fromString('abcdef')->slice(4, 99)->read());
    }

    #[Test]
    public function slicesNothingPastTheEnd(): void
    {
        static::assertSame('', Content::fromString('abcdef')->slice(9, 12)->read());
    }

    #[Test]
    public function readsLinesKeyedByOffset(): void
    {
        static::assertSame(
            [0 => "a\n", 2 => "bc\n", 5 => 'd'],
            iterator_to_array(Lines::of(Content::fromString("a\nbc\nd"))),
        );
    }

    #[Test]
    public function readsLongLinesInChunks(): void
    {
        $pieces = iterator_to_array(Lines::of(Content::fromString(str_repeat('a', times: Content::CHUNK + 1))));

        static::assertSame([0, Content::CHUNK], array_keys($pieces));
    }

    #[Test]
    public function knowsWhetherAPieceEndsALine(): void
    {
        static::assertSame([true, false], [Lines::endsLine("a\n"), Lines::endsLine('a')]);
    }

    #[Test]
    public function unquotesMboxrdFromLines(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: ">From a\n>>From b\n> From c\n");

        static::assertSame(
            "From a\n>From b\n> From c\n",
            Content::fromStream($stream, start: 0, end: 26, unquoteFrom: true)->read(),
        );
    }

    #[Test]
    public function keepsQuotedFromLinesWhenNotUnquoting(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: ">From a\n");

        static::assertSame(">From a\n", Content::fromStream($stream, start: 0, end: 8)->read());
    }

    #[Test]
    public function keepsUnquotingInSlices(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: "x\n>From a\n");

        static::assertSame(
            "From a\n",
            Content::fromStream($stream, start: 0, end: 10, unquoteFrom: true)->slice(2, 10)->read(),
        );
    }

    #[Test]
    public function treatsRangeEndingBeforeItsStartAsEmpty(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: 'abc');

        static::assertSame(0, Content::fromStream($stream, start: 2, end: 1)->length());
    }

    #[Test]
    public function refusesToReadAClosedStream(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: 'abc');
        $content = Content::fromStream($stream, start: 0, end: 3);
        fclose($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The storage this message was read from has been closed');

        $content->read();
    }

    #[DataProvider('saveProvider')]
    #[Test]
    public function savesTheContentGetContentReturns(string $raw): void
    {
        $part   = Part::fromString($raw);
        $stream = fopen('php://memory', mode: 'w+b');
        $count  = $part->saveTo($stream);
        rewind($stream);

        static::assertSame(
            [strlen($part->getContent()), $part->getContent()],
            [$count, stream_get_contents($stream)],
        );
    }

    #[Test]
    public function savesNothingOfAMultipart(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        $count  = Part::fromString(Fixtures::manyParts(2))->saveTo($stream);
        rewind($stream);

        static::assertSame([0, ''], [$count, stream_get_contents($stream)]);
    }

    #[Test]
    public function refusesToSaveToAStreamThatCannotBeWritten(): void
    {
        $part = Part::fromString("Content-Transfer-Encoding: base64\r\n\r\nYWI=");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write the content to the stream');

        $part->saveTo(fopen('php://memory', mode: 'rb'));
    }

    /**
     * A large attachment is decoded a block at a time, never held whole.
     */
    #[Test]
    public function savesALargeAttachmentWithoutHoldingIt(): void
    {
        $part = Part::fromString(
            "Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode(str_repeat('a', times: 4_000_000))),
        );
        $stream = fopen('php://temp/maxmemory:0', mode: 'w+b');
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $part->saveTo($stream);

        static::assertLessThan(1_000_000, memory_get_peak_usage() - $before);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function saveProvider(): array
    {
        $binary = str_repeat("\x00\xFF binary \r\n", times: 5_000);
        $text   = str_repeat("Grüße = tschüß\r\n", times: 5_000);

        return [
            'base64'           => ["Content-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($binary))],
            'quoted-printable' => [
                "Content-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($text),
            ],
            '7bit'             => ["Content-Transfer-Encoding: 7bit\r\n\r\n" . str_repeat("plain\r\n", times: 10_000)],
            '8bit'             => ["Content-Transfer-Encoding: 8bit\r\n\r\n{$text}"],
            'empty'            => ["Content-Transfer-Encoding: base64\r\n\r\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function contentProvider(): array
    {
        return [
            'plain'                      => ["Content-Type: text/plain\r\n\r\nHello\r\nworld", "Hello\r\nworld"],
            'bare line feeds kept'       => ["Subject: x\n\nHello\nworld", "Hello\nworld"],
            'base64'                     => [
                "Content-Transfer-Encoding: base64\r\n\r\n" . base64_encode('Grüße'),
                'Grüße',
            ],
            'base64 folded'              => ["Content-Transfer-Encoding: BASE64\r\n\r\nR3L\r\nDvMOfZQ==", 'Grüße'],
            'base64 with stray bytes'    => ["Content-Transfer-Encoding: base64\r\n\r\nR3L*Dv!MOfZQ==", 'Grüße'],
            'quoted-printable'           => [
                "Content-Transfer-Encoding: quoted-printable\r\n\r\nGr=C3=BC=C3=9Fe",
                'Grüße',
            ],
            'quoted-printable soft LF'   => ["Content-Transfer-Encoding: quoted-printable\n\nlong=\nline", 'longline'],
            'quoted-printable soft CRLF' => [
                "Content-Transfer-Encoding: quoted-printable\r\n\r\nlong=\r\nline",
                'longline',
            ],
            '8bit'                       => ["Content-Transfer-Encoding: 8bit\r\n\r\nGrüße", 'Grüße'],
            'unknown encoding as is'     => ["Content-Transfer-Encoding: x-uuencode\r\n\r\nbegin", 'begin'],
            'empty body'                 => ["Subject: x\r\n\r\n", ''],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function encodedContentProvider(): array
    {
        return [
            'crlf kept'         => ["Subject: x\r\n\r\na\r\nb", "a\r\nb"],
            'bare lf converted' => ["Subject: x\n\na\nb", "a\r\nb"],
            'base64 as is'      => ["Content-Transfer-Encoding: base64\r\n\r\nR3LDvMOfZQ==", 'R3LDvMOfZQ=='],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function contentTypeProvider(): array
    {
        return [
            'given'     => ["Content-Type: TEXT/HTML; charset=utf-8\r\n\r\nx", 'text/html'],
            'missing'   => ["Subject: x\r\n\r\nx", 'text/plain'],
            'not valid' => ["Content-Type: not-a-type\r\n\r\nx", 'text/plain'],
        ];
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function filenameProvider(): array
    {
        return [
            'disposition'          => ["Content-Disposition: attachment; filename=\"a.pdf\"\r\n\r\nx", 'a.pdf'],
            'content type name'    => ["Content-Type: application/pdf; name=\"b.pdf\"\r\n\r\nx", 'b.pdf'],
            'disposition first'    => [
                "Content-Type: application/pdf; name=\"b.pdf\"\r\nContent-Disposition: attachment; filename=\"a.pdf\"\r\n\r\nx",
                'a.pdf',
            ],
            'disposition without'  => [
                "Content-Type: application/pdf; name=\"b.pdf\"\r\nContent-Disposition: inline\r\n\r\nx",
                'b.pdf',
            ],
            'none'                 => ["Subject: x\r\n\r\nx", null],
            'traversal kept as is' => ["Content-Disposition: attachment; filename=\"../x\"\r\n\r\nx", '../x'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsplittableMultipartProvider(): array
    {
        return [
            'no boundary'        => ['multipart/mixed'],
            'empty boundary'     => ['multipart/mixed; boundary=""'],
            'boundary too long'  => ['multipart/mixed; boundary=' . str_repeat('b', times: 201)],
            'boundary not multi' => ['text/plain; boundary=b'],
        ];
    }
}
