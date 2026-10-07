<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

/**
 * How much a server may make a protocol read for one command, so that a
 * hostile or broken server cannot exhaust memory.
 *
 * The line length bounds each line of a response; for IMAP that excludes
 * literals, which count only towards the response size. The response size
 * bounds everything read for one command: all its lines and literals, or a
 * whole POP3 multi-line response such as RETR.
 *
 * ```php
 * $imap->setResponseLimits(new ResponseLimits(maxResponseSize: 256 * 1024 * 1024));
 * ```
 *
 * @api
 */
final readonly class ResponseLimits
{
    /** 8 MiB: an IMAP SEARCH of a very large mailbox fits on one line */
    public const int DEFAULT_MAX_LINE_LENGTH = 8_388_608;

    /** 64 MiB: a message with about 45 MiB of attachments, once base64 encoded */
    public const int DEFAULT_MAX_RESPONSE_SIZE = 67_108_864;

    /** The smallest limit accepted, so that ordinary responses always fit */
    public const int MINIMUM = 1024;

    /**
     * @throws Exception\InvalidArgumentException When a limit is below the minimum, or a line may exceed the response.
     */
    public function __construct(
        public int $maxLineLength = self::DEFAULT_MAX_LINE_LENGTH,
        public int $maxResponseSize = self::DEFAULT_MAX_RESPONSE_SIZE,
    ) {
        if ($maxLineLength < self::MINIMUM || $maxResponseSize < self::MINIMUM) {
            throw new Exception\InvalidArgumentException(
                'Response limits must be at least ' . self::MINIMUM . ' bytes',
            );
        }

        if ($maxLineLength > $maxResponseSize) {
            throw new Exception\InvalidArgumentException('The line length limit cannot exceed the response size limit');
        }
    }
}
