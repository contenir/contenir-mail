<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Part;

use Contenir\Mail\Mime\TransferEncoding;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\FileSystem;
use Generator;

use function base64_decode;
use function fwrite;
use function preg_replace;
use function quoted_printable_decode;
use function str_contains;
use function strlen;
use function substr;

/**
 * Decodes stored content from its Content-Transfer-Encoding a block at a
 * time, so a large attachment is never held whole while it is decoded.
 *
 * @internal Used by Storage\Part.
 */
final class Decoder
{
    /**
     * The decoded content, in pieces that join up to the whole.
     *
     * @return Generator<int, string>
     * @throws Exception\RuntimeException When the storage has been closed, or the file cannot be opened.
     */
    public static function decode(Content $body, ?TransferEncoding $encoding): Generator
    {
        return match ($encoding) {
            TransferEncoding::Base64          => self::base64($body->chunks()),
            TransferEncoding::QuotedPrintable => self::quotedPrintable($body->chunks()),
            default                           => $body->chunks(),
        };
    }

    /**
     * The pieces joined.
     *
     * @param iterable<string> $pieces
     * @throws Exception\RuntimeException When the storage has been closed, or the file cannot be opened.
     */
    public static function join(iterable $pieces): string
    {
        $joined = '';
        foreach ($pieces as $piece) {
            $joined .= $piece;
        }

        return $joined;
    }

    /**
     * Write the pieces to a stream, returning the number of bytes written.
     *
     * @param iterable<string> $pieces
     * @param resource $stream
     * @throws Exception\RuntimeException When the storage has been closed, or the stream cannot be written.
     *
     * @mago-expect analysis:missing-parameter-type Streams have no native parameter type.
     */
    public static function write(iterable $pieces, $stream): int
    {
        $written = 0;
        foreach ($pieces as $piece) {
            if (FileSystem::quietly(static fn(): int|false => fwrite($stream, $piece)) !== strlen($piece)) {
                throw new Exception\RuntimeException('Cannot write the content to the stream');
            }

            $written += strlen($piece);
        }

        return $written;
    }

    /**
     * Bare LF line breaks, as in mbox files, as CRLF.
     */
    public static function crlf(string $text): string
    {
        return (string) preg_replace('/(?<!\r)\n/', replacement: "\r\n", subject: $text);
    }

    /**
     * Stored base64 often carries stray characters; mail clients decode it
     * leniently, and so does this. Every character outside the alphabet is
     * skipped, "=" included, as base64_decode() skips them when not strict,
     * so each whole group of four decodes on its own.
     *
     * @param iterable<string> $chunks
     * @return Generator<int, string>
     *
     * @mago-expect lint:strict-behavior Lenient decoding is the point; see above.
     */
    private static function base64(iterable $chunks): Generator
    {
        $carry = '';
        foreach ($chunks as $chunk) {
            $carry .= preg_replace('/[^A-Za-z0-9+\/]+/', replacement: '', subject: $chunk) ?? '';
            $whole = strlen($carry) - (strlen($carry) % 4);

            yield (string) base64_decode(substr($carry, offset: 0, length: $whole));

            $carry = substr($carry, $whole);
        }

        yield (string) base64_decode($carry);
    }

    /**
     * Quoted-printable escapes never span a line break, so each run of whole
     * lines decodes on its own. quoted_printable_decode() stops at a NUL
     * byte, and so does the decoding of the whole content.
     *
     * @param iterable<string> $chunks
     * @return Generator<int, string>
     */
    private static function quotedPrintable(iterable $chunks): Generator
    {
        foreach (Lines::whole($chunks) as $lines) {
            yield quoted_printable_decode(self::crlf($lines));

            if (str_contains($lines, "\0")) {
                return;
            }
        }
    }
}
