<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\TestAsset;

use Contenir\Mail\Storage\Mbox;
use Contenir\Mail\Storage\Message;

/**
 * Maildir class, which uses old message class
 */
class MboxOldMessage extends Mbox
{
    /**
     * used message class
     *
     * @var class-string<Message\MessageInterface>
     */
    protected $messageClass = Message::class;
}
