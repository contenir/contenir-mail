<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

/**
 * The attributes of one attachment, gathered as its records are read.
 *
 * @internal Used by Parser.
 */
final class AttachmentRecord
{
    /** The attAttachTitle bytes, in the message's code page */
    public ?string $title = null;

    /** The attAttachData bytes */
    public ?string $data = null;

    /** The attAttachment MAPI properties */
    public Properties $properties;

    public function __construct()
    {
        $this->properties = new Properties();
    }
}
