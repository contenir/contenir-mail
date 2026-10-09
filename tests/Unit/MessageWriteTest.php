<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Closure;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Tests\Unit\TestAsset\StringSerializableObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function str_repeat;
use function stream_get_contents;

/**
 * Writing a message to a stream, a piece at a time, with the bytes toString() returns.
 */
#[CoversClass(Message::class)]
#[Group('unit')]
final class MessageWriteTest extends TestCase
{
    /**
     * @param Closure(Message): Message $build
     */
    #[DataProvider('shapeProvider')]
    #[Test]
    public function writesWhatToStringReturns(Closure $build): void
    {
        $message = $build(self::message());
        $stream  = self::temporary();
        $message->writeTo($stream);

        static::assertSame($message->toString(), stream_get_contents($stream, offset: 0));
    }

    /**
     * @param Closure(Message): Message $build
     */
    #[DataProvider('shapeProvider')]
    #[Test]
    public function writesTheBodyThatGetBodyTextReturns(Closure $build): void
    {
        $message = $build(self::message());
        $stream  = self::temporary();
        $message->writeBodyTo($stream);

        static::assertSame($message->getBodyText(), stream_get_contents($stream, offset: 0));
    }

    #[DataProvider('notAStreamProvider')]
    #[Test]
    public function refusesToWriteToSomethingOtherThanAStream(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        self::message()->{$method}('php://memory');
    }

    #[DataProvider('notAStreamProvider')]
    #[Test]
    public function failsWhenTheStreamCannotBeWritten(string $method): void
    {
        $stream = fopen(__FILE__, mode: 'rb');
        static::assertNotFalse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write the message to the stream');

        self::message()->setBody('Hello')->{$method}($stream);
    }

    #[Test]
    public function writesAStreamedAttachmentWithoutHoldingItInMemory(): void
    {
        $attachment = self::temporary();
        $block      = str_repeat('0123456789abcdef', times: 4096);
        for ($i = 0; $i < 128; $i++) {
            fwrite($attachment, $block);
        }

        unset($block);
        $message = self::message()
            ->setText('See attached')
            ->attach(new Part($attachment, disposition: Disposition::Attachment, filename: 'big.bin'));
        $stream = fopen('php://temp/maxmemory:0', mode: 'w+b');
        static::assertNotFalse($stream);

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $message->writeTo($stream);

        static::assertLessThan(2 * 1024 * 1024, memory_get_peak_usage() - $before);
    }

    /**
     * @return array<string, array{Closure(Message): Message}>
     */
    public static function shapeProvider(): array
    {
        return [
            'no body'          => [static fn(Message $message): Message => $message],
            'string body'      => [static fn(Message $message): Message => $message->setBody("Hello\r\n.world")],
            'stringable body'  => [
                static fn(Message $message): Message => $message->setBody(new StringSerializableObject('Hello')),
            ],
            'text'             => [static fn(Message $message): Message => $message->setText("Grüße\nworld")],
            'html'             => [static fn(Message $message): Message => $message->setHtml('<p>Hello</p>')],
            'alternative'      => [
                static fn(Message $message): Message => $message->setText('Hi')->setHtml('<b>Hi</b>'),
            ],
            'attachments'      => [
                static fn(Message $message): Message => $message->setText('See attached')
                    ->attach(Attachment::fromString('a,b', 'a.csv', 'text/csv'))
                    ->attach(new Part(self::content(100_000), disposition: Disposition::Attachment)),
            ],
            'embedded'         => [
                static fn(Message $message): Message => $message->setHtml('<img src="cid:logo">')
                    ->embed(Attachment::inline('PNG', id: 'logo', type: 'image/png')),
            ],
            'everything'       => [
                static fn(Message $message): Message => $message->setText('Hi')
                    ->setHtml('<img src="cid:logo">')
                    ->embed(new Part(self::content(70_000), 'image/png', id: 'logo'))
                    ->attach(Attachment::fromString('a,b', 'a.csv', 'text/csv')),
            ],
            'multipart body'   => [
                static fn(Message $message): Message => $message->setBody(new Multipart(
                    MultipartType::Mixed,
                    [Part::text('Hi')],
                    boundary: 'frontier',
                )),
            ],
            'single part body' => [static fn(Message $message): Message => $message->setBody(Part::text('Hi'))],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notAStreamProvider(): array
    {
        return [
            'message'      => ['writeTo'],
            'message body' => ['writeBodyTo'],
        ];
    }

    private static function message(): Message
    {
        return new Message(new Headers(new Date(new DateTimeImmutable('2024-01-01T00:00:00Z'))));
    }

    /**
     * @return resource
     */
    private static function content(int $length)
    {
        $stream = self::temporary();
        fwrite($stream, str_repeat("\x00\x80\xFFabc", (int) ($length / 6)));

        return $stream;
    }

    /**
     * @return resource
     */
    private static function temporary()
    {
        $stream = fopen('php://temp', mode: 'w+b');
        static::assertNotFalse($stream);

        return $stream;
    }
}
