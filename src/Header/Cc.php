<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

/**
 * @api
 */
final readonly class Cc extends AbstractAddressList
{
    protected const string FIELD_NAME = 'Cc';

    protected const array FIELD_NAMES = ['cc'];
}
