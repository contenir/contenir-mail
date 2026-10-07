<?php

namespace Contenir\Mail\Tests\Unit\Storage\TestAsset;

use Contenir\Mail\Storage\Maildir;
use Contenir\Mail\Storage\Message;

/**
 * Maildir class, which uses old message class
 */
class MaildirOldMessage extends Maildir
{
    /**
     * used message class
     *
     * @var class-string<Message>
     */
    protected $messageClass = Message::class;
}
