<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Closure;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Headers;

/**
 * Writes the body of a MIME tree: the encoded content of a leaf, or each
 * child of a multipart with its headers between boundary lines.
 *
 * body() returns the text; write() sends the same bytes to a stream a piece
 * at a time, so that a large attachment read from a stream is never held in
 * memory as a whole.
 */
final class PartWriter
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * @throws Exception\RuntimeException When a multipart has no boundary in its Content-Type, or a
     *     part has a line starting with the boundary (RFC 2046, section 5.1.1).
     */
    public static function body(PartInterface $part): string
    {
        $body = '';
        self::emit($part, static function (string $bytes) use (&$body): void {
            $body .= $bytes;
        });

        return $body;
    }

    /**
     * Write the body to a stream, exactly as body() returns it.
     *
     * The bytes go out as they are made, so when an error is found part of the body may
     * already have been written.
     *
     * @param resource $stream
     * @throws Exception\InvalidArgumentException When $stream is not an open stream.
     * @throws Exception\RuntimeException When the stream cannot be written, a multipart has no
     *     boundary in its Content-Type, or a part has a line starting with the boundary.
     */
    public static function write(PartInterface $part, mixed $stream): void
    {
        StreamOutput::check($stream);
        self::emit(
            $part,
            /** @throws Exception\RuntimeException When the stream does not take all the bytes. */
            static function (string $bytes) use ($stream): void {
                StreamOutput::write($stream, $bytes);
            },
        );
    }

    /**
     * @param Closure(string): void $out
     * @throws Exception\RuntimeException
     */
    private static function emit(PartInterface $part, Closure $out): void
    {
        if (! $part->isMultipart()) {
            $chunks = $part instanceof Part ? $part->encodedChunks() : [$part->getEncodedContent()];
            foreach ($chunks as $chunk) {
                $out($chunk);
            }

            return;
        }

        $boundary = self::boundary($part);
        foreach ($part->getParts() as $child) {
            $out("--{$boundary}" . Headers::EOL);
            $guarded = self::guarded($out, $boundary);
            $guarded($child->getHeaders()->toString() . Headers::EOL);
            self::emit($child, $guarded);
            $out(Headers::EOL);
        }

        $out("--{$boundary}--");
    }

    /**
     * Pass the bytes of one child on to $out, failing at a line that starts with the boundary.
     *
     * @param Closure(string): void $out
     * @return Closure(string): void
     */
    private static function guarded(Closure $out, string $boundary): Closure
    {
        $guard = new BoundaryGuard($boundary);

        return (
            /** @throws Exception\RuntimeException When a line of the part starts with the boundary. */
            static function (string $bytes) use ($guard, $out): void {
                $guard->check($bytes);
                $out($bytes);
            }
        );
    }

    /**
     * @throws Exception\RuntimeException
     */
    private static function boundary(PartInterface $part): string
    {
        $contentType = $part->getHeaders()->get('Content-Type');
        $boundary    = $contentType instanceof ContentType ? $contentType->getParameter('boundary') : null;
        if (null === $boundary || '' === $boundary) {
            throw new Exception\RuntimeException('A multipart part has no boundary in its Content-Type');
        }

        return $boundary;
    }
}
