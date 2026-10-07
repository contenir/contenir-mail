<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Exception;
use Contenir\Mail\Message;
use Contenir\Mail\MessageFactory;
use Contenir\Mail\Mime\PartInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(MessageFactory::class)]
#[Group('unit')]
final class MessageFactoryTest extends TestCase
{
    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('addressOptionProvider')]
    #[Test]
    public function setsAddressHeaderFromOption(string $option, string $header, array $expected): void
    {
        $message = MessageFactory::getInstance([$option => 'test@example.com']);

        static::assertSame($expected, $this->emails($message, $header));
    }

    #[Test]
    public function setsSenderFromOption(): void
    {
        $message = MessageFactory::getInstance(['sender' => 'matthew@example.com']);

        static::assertSame('matthew@example.com', $message->getSender()?->getEmail());
    }

    #[Test]
    public function setsSubjectFromOption(): void
    {
        static::assertSame('subject', MessageFactory::getInstance(['subject' => 'subject'])->getSubject());
    }

    #[Test]
    public function setsBodyFromOption(): void
    {
        static::assertSame('body', MessageFactory::getInstance(['body' => 'body'])->getBody());
    }

    #[DataProvider('textOptionProvider')]
    #[Test]
    public function buildsBodyFromTextOption(string $option, string $expected): void
    {
        $body = MessageFactory::getInstance([$option => 'content'])->getBody();
        static::assertInstanceOf(PartInterface::class, $body);

        static::assertSame($expected, $body->getHeaders()->get('Content-Type')?->getFieldValue());
    }

    #[Test]
    public function setsManyRecipientsFromArrayOption(): void
    {
        $message = MessageFactory::getInstance(['to' => ['test@example.com', 'list@example.com']]);

        static::assertSame(['test@example.com', 'list@example.com'], $this->emails($message, 'To'));
    }

    #[Test]
    public function setsDisplayNameFromArrayOption(): void
    {
        $message = MessageFactory::getInstance(['from' => ['matthew@example.com' => 'Matthew']]);

        static::assertSame('Matthew', $message->getFrom()->get('matthew@example.com')?->getName());
    }

    /**
     * The body charset now belongs to its text parts, so the old "encoding" option has no setter.
     */
    #[DataProvider('unrecognisedOptionProvider')]
    #[Test]
    public function ignoresUnrecognisedOptions(string $option): void
    {
        $message = MessageFactory::getInstance([$option => 'UTF-8']);

        static::assertSame(['Date'], array_keys($message->getHeaders()->toArray()));
    }

    #[Test]
    public function createsEmptyMessageWithoutOptions(): void
    {
        static::assertSame(['Date'], array_keys(MessageFactory::getInstance()->getHeaders()->toArray()));
    }

    #[DataProvider('invalidOptionsProvider')]
    #[Test]
    public function rejectsOptionsThatAreNotIterable(mixed $options, string $type): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "\"Contenir\\Mail\\MessageFactory::getInstance\" expects an array or Traversable; received \"{$type}\"",
        );

        MessageFactory::getInstance($options);
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function addressOptionProvider(): array
    {
        return [
            'from'     => ['from', 'From', ['test@example.com']],
            'to'       => ['to', 'To', ['test@example.com']],
            'cc'       => ['cc', 'Cc', ['test@example.com']],
            'bcc'      => ['bcc', 'Bcc', ['test@example.com']],
            'reply-to' => ['reply-to', 'Reply-To', ['test@example.com']],
            'reply_to' => ['reply_to', 'Reply-To', ['test@example.com']],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function textOptionProvider(): array
    {
        return [
            'text' => ['text', 'text/plain; charset="UTF-8"'],
            'html' => ['html', 'text/html; charset="UTF-8"'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unrecognisedOptionProvider(): array
    {
        return [
            'unknown'  => ['foo'],
            'encoding' => ['encoding'],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidOptionsProvider(): array
    {
        return [
            'null'         => [null, 'NULL'],
            'bool'         => [true, 'boolean'],
            'int'          => [1, 'integer'],
            'float'        => [1.1, 'double'],
            'string'       => ['not-an-array', 'string'],
            'plain-object' => [(object) ['from' => 'matthew@example.com'], 'stdClass'],
        ];
    }

    /**
     * @return list<string>
     */
    private function emails(Message $message, string $header): array
    {
        $list = match ($header) {
            'From'  => $message->getFrom(),
            'To'    => $message->getTo(),
            'Cc'    => $message->getCc(),
            'Bcc'   => $message->getBcc(),
            default => $message->getReplyTo(),
        };

        $emails = [];
        foreach ($list as $address) {
            $emails[] = $address->getEmail();
        }

        return $emails;
    }
}
