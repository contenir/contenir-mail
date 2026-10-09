<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

/**
 * @api
 */
final readonly class From extends AbstractAddressList
{
    protected const string FIELD_NAME = 'From';

    protected const array FIELD_NAMES = ['from'];
}
