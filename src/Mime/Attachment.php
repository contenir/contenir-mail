<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use function basename;
use function fopen;
use function function_exists;
use function is_file;
use function is_readable;
use function mime_content_type;

/**
 * Builds parts for files attached to a message and resources embedded in its HTML.
 */
final class Attachment
{
    private function __construct() {}

    /**
     * Attach a file, read from disk only when the message is written.
     *
     * The type is detected from the file's contents when ext-fileinfo is
     * available, and is application/octet-stream otherwise.
     *
     * @throws Exception\InvalidArgumentException When the file cannot be read.
     */
    public static function fromPath(string $path, ?string $filename = null, ?string $type = null): Part
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new Exception\InvalidArgumentException("Cannot read the file \"{$path}\"");
        }

        return new Part(
            self::open($path),
            $type ?? self::detectType($path),
            TransferEncoding::Base64,
            disposition: Disposition::Attachment,
            filename: $filename ?? basename($path),
        );
    }

    public static function fromString(string $content, string $filename, string $type = Mime::TYPE_OCTETSTREAM): Part
    {
        return new Part(
            $content,
            $type,
            TransferEncoding::Base64,
            disposition: Disposition::Attachment,
            filename: $filename,
        );
    }

    /**
     * A resource embedded in the HTML, which refers to it as "cid:{$id}".
     */
    public static function inline(string $content, string $id, string $type, ?string $filename = null): Part
    {
        return new Part(
            $content,
            $type,
            TransferEncoding::Base64,
            disposition: Disposition::Inline,
            filename: $filename,
            id: $id,
        );
    }

    /**
     * @return resource
     * @throws Exception\InvalidArgumentException
     *
     * @mago-expect analysis:missing-return-type Streams have no native return type.
     */
    private static function open(string $path)
    {
        $stream = fopen($path, mode: 'rb');
        // @codeCoverageIgnoreStart
        // Unreachable: the file was found readable just before
        if (false === $stream) {
            throw new Exception\InvalidArgumentException("Cannot read the file \"{$path}\"");
        }

        // @codeCoverageIgnoreEnd

        return $stream;
    }

    private static function detectType(string $path): string
    {
        // @codeCoverageIgnoreStart
        // Without ext-fileinfo there is nothing to detect with; the suite always runs with it
        if (! function_exists('mime_content_type')) {
            return Mime::TYPE_OCTETSTREAM;
        }

        // @codeCoverageIgnoreEnd

        $type = mime_content_type($path);

        return false === $type ? Mime::TYPE_OCTETSTREAM : $type;
    }
}
