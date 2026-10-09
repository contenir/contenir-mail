<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

/**
 * @api
 */
final readonly class ReplyTo extends AbstractAddressList
{
    protected const string FIELD_NAME = 'Reply-To';

    protected const array FIELD_NAMES = ['reply-to', 'replyto', 'reply_to'];
}
