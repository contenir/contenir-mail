<?php

namespace Contenir\Mail\Header;

class References extends IdentificationField
{
    /** @var string  */
    protected $fieldName = 'References';
    /** @var string  */
    protected static $type = 'references';
}
