<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Decode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DecodeTest extends TestCase
{
    #[Test]
    public function decodeMessageWithoutHeaders()
    {
        $text = 'This is a message body';

        Decode::splitMessage($text, $headers, $body);

        static::assertInstanceOf(Headers::class, $headers);
        static::assertSame($text, $body);
    }
}
