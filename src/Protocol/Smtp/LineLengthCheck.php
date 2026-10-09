<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp;

use Contenir\Mail\Protocol\Exception;
use Generator;

use function preg_match;
use function sprintf;
use function strcspn;
use function strlen;
use function strrpos;
use function substr;
use function substr_count;

/**
 * Finds a line of SMTP DATA that is too long, reading the message in chunks.
 *
 * Folding the line instead would change the content and break a DKIM signature,
 * so the message is refused before any of it is sent.
 *
 * @internal
 */
final class LineLengthCheck
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * @param iterable<string> $chunks
     * @throws Exception\InvalidArgumentException When a line, its line break not counted, is longer than $limit.
     */
    public static function check(iterable $chunks, int $limit): void
    {
        $tooLong    = '/^[^\r\n]{' . ($limit + 1) . '}/m';
        $lines      = 0;
        $line       = '';
        $normalized = MessageData::normalize($chunks);
        foreach ($normalized as $chunk) {
            $text = $line . $chunk;
            if (1 === preg_match($tooLong, $text)) {
                throw self::lineTooLong($text, $lines, $normalized, $limit);
            }

            $lines += substr_count($chunk, needle: "\n");
            $end   = strrpos($text, needle: "\n");
            $line  = false === $end ? $text : substr($text, $end + 1);
        }
    }

    /**
     * @param string $text Starts at the start of a line, and has a line longer than $limit.
     * @param int $lines The line breaks before $text.
     * @param Generator<int, string> $chunks The chunks after $text.
     */
    private static function lineTooLong(
        string $text,
        int $lines,
        Generator $chunks,
        int $limit,
    ): Exception\InvalidArgumentException {
        $match = [];
        preg_match("/\\A(?:[^\\r\\n]{0,{$limit}}\\r\\n)*/", $text, $match);
        $before = $match[0] ?? '';

        return new Exception\InvalidArgumentException(sprintf(
            'Line %d of the message is %d bytes; SMTP allows at most %d. Encode the content '
                . '(quoted-printable or base64) instead of sending it as is.',
            $lines + substr_count($before, needle: "\n") + 1,
            self::lineLength($text, strlen($before), $chunks),
            $limit,
        ));
    }

    /**
     * The length of the line that starts at $start of $text, reading on through the chunks until it ends.
     *
     * @param Generator<int, string> $chunks
     */
    private static function lineLength(string $text, int $start, Generator $chunks): int
    {
        $length = strcspn($text, characters: "\r\n", offset: $start);
        $open   = ($start + $length) === strlen($text);
        while ($open) {
            $chunks->next();
            if (! $chunks->valid()) {
                break;
            }

            $text   = $chunks->current();
            $part   = strcspn($text, characters: "\r\n");
            $length += $part;
            $open   = strlen($text) === $part;
        }

        return $length;
    }
}
