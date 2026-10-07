<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Header\Subject;
use Contenir\Mail\Headers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function serialize;
use function str_repeat;
use function unserialize;

#[CoversClass(Headers::class)]
#[Group('unit')]
final class HeadersSerializationTest extends TestCase
{
    private static function restore(mixed $data): Headers
    {
        $headers = (new ReflectionClass(Headers::class))->newInstanceWithoutConstructor();
        $headers->__unserialize($data);

        return $headers;
    }

    #[Test]
    public function keepsParsedTextThroughSerialization(): void
    {
        $headers = Headers::fromString("Subject:   odd  spacing\r\n\tfolded\r\nX-Id: 1\r\n");

        static::assertSame($headers->toString(), unserialize(serialize($headers))->toString());
    }

    #[Test]
    public function keepsBuiltHeadersThroughSerialization(): void
    {
        $headers = new Headers(new Subject('Grüße'), new GenericHeader('X-Id', '1'));

        static::assertSame($headers->toString(), unserialize(serialize($headers))->toString());
    }

    #[Test]
    public function serializesWithoutOriginalTextForBuiltHeaders(): void
    {
        static::assertSame(
            [null],
            (new Headers(new Subject('Hi')))->__serialize()['wireText'],
        );
    }

    #[Test]
    #[DataProvider('tamperedTextProvider')]
    public function dropsOriginalTextThatParsingWouldNotKeep(string $text): void
    {
        $subject = new Subject('Hi');

        static::assertSame(
            "Subject: Hi\r\n",
            self::restore(['headers' => [$subject], 'wireText' => [$text]])->toString(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tamperedTextProvider(): array
    {
        return [
            'another header name'      => ['To: victim@example.com'],
            'second header smuggled'   => ["Subject: Hi\r\nBcc: victim@example.com"],
            'bare line feed'           => ["Subject: Hi\nBcc: victim@example.com"],
            'line over 998 characters' => ['Subject: ' . str_repeat('x', times: 990)],
            'not a header line'        => ['Hi'],
            'blank continuation'       => ["Subject: Hi\r\n \r\n more"],
        ];
    }

    #[Test]
    public function keepsOriginalTextOfTheSameHeaderInAnyCase(): void
    {
        $subject = new Subject('Hi');

        static::assertSame(
            "SUBJECT:  Hi\r\n",
            self::restore(['headers' => [$subject], 'wireText' => ['SUBJECT:  Hi']])->toString(),
        );
    }

    #[Test]
    public function ignoresOriginalTextThatIsNotAString(): void
    {
        static::assertSame(
            "Subject: Hi\r\n",
            self::restore(['headers' => [new Subject('Hi')], 'wireText' => [42]])->toString(),
        );
    }

    #[Test]
    public function ignoresMissingOriginalText(): void
    {
        static::assertSame(
            "Subject: Hi\r\n",
            self::restore(['headers' => [new Subject('Hi')], 'wireText' => []])->toString(),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[Test]
    #[DataProvider('invalidDataProvider')]
    public function refusesDataThatIsNotAListOfHeaders(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serialized headers must hold a list of headers');

        self::restore($data);
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function invalidDataProvider(): array
    {
        return [
            'no headers'               => [['wireText' => []]],
            'headers not an array'     => [['headers' => 'Subject: Hi', 'wireText' => []]],
            'headers keyed by name'    => [['headers' => ['subject' => new Subject('Hi')], 'wireText' => []]],
            'no written text'          => [['headers' => []]],
            'written text not a list'  => [['headers' => [], 'wireText' => 'x']],
            'an entry is not a header' => [['headers' => ['Subject: Hi'], 'wireText' => [null]]],
        ];
    }
}
