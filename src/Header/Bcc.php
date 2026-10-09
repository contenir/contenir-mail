<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

/**
 * @api
 */
final readonly class Bcc extends AbstractAddressList
{
    protected const string FIELD_NAME = 'Bcc';

    protected const array FIELD_NAMES = ['bcc'];
}
