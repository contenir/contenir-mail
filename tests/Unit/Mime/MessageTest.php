<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime;
use Contenir\Mail\Mime\Message;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function current;
use function strlen;
use function strpos;

class MessageTest extends TestCase
{
    #[Test]
    public function multiPart()
    {
        $msg = new Mime\Message(); // No Parts
        static::assertFalse($msg->isMultiPart());
    }

    #[Test]
    public function setGetParts()
    {
        $msg = new Mime\Message(); // No Parts
        $p = $msg->getParts();
        static::assertIsArray($p);
        static::assertEmpty($p);

        $p2   = [];
        $p2[] = new Mime\Part('This is a test');
        $p2[] = new Mime\Part('This is another test');
        $msg->setParts($p2);
        $p = $msg->getParts();
        static::assertIsArray($p);
        static::assertCount(2, $p);
    }

    #[Test]
    public function getMime()
    {
        $msg = new Mime\Message(); // No Parts
        $m = $msg->getMime();
        static::assertInstanceOf(\Contenir\Mail\Mime\Mime::class, $m);

        $msg = new Mime\Message(); // No Parts
        $mime = new Mime\Mime('1234');
        $msg->setMime($mime);
        $m2 = $msg->getMime();
        static::assertInstanceOf(\Contenir\Mail\Mime\Mime::class, $m2);
        static::assertEquals('1234', $m2->boundary());
    }

    #[Test]
    public function generate()
    {
        $msg = new Mime\Message(); // No Parts
        $p1 = new Mime\Part('This is a test');
        $p2 = new Mime\Part('This is another test');
        $msg->addPart($p1);
        $msg->addPart($p2);
        $res      = $msg->generateMessage();
        $mime     = $msg->getMime();
        $boundary = $mime->boundary();
        $p1       = strpos($res, $boundary);
        // $boundary must appear once for every mime part
        static::assertNotFalse($p1);
        if ($p1) {
            $p2 = strpos($res, $boundary, $p1 + strlen($boundary));
            static::assertNotFalse($p2);
        }
        // check if the two test messages appear:
        static::assertStringContainsString('This is a test', $res);
        static::assertStringContainsString('This is another test', $res);

        // ... more in ZMailTest
    }

    /**
     * check if decoding a string into a \Contenir\Mail\Mime\Message object works
     */
    #[Test]
    public function decodeMimeMessage()
    {
        $text = <<<EOD
            This is a message in Mime Format.  If you see this, your mail reader does not support this format.

            --=_af4357ef34b786aae1491b0a2d14399f
            Content-Type: application/octet-stream
            Content-Transfer-Encoding: 8bit

            This is a test
            --=_af4357ef34b786aae1491b0a2d14399f
            Content-Type: image/gif
            Content-Transfer-Encoding: base64
            Content-ID: <12>

            This is another test
            --=_af4357ef34b786aae1491b0a2d14399f--
            EOD;
        $res = Mime\Message::createFromMessage($text, '=_af4357ef34b786aae1491b0a2d14399f');

        $parts = $res->getParts();
        static::assertEquals(2, count($parts));

        $part1 = $parts[0];
        static::assertEquals('application/octet-stream', $part1->type);
        static::assertEquals('8bit', $part1->encoding);

        $part2 = $parts[1];
        static::assertEquals('image/gif', $part2->type);
        static::assertEquals('base64', $part2->encoding);
        static::assertEquals('12', $part2->id);
    }

    /**
     * check if decoding a string into a \Contenir\Mail\Mime\Message object works
     */
    #[Test]
    public function decodeMimeMessageNoHeader()
    {
        $text = <<<EOD
            This is a MIME-encapsulated message

            --=_af4357ef34b786aae1491b0a2d14399f

            The original message was received at Fri, 16 Aug 2013 00:00:48 -0700
            from localhost.localdomain [127.0.0.1]
            End content

            --=_af4357ef34b786aae1491b0a2d14399f
            Content-Type: image/gif

            This is a test
            --=_af4357ef34b786aae1491b0a2d14399f--
            EOD;
        $res = Mime\Message::createFromMessage($text, '=_af4357ef34b786aae1491b0a2d14399f');

        $parts = $res->getParts();
        static::assertEquals(2, count($parts));

        $part1        = $parts[0];
        $part1Content = $part1->getRawContent();
        static::assertStringContainsString('The original message', $part1Content);
        static::assertStringContainsString('End content', $part1Content);

        $part2 = $parts[1];
        static::assertEquals('image/gif', $part2->type);
    }

    /**
     * Check if decoding a string that is not a multipart message works
     */
    #[Test]
    public function decodeNonMultipartMimeMessage()
    {
        $text = <<<EOD
            Content-Type: image/gif

            This is a test
            EOD;
        $res = Mime\Message::createFromMessage($text);

        $parts = $res->getParts();
        static::assertEquals(1, count($parts));

        $part1        = $parts[0];
        $part1Content = $part1->getRawContent();
        static::assertEquals('This is a test', $part1Content);
        static::assertEquals('image/gif', $part1->type);
    }

    #[Test]
    public function nonMultipartMessageShouldNotRemovePartFromMessage()
    {
        $message = new Mime\Message(); // No Parts
        $part = new Mime\Part('This is a test');
        $message->addPart($part);
        $message->generateMessage();

        $parts = $message->getParts();
        $test  = current($parts);
        static::assertSame($part, $test);
    }

    #[Test]
    #[Group('Laminas-5962')]
    public function passEmptyArrayIntoSetPartsShouldReturnEmptyString()
    {
        $mimeMessage = new Mime\Message();
        $mimeMessage->setParts([]);

        static::assertEquals('', $mimeMessage->generateMessage());
    }

    #[Test]
    public function duplicatePartAddedWillThrowException()
    {
        $this->expectException(Mime\Exception\InvalidArgumentException::class);

        $message = new Mime\Message();
        $part    = new Mime\Part('This is a test');
        $message->addPart($part);
        $message->addPart($part);
    }

    #[Test]
    public function fromStringWithCrlfAndRfc2822FoldedHeaders()
    {
        // This is a fixture as provided by many mailservers
        // e.g. cyrus or dovecot
        $eol     = "\r\n";
        $fixture = 'This is a MIME-encapsulated message'
        . $eol
        . $eol
        . '--=_af4357ef34b786aae1491b0a2d14399f'
        . $eol
        . 'Content-Type: text/plain'
        . $eol
        . 'Content-Disposition: attachment;'
        . $eol
        . "\t"
        . 'filename="test.txt"'
        . $eol // Valid folding
        . $eol
        . 'This is a test'
        . $eol
        . '--=_af4357ef34b786aae1491b0a2d14399f--';

        $message = Message::createFromMessage($fixture, '=_af4357ef34b786aae1491b0a2d14399f', $eol);
        $parts   = $message->getParts();

        static::assertEquals(1, count($parts));
        static::assertEquals('attachment; filename="test.txt"', $parts[0]->getDisposition());
    }
}
