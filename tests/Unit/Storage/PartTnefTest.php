<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Tnef\Contents;
use Contenir\Mail\Storage\Tnef\Reader;
use Contenir\Mail\Storage\Tnef\TnefAttachment;
use Contenir\Mail\Tests\TestAsset\TnefBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_encode;
use function chunk_split;
use function implode;

#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[Group('unit')]
final class PartTnefTest extends TestCase
{
    #[DataProvider('tnefPartProvider')]
    #[Test]
    public function readsTheTnefPartOfAMessage(string $headers): void
    {
        $raw = self::multipart([
            self::leaf('text/plain', 'See attached.'),
            self::tnef($headers, TnefBuilder::outlookMessage()),
        ]);

        static::assertSame(
            ['Quarterly report 2026.pdf', 'notes.txt'],
            self::filenames(Message::fromString($raw)->getTnefContents()),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tnefPartProvider(): array
    {
        return [
            'application/ms-tnef'         => ['Content-Type: application/ms-tnef; name="winmail.dat"'],
            'application/vnd.ms-tnef'     => ['Content-Type: Application/VND.MS-TNEF'],
            'winmail.dat as octet-stream' => [
                "Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"WINMAIL.DAT\"",
            ],
        ];
    }

    #[Test]
    public function readsAPartThatIsItselfTnef(): void
    {
        $part = Part::fromString(self::tnef('Content-Type: application/ms-tnef', TnefBuilder::outlookMessage()));

        static::assertSame(['Quarterly report 2026.pdf', 'notes.txt'], self::filenames($part->getTnefContents()));
    }

    #[Test]
    public function readsTheFirstTnefPartDepthFirst(): void
    {
        $first  = TnefBuilder::create()->attachment('first.txt', '1')->build();
        $second = TnefBuilder::create()->attachment('second.txt', '2')->build();
        $raw    = self::multipart([
            self::multipart([
                self::leaf('text/plain', 'x'),
                self::tnef('Content-Type: application/ms-tnef', $first),
            ], 'inner'),
            self::tnef('Content-Type: application/ms-tnef', $second),
        ]);

        static::assertSame(['first.txt'], self::filenames(Part::fromString($raw)->getTnefContents()));
    }

    #[DataProvider('withoutTnefProvider')]
    #[Test]
    public function hasNoTnefContentsWithoutATnefPart(string $raw): void
    {
        static::assertNull(Message::fromString($raw)->getTnefContents());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function withoutTnefProvider(): array
    {
        return [
            'plain text'  => [self::leaf('text/plain', 'Hello')],
            'attachments' => [self::multipart([
                self::leaf('text/plain', 'Hello'),
                self::tnef(
                    "Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"winmail.bin\"",
                    'x',
                ),
            ])],
        ];
    }

    #[Test]
    public function readsWithTheReaderGiven(): void
    {
        $raw = self::tnef('Content-Type: application/ms-tnef', TnefBuilder::outlookMessage());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The TNEF data holds more than 1 attachments');

        Message::fromString($raw)->getTnefContents(new Reader(maxAttachments: 1));
    }

    #[Test]
    public function refusesAMalformedTnefPart(): void
    {
        $raw = self::tnef('Content-Type: application/ms-tnef', 'not TNEF at all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The data is not TNEF: it does not start with the TNEF signature');

        Message::fromString($raw)->getTnefContents();
    }

    /**
     * @return list<string>
     */
    private static function filenames(?Contents $contents): array
    {
        return array_map(
            static fn(TnefAttachment $attachment): string => $attachment->filename,
            $contents->attachments ?? [],
        );
    }

    private static function tnef(string $headers, string $bytes): string
    {
        return "{$headers}\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($bytes));
    }

    private static function leaf(string $type, string $body): string
    {
        return "Content-Type: {$type}\r\n\r\n{$body}";
    }

    /**
     * @param list<string> $parts
     */
    private static function multipart(array $parts, string $boundary = 'outer'): string
    {
        return (
            "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n--{$boundary}\r\n"
                . implode("\r\n--{$boundary}\r\n", $parts)
                . "\r\n--{$boundary}--\r\n"
        );
    }
}
