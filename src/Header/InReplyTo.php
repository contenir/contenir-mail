<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

final readonly class InReplyTo extends AbstractIdentificationField
{
    protected const string FIELD_NAME = 'In-Reply-To';
}
