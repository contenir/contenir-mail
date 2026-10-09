<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

/**
 * The multipart subtypes contenir-mail composes (RFC 2046, RFC 2387).
 *
 * @api
 */
enum MultipartType: string
{
    /** Independent parts in order, such as a message and its attachments */
    case Mixed = 'mixed';

    /** The same content in increasingly rich forms, such as text and HTML */
    case Alternative = 'alternative';

    /** A root part with the resources it refers to, such as HTML and its inline images */
    case Related = 'related';

    public function contentType(): string
    {
        return "multipart/{$this->value}";
    }
}
