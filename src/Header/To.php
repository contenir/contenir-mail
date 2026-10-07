<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

final readonly class To extends AbstractAddressList
{
    protected const string FIELD_NAME = 'To';

    protected const array FIELD_NAMES = ['to'];
}
