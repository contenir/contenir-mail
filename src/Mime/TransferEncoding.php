<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

/**
 * Content-Transfer-Encoding mechanisms (RFC 2045, section 6.1).
 *
 * @api
 */
enum TransferEncoding: string
{
    case SevenBit        = '7bit';
    case EightBit        = '8bit';
    case Binary          = 'binary';
    case QuotedPrintable = 'quoted-printable';
    case Base64          = 'base64';
}
