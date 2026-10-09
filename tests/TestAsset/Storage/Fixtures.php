<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset\Storage;

use PharData;

use function copy;
use function mkdir;
use function str_repeat;

use const DIRECTORY_SEPARATOR;

/**
 * Mailboxes for storage tests, copied into a test's own temporary directory.
 */
final class Fixtures
{
    private const string FILES = __DIR__ . '/../../Unit/_files/';

    /** A two-part message with a preamble, CRLF line breaks */
    public const string MULTIPART =
        "Subject: multipart\r\n"
            . "Content-Type: multipart/alternative; boundary=\"b\"\r\n"
            . "\r\n"
            . "preamble\r\n"
            . "--b\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "first\r\n"
            . "--b\r\n"
            . "Content-Type: text/html\r\n"
            . "\r\n"
            . "<p>second</p>\r\n"
            . "--b--\r\n"
            . "epilogue\r\n";

    /**
     * The mbox test file with CRLF ("INBOX") or LF ("INBOX.unix") line breaks, copied into $directory.
     */
    public static function mbox(string $directory, string $name = 'INBOX'): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . 'INBOX';
        copy(self::FILES . "test.mbox/{$name}", $path);

        return $path;
    }

    /**
     * The mbox folder tree: INBOX and subfolder/test.
     */
    public static function mboxTree(string $directory): string
    {
        copy(self::FILES . 'test.mbox/INBOX', $directory . DIRECTORY_SEPARATOR . 'INBOX');
        mkdir($directory . DIRECTORY_SEPARATOR . 'subfolder');
        copy(self::FILES . 'test.mbox/subfolder/test', $directory . DIRECTORY_SEPARATOR . 'subfolder/test');

        return $directory;
    }

    /**
     * The maildir test tree: five messages in INBOX (one recent), an empty ".subfolder"
     * and one message in ".subfolder.test", with a maildirsize of "10C,1L,3000S".
     */
    public static function maildir(string $directory): string
    {
        (new PharData(self::FILES . 'test.maildir/maildir.tar'))->extractTo($directory);
        mkdir($directory . DIRECTORY_SEPARATOR . '.subfolder/cur', permissions: 0o700, recursive: true);

        return $directory;
    }

    /**
     * A multipart nested $depth deep, each level holding the next as its only part.
     */
    public static function nested(int $depth): string
    {
        $message = "Content-Type: text/plain\r\n\r\nleaf";
        for ($level = 0; $level < $depth; ++$level) {
            $message =
                "Content-Type: multipart/mixed; boundary=\"b{$level}\"\r\n\r\n"
                . "--b{$level}\r\n{$message}\r\n--b{$level}--\r\n";
        }

        return $message;
    }

    /**
     * A multipart with $count empty text parts.
     */
    public static function manyParts(int $count): string
    {
        return (
            "Content-Type: multipart/mixed; boundary=\"b\"\r\n\r\n"
                . str_repeat("--b\r\n\r\nx\r\n", times: $count)
                . "--b--\r\n"
        );
    }
}
