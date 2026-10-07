<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Decode;
use PHPUnit\Framework\TestCase;

class DecodeTest extends TestCase
{
    public function testDecodeMessageWithoutHeaders()
    {
        $text = 'This is a message body';

        Decode::splitMessage($text, $headers, $body);

        self::assertInstanceOf(Headers::class, $headers);
        self::assertSame($text, $body);
    }
}
