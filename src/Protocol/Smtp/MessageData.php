<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp;

use Generator;

use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function substr;

/**
 * The message text of SMTP DATA, worked on in chunks so a large message is never split into lines.
 *
 * CRLF, bare CR and bare LF all become CRLF, also when a CRLF is split between
 * two chunks, and a "." that starts a line is doubled (RFC 5321, section 4.5.2).
 *
 * @internal
 */
final class MessageData
{
    /**
     * @codeCoverageIgnore Never called: it only stops the class of static methods being instantiated.
     */
    private function __construct() {}

    /**
     * The message as it is sent after DATA: every line ending with CRLF, and leading dots doubled.
     *
     * A line break at the very end adds no empty line, and a message that is empty or a single line
     * break sends no lines at all. The "." that ends the data is not included.
     *
     * @param iterable<string> $chunks
     * @return Generator<int, string>
     */
    public static function encode(iterable $chunks): Generator
    {
        $lineStart = true;
        $heldBreak = false;
        $sent      = false;
        foreach (self::normalize($chunks) as $chunk) {
            $stuffed = str_replace(
                search: "\n.",
                replace: "\n..",
                subject: $chunk,
            );
            if ($lineStart && str_starts_with($stuffed, '.')) {
                $stuffed = ".{$stuffed}";
            }

            $out       = ($heldBreak ? "\r\n" : '') . $stuffed;
            $lineStart = str_ends_with($chunk, "\n");
            $heldBreak = $lineStart;
            if ($heldBreak) {
                $out = substr($out, offset: 0, length: -2);
            }

            if ('' !== $out) {
                $sent = true;
                yield $out;
            }
        }

        if ($sent) {
            yield "\r\n";
        }
    }

    /**
     * The chunks with CRLF, bare CR and bare LF written as CRLF; a CR that ends one chunk and
     * the LF that starts the next are one line break. No chunk ends with a bare CR.
     *
     * @param iterable<string> $chunks
     * @return Generator<int, string>
     */
    public static function normalize(iterable $chunks): Generator
    {
        $carriageReturn = false;
        foreach ($chunks as $chunk) {
            if ('' === $chunk) {
                continue;
            }

            if ($carriageReturn && str_starts_with($chunk, "\n")) {
                $chunk = substr($chunk, offset: 1);
            }

            $carriageReturn = str_ends_with($chunk, "\r");
            if ('' !== $chunk) {
                yield str_replace(
                    search: "\n",
                    replace: "\r\n",
                    subject: str_replace(
                        search: ["\r\n", "\r"],
                        replace: "\n",
                        subject: $chunk,
                    ),
                );
            }
        }
    }
}
