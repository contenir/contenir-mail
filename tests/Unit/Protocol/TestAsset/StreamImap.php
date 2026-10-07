<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\TestAsset;

use Contenir\Mail\Protocol\Imap;

use function fopen;
use function fwrite;
use function rewind;

/**
 * IMAP protocol reading server responses from an in-memory stream.
 */
final class StreamImap extends Imap
{
    public function __construct(string $serverResponse)
    {
        $stream = fopen('php://memory', mode: 'rw+');
        fwrite($stream, $serverResponse);
        rewind($stream);
        $this->socket = $stream;
    }
}
