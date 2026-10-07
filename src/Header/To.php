<?php

namespace Contenir\Mail\Header;

class To extends AbstractAddressList
{
    /** @var string */
    protected $fieldName = 'To';
    /** @var string */
    protected static $type = 'to';
}
