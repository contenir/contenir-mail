<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use ArrayObject;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Contenir\Mail\Mime\StreamOutput;
use Contenir\Mail\Tests\TestAsset\RecordingWriteStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function stream_context_create;
use function stream_get_contents;

#[CoversClass(StreamOutput::class)]
#[Group('unit')]
final class StreamOutputTest extends TestCase
{
    #[Test]
    public function acceptsAnOpenStream(): void
    {
        StreamOutput::check(self::memory());

        $this->addToAssertionCount(1);
    }

    #[DataProvider('notAStreamProvider')]
    #[Test]
    public function refusesSomethingOtherThanAnOpenStream(mixed $stream): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        StreamOutput::check($stream);
    }

    #[Test]
    public function refusesClosedStream(): void
    {
        $stream = self::memory();
        fclose($stream);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected an open stream');

        StreamOutput::check($stream);
    }

    #[Test]
    public function writesTheBytes(): void
    {
        $stream = self::memory();
        StreamOutput::write($stream, 'Hello');
        StreamOutput::write($stream, ', world');

        static::assertSame('Hello, world', stream_get_contents($stream, offset: 0));
    }

    #[Test]
    public function failsWhenTheStreamRefusesTheBytes(): void
    {
        $stream = fopen(__FILE__, mode: 'rb');
        static::assertNotFalse($stream);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(
            '/^Cannot write the message to the stream: fwrite\(\): Write of 5 bytes failed/',
        );

        StreamOutput::write($stream, 'Hello');
    }

    #[Test]
    public function failsWhenTheStreamTakesOnlySomeOfTheBytes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^Cannot write the message to the stream$/');

        StreamOutput::write(RecordingWriteStream::open(new ArrayObject(), accept: 2), 'Hello');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notAStreamProvider(): array
    {
        return [
            'string'         => ['php://memory'],
            'null'           => [null],
            'stream context' => [stream_context_create()],
        ];
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', mode: 'w+b');
        static::assertNotFalse($stream);

        return $stream;
    }
}
