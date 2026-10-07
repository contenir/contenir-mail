<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\TransferEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function fopen;
use function fwrite;
use function rewind;
use function serialize;
use function unserialize;

#[CoversClass(Part::class)]
#[Group('unit')]
final class PartSerializationTest extends TestCase
{
    private static function part(): Part
    {
        return new Part(
            'content',
            type: 'text/calendar',
            encoding: TransferEncoding::QuotedPrintable,
            charset: 'UTF-8',
            disposition: Disposition::Attachment,
            filename: 'meeting.ics',
            id: 'cal',
            description: 'Meeting',
            location: 'https://example.com/meeting.ics',
            language: 'en',
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function restore(array $data): Part
    {
        $part = (new ReflectionClass(Part::class))->newInstanceWithoutConstructor();
        $part->__unserialize($data);

        return $part;
    }

    #[Test]
    public function keepsEveryFieldThroughSerialization(): void
    {
        $part = self::part();

        static::assertSame($part->getHeaders()->toString(), unserialize(serialize($part))->getHeaders()->toString());
    }

    #[Test]
    public function keepsContentThroughSerialization(): void
    {
        static::assertSame('content', unserialize(serialize(self::part()))->getContent());
    }

    #[Test]
    public function serializesStreamContentAsText(): void
    {
        $stream = fopen('php://temp', mode: 'r+');
        if (false === $stream) {
            throw new RuntimeException('Cannot open a temporary stream');
        }

        fwrite($stream, data: 'streamed bytes');
        rewind($stream);

        static::assertSame('streamed bytes', unserialize(serialize(new Part($stream)))->getContent());
    }

    #[Test]
    public function keepsUnsetFieldsNull(): void
    {
        static::assertNull(unserialize(serialize(new Part('x')))->getFilename());
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[Test]
    #[DataProvider('invalidDataProvider')]
    public function refusesInvalidData(array $data): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Serialized part data is not valid');

        self::restore($data);
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function invalidDataProvider(): array
    {
        $valid = self::part()->__serialize();

        return [
            'content not a string'     => [['content' => 1] + $valid],
            'no content'               => [['type' => 'text/plain', 'encoding' => TransferEncoding::Base64]],
            'type not a string'        => [['type' => 1] + $valid],
            'encoding not an enum'     => [['encoding' => 'base64'] + $valid],
            'disposition not an enum'  => [['disposition' => 'inline'] + $valid],
            'charset not a string'     => [['charset' => 1] + $valid],
            'filename not a string'    => [['filename' => 1] + $valid],
            'id not a string'          => [['id' => 1] + $valid],
            'description not a string' => [['description' => 1] + $valid],
            'location not a string'    => [['location' => 1] + $valid],
            'language not a string'    => [['language' => 1] + $valid],
        ];
    }

    #[Test]
    public function acceptsMissingDisposition(): void
    {
        static::assertNull(self::restore(['disposition' => null] + self::part()->__serialize())->getDisposition());
    }
}
