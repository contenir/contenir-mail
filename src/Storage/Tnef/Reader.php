<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\RuntimeException;

use function sprintf;

/**
 * Reads the attachments and body from a TNEF container: an application/ms-tnef part, or a winmail.dat file.
 *
 * Outlook and Exchange wrap a message's attachments in TNEF (MS-OXTNEF),
 * which other mail clients cannot open. read() returns the files it holds,
 * the plain-text body, and the RTF body, decompressed (MS-OXRTFCP).
 *
 * The bytes are treated as hostile. Every length is checked against the
 * bytes left before it is used, and reading stops with a RuntimeException
 * at the first problem rather than returning part of the contents:
 * a missing signature, data that ends early, a record whose checksum does
 * not match, an unknown record level, more attachments than allowed, more
 * output than allowed, or malformed MAPI properties or compressed RTF.
 * File names are made safe with SafeText::filename().
 *
 * ```php
 * $contents = (new Reader())->read(file_get_contents('winmail.dat'));
 * foreach ($contents->attachments as $attachment) {
 *     file_put_contents("/srv/attachments/{$attachment->filename}", $attachment->content);
 * }
 * ```
 *
 * @api
 */
final readonly class Reader
{
    /** Most attachments read from one container, by default */
    public const int MAX_ATTACHMENTS = 100;

    /** Most bytes of attachments, body and RTF together read from one container, by default: 64 MiB */
    public const int MAX_BYTES = 67_108_864;

    /**
     * @param int $maxAttachments The most attachments a container may hold.
     * @param int $maxBytes The most bytes of output, attachments, body and decompressed RTF together.
     * @throws InvalidArgumentException When a limit is less than one.
     */
    public function __construct(
        private int $maxAttachments = self::MAX_ATTACHMENTS,
        private int $maxBytes = self::MAX_BYTES,
    ) {
        if ($maxAttachments < 1 || $maxBytes < 1) {
            throw new InvalidArgumentException(sprintf(
                'TNEF limits must be at least 1; %d attachments and %d bytes were given',
                $maxAttachments,
                $maxBytes,
            ));
        }
    }

    /**
     * @throws RuntimeException When the bytes are not TNEF, are malformed, or exceed a limit.
     */
    public function read(string $bytes): Contents
    {
        return (new Parser($this->maxAttachments, $this->maxBytes))->parse($bytes);
    }
}
