<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use Contenir\Mail\Header\Exception\InvalidArgumentException as HeaderException;
use Contenir\Mail\Header\HeaderWrap;
use Contenir\Mail\Header\MimeParameterParser;
use Contenir\Mail\Headers;

use function count;
use function explode;
use function implode;
use function preg_match;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Splits raw MIME text: a message into headers and body, a multipart body into its parts.
 *
 * Each method makes one pass over its input, so broken or hostile input
 * cannot make it loop, and the number of parts is limited. Storage\Part
 * reads stored mail the same way without holding it all in memory.
 *
 * @mago-expect lint:cyclomatic-complexity The laminas-mime splitting functions, each a single checked pass.
 * @mago-expect lint:kan-defect The laminas-mime splitting functions, each a single checked pass.
 *
 * @api
 */
final class Decode
{
    /** Most parts splitMime() returns */
    public const int MAX_PARTS = 1000;

    /**
     * The parts of a multipart body, between "--boundary" lines.
     *
     * The preamble and epilogue are not parts, and the line break before a
     * boundary line belongs to the boundary (RFC 2046, section 5.1.1).
     *
     * @return list<string> Empty when the body has no boundary line.
     * @throws Exception\InvalidArgumentException When the boundary is empty.
     * @throws Exception\RuntimeException When the closing boundary is missing, or there are too many parts.
     */
    public static function splitMime(string $body, string $boundary): array
    {
        if ('' === $boundary) {
            throw new Exception\InvalidArgumentException('The boundary may not be empty');
        }

        $delimiter = "--{$boundary}";
        $parts     = [];
        $lines     = null;
        foreach (explode("\n", $body) as $line) {
            $kind = self::delimiterKind($line, $delimiter);
            if (null === $kind) {
                if (null !== $lines) {
                    $lines[] = $line;
                }

                continue;
            }

            if (null !== $lines) {
                $part    = implode("\n", $lines);
                $parts[] = str_ends_with($part, "\r") ? substr($part, offset: 0, length: -1) : $part;
                if (count($parts) > self::MAX_PARTS) {
                    throw new Exception\RuntimeException(sprintf(
                        'A multipart may hold at most %d parts',
                        self::MAX_PARTS,
                    ));
                }
            }

            if ('close' === $kind) {
                return $parts;
            }

            $lines = [];
        }

        if (null === $lines) {
            return [];
        }

        throw new Exception\RuntimeException('Not a valid Mime Message: End Missing');
    }

    /**
     * The parts of a multipart body, each split into headers and body.
     *
     * @return list<array{header: Headers, body: string}>|null Null when the body has no boundary line.
     * @throws Exception\ExceptionInterface When the body is not a valid multipart.
     * @throws \Contenir\Mail\Exception\RuntimeException When a part's headers are malformed.
     */
    public static function splitMessageStruct(string $message, string $boundary, string $eol = Mime::LINEEND): ?array
    {
        $parts = self::splitMime($message, $boundary);
        if ([] === $parts) {
            return null;
        }

        $result = [];
        foreach ($parts as $part) {
            $headers = null;
            $body    = null;
            self::splitMessage($part, $headers, $body, $eol);
            $result[] = ['header' => $headers, 'body' => $body];
        }

        return $result;
    }

    /**
     * Split a message into its headers and its body.
     *
     * Text that does not start with a header line is all body. The headers
     * end at the first blank line, written with $eol, or else with CRLF or
     * LF; without one, the whole text is headers.
     *
     * @param-out Headers $headers
     * @param-out string $body
     * @throws \Contenir\Mail\Exception\RuntimeException When the headers are malformed.
     */
    public static function splitMessage(
        string|Headers $message,
        mixed &$headers,
        mixed &$body,
        string $eol = Mime::LINEEND,
    ): void {
        $message = $message instanceof Headers ? $message->toString() : $message;
        if (1 !== preg_match('/^[\x21-\x39\x3B-\x7E]+:/', $message)) {
            $headers = new Headers();
            $body    = $message;

            return;
        }

        foreach ([$eol, "\r\n", "\n"] as $lineEnd) {
            $position = strpos($message, $lineEnd . $lineEnd);
            if (false !== $position) {
                $headers = Headers::fromString(substr($message, offset: 0, length: $position), $lineEnd);
                $body    = substr($message, $position + (2 * strlen($lineEnd)));

                return;
            }
        }

        $headers = Headers::fromString($message, str_contains($message, "\r\n") ? "\r\n" : "\n");
        $body    = '';
    }

    /**
     * A Content-Type value split into its type and parameters, or one of them.
     *
     * @return string|array<string, string>|null
     * @throws Exception\RuntimeException When a parameter continuation is broken.
     */
    public static function splitContentType(string $type, ?string $wantedPart = null): string|array|null
    {
        return self::splitHeaderField($type, $wantedPart, 'type');
    }

    /**
     * A structured header value split into its leading value and parameters, or one of them.
     *
     * The leading value is keyed $firstName; parameter names are lower-cased.
     *
     * @return string|array<string, string>|null The part asked for, null when it is missing, or all parts.
     * @throws Exception\RuntimeException When a parameter continuation is broken.
     */
    public static function splitHeaderField(
        string $field,
        ?string $wantedPart = null,
        string $firstName = '0',
    ): string|array|null {
        try {
            [$leader, $parameters] = MimeParameterParser::parse($field, $field, 'structured');
        } catch (HeaderException $e) {
            throw new Exception\RuntimeException('not a valid header field', 0, $e);
        }

        $leader    = trim($leader, characters: '"');
        $firstName = strtolower($firstName);
        if (null === $wantedPart) {
            return [$firstName => $leader, ...$parameters];
        }

        $wantedPart = strtolower($wantedPart);

        return $wantedPart === $firstName ? $leader : $parameters[$wantedPart] ?? null;
    }

    /**
     * Decode RFC 2047 encoded words in a header value to UTF-8, unfolding it first.
     */
    public static function decodeQuotedPrintable(string $string): string
    {
        return HeaderWrap::mimeDecodeValue($string);
    }

    /**
     * "part" for a boundary line, "close" for the closing one, null for any other line.
     */
    private static function delimiterKind(string $line, string $delimiter): ?string
    {
        if (! str_starts_with($line, $delimiter)) {
            return null;
        }

        return match (rtrim(substr($line, strlen($delimiter)), characters: " \t\r")) {
            ''      => 'part',
            '--'    => 'close',
            default => null,
        };
    }
}
