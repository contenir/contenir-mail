<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

/**
 * How a part is presented (RFC 2183): shown in the message, or offered as a file.
 */
enum Disposition: string
{
    case Inline     = 'inline';
    case Attachment = 'attachment';
}
