<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayIterator;
use Contenir\Mail\Exception\InvalidArgumentException as ConfigException;
use Contenir\Mail\Storage\AbstractStorage;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\LocalPath;
use Contenir\Mail\Storage\Mbox;
use Contenir\Mail\Storage\MboxConfig;
use Contenir\Mail\Storage\MboxFormat;
use Contenir\Mail\Storage\MboxScanner;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Tests\Unit\Storage\TestAsset\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function chmod;
use function file_put_contents;
use function iterator_to_array;
use function serialize;
use function str_repeat;
use function unserialize;

#[CoversClass(Mbox::class)]
#[CoversClass(MboxScanner::class)]
#[CoversClass(MboxConfig::class)]
#[CoversClass(LocalPath::class)]
#[CoversClass(AbstractStorage::class)]
#[CoversClass(FileSystem::class)]
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[Group('unit')]
final class MboxTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function mbox(string $fixture = 'INBOX'): Mbox
    {
        return new Mbox(['filename' => Fixtures::mbox($this->directory, $fixture)]);
    }

    private function file(string $contents): string
    {
        $path = "{$this->directory}/mbox";
        file_put_contents($path, data: $contents);

        return $path;
    }

    #[DataProvider('fixtureProvider')]
    #[Test]
    public function countsMessages(string $fixture): void
    {
        static::assertSame(7, $this->mbox($fixture)->countMessages());
    }

    #[DataProvider('fixtureProvider')]
    #[Test]
    public function readsHeaders(string $fixture): void
    {
        static::assertSame('Simple Message', $this->mbox($fixture)->getMessage(1)->getSubject());
    }

    /**
     * Mbox files with bare LF line breaks read like CRLF ones.
     */
    #[DataProvider('fixtureProvider')]
    #[Test]
    public function readsBody(string $fixture): void
    {
        static::assertSame("Message\r\n", $this->mbox($fixture)->getMessage(2)->getEncodedContent());
    }

    #[DataProvider('fixtureProvider')]
    #[Test]
    public function readsMultipartParts(string $fixture): void
    {
        static::assertSame('Again a simple message', $this->mbox($fixture)->getMessage(5)->getPart(2)->getContent());
    }

    #[DataProvider('fixtureProvider')]
    #[Test]
    public function readsMessageWithoutBody(string $fixture): void
    {
        static::assertSame('no body', $this->mbox($fixture)->getMessage(6)->getSubject());
    }

    #[Test]
    public function readsRepeatedHeaders(): void
    {
        $twins = $this->mbox()->getMessage(3)->getHeaders()->all('X-Twin');

        static::assertSame(
            ['the good', 'the evil'],
            array_map(static fn($header): string => $header->getFieldValue(), $twins),
        );
    }

    #[Test]
    public function readsRawHeader(): void
    {
        static::assertSame(
            "To: bar@example.com\r\nSubject: A Really Simple Message\r\nFrom: foo@example.com\r\n\r\n",
            $this->mbox()->getRawHeader(2),
        );
    }

    #[Test]
    public function readsRawContent(): void
    {
        static::assertSame("Message\r\n", $this->mbox()->getRawContent(2));
    }

    #[Test]
    public function measuresMessage(): void
    {
        static::assertSame(89, $this->mbox()->getSize(2));
    }

    #[Test]
    public function measuresEveryMessage(): void
    {
        static::assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($this->mbox()->getSizes()));
    }

    #[Test]
    public function measuresEveryMessageAlike(): void
    {
        $mbox = $this->mbox();

        static::assertSame($mbox->getSize(2), $mbox->getSizes()[2]);
    }

    #[Test]
    public function iteratesMessagesByNumber(): void
    {
        static::assertSame([1, 2, 3, 4, 5, 6, 7], array_keys(iterator_to_array($this->mbox())));
    }

    #[Test]
    public function countsWithCount(): void
    {
        static::assertCount(7, $this->mbox());
    }

    #[Test]
    public function countsNoMessagesWithFlags(): void
    {
        static::assertSame(0, $this->mbox()->countMessages(Flag::Seen));
    }

    #[Test]
    public function usesNumbersAsUniqueIds(): void
    {
        static::assertSame('3', $this->mbox()->getUniqueId(3));
    }

    #[Test]
    public function listsNumbersAsUniqueIds(): void
    {
        static::assertSame(
            [1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7'],
            $this->mbox()->getUniqueIds(),
        );
    }

    #[Test]
    public function findsNumberByUniqueId(): void
    {
        static::assertSame(4, $this->mbox()->getNumberByUniqueId('4'));
    }

    #[DataProvider('unknownUniqueIdProvider')]
    #[Test]
    public function refusesUnknownUniqueId(string $id): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage("There is no message {$id}");

        $this->mbox()->getNumberByUniqueId($id);
    }

    #[DataProvider('unknownNumberProvider')]
    #[Test]
    public function refusesMessageNumberThatDoesNotExist(int $id): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage("There is no message {$id}");

        $this->mbox()->getMessage($id);
    }

    #[Test]
    public function refusesUniqueIdOfMessageThatDoesNotExist(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no message 8');

        $this->mbox()->getUniqueId(8);
    }

    #[Test]
    public function isReadOnly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mbox is read-only');

        $this->mbox()->removeMessage(1);
    }

    #[Test]
    public function hasTopAndNoUniqueIds(): void
    {
        $capabilities = $this->mbox()->getCapabilities();

        static::assertSame([true, false], [$capabilities['top'], $capabilities['uniqueid']]);
    }

    #[Test]
    public function doesNothingOnNoop(): void
    {
        $mbox = $this->mbox();
        $mbox->noop();

        static::assertSame(7, $mbox->countMessages());
    }

    #[Test]
    public function hasNoMessagesOnceClosed(): void
    {
        $mbox = $this->mbox();
        $mbox->close();

        static::assertSame(0, $mbox->countMessages());
    }

    #[Test]
    public function canBeClosedTwice(): void
    {
        $mbox = $this->mbox();
        $mbox->close();
        $mbox->close();

        static::assertSame(0, $mbox->countMessages());
    }

    #[Test]
    public function keepsMessagesReadableOnceClosed(): void
    {
        $mbox    = $this->mbox();
        $message = $mbox->getMessage(2);
        $mbox->close();

        static::assertSame("Message\r\n", $message->getContent());
    }

    #[Test]
    public function refusesMessagesOnceClosed(): void
    {
        $mbox = $this->mbox();
        $mbox->close();

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no message 1');

        $mbox->getMessage(1);
    }

    #[Test]
    public function takesConfigObject(): void
    {
        static::assertSame(7, (new Mbox(new MboxConfig(Fixtures::mbox($this->directory))))->countMessages());
    }

    #[Test]
    public function takesTraversableConfig(): void
    {
        $config = new ArrayIterator(['filename' => Fixtures::mbox($this->directory)]);

        static::assertSame(7, (new Mbox($config))->countMessages());
    }

    #[Test]
    public function refusesFileThatIsNotMbox(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not an mbox file');

        new Mbox(['filename' => $this->file("Subject: x\r\n\r\nnot mbox")]);
    }

    #[Test]
    public function refusesEmptyFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not an mbox file');

        new Mbox(['filename' => $this->file('')]);
    }

    #[Test]
    public function refusesDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$this->directory} is not a file");

        new Mbox(['filename' => $this->directory]);
    }

    #[Test]
    public function refusesMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a file');

        new Mbox(['filename' => "{$this->directory}/missing"]);
    }

    #[Test]
    public function refusesUnreadableFile(): void
    {
        $path = $this->file("From a\r\n\r\n");
        chmod($path, permissions: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open mbox file');

        new Mbox(['filename' => $path]);
    }

    #[Test]
    public function readsSingleMessage(): void
    {
        static::assertSame(
            'only',
            (new Mbox(['filename' => $this->file("From a\nSubject: only\n\nbody\n")]))->getMessage(1)
                ->getSubject(),
        );
    }

    #[Test]
    public function readsFromLineLongerThanAChunk(): void
    {
        $mbox = new Mbox([
            'filename' => $this->file('From ' . str_repeat('a', times: 9000) . "\nSubject: x\n\nbody\n"),
        ]);

        static::assertSame(1, $mbox->countMessages());
    }

    #[Test]
    public function ignoresFromInsideALongLine(): void
    {
        $mbox = new Mbox([
            'filename' => $this->file("From a\nSubject: x\n\n" . str_repeat('a', times: 8192) . "From b\n"),
        ]);

        static::assertSame(1, $mbox->countMessages());
    }

    #[Test]
    public function endsMessageBeforeTheLineBreakAheadOfFrom(): void
    {
        $mbox = new Mbox(['filename' => $this->file("From a\nSubject: x\n\nbody\nFrom b\nSubject: y\n\n")]);

        static::assertSame('body', $mbox->getMessage(1)->getContent());
    }

    /**
     * mboxrd: one ">" is removed from ">From " lines, restoring the body exactly.
     */
    #[Test]
    public function unquotesFromLinesInMboxrd(): void
    {
        $path = $this->file("From a\nSubject: x\n\n>From here\n>>From there\n");

        static::assertSame(
            "From here\n>From there\n",
            (new Mbox(new MboxConfig($path, MboxFormat::Mboxrd)))->getMessage(1)
                ->getContent(),
        );
    }

    #[Test]
    public function keepsQuotedFromLinesInMboxo(): void
    {
        $path = $this->file("From a\nSubject: x\n\n>From here\n");

        static::assertSame(
            ">From here\n",
            (new Mbox(['filename' => $path]))->getMessage(1)
                ->getContent(),
        );
    }

    #[Test]
    public function readsFormatSetting(): void
    {
        static::assertSame(
            MboxFormat::Mboxrd,
            MboxConfig::fromIterable(['filename' => 'x', 'format' => 'mboxrd'])->format,
        );
    }

    /**
     * Arbitrary file and remote reads: a stream wrapper in the filename is refused.
     */
    #[DataProvider('unsafePathProvider')]
    #[Test]
    public function refusesPathThatIsNotLocal(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('filename must be a local file system path');

        new MboxConfig($path);
    }

    #[Test]
    public function acceptsWindowsDrivePath(): void
    {
        static::assertSame('C:\\mail\\inbox', (new MboxConfig('C:\\mail\\inbox'))->filename);
    }

    #[Test]
    public function requiresFilename(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\MboxConfig: option "filename" is required');

        MboxConfig::fromIterable([]);
    }

    #[Test]
    public function refusesUnknownSetting(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown option "messageEOL"');

        MboxConfig::fromIterable(['filename' => 'x', 'messageEOL' => "\n"]);
    }

    #[Test]
    public function refusesFilenameOfTheWrongType(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('option "filename" must be a string, got int');

        MboxConfig::fromIterable(['filename' => 1]);
    }

    /**
     * Unserialize hazards: a storage can be neither serialized nor built by unserialize().
     */
    #[Test]
    public function cannotBeSerialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\Mbox cannot be serialized');

        serialize($this->mbox());
    }

    #[Test]
    public function cannotBeUnserialized(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\Mbox cannot be unserialized');

        unserialize('O:26:"Contenir\Mail\Storage\Mbox":1:{s:8:"filename";s:11:"/etc/passwd";}');
    }

    #[Test]
    public function readsMessagesAsMessages(): void
    {
        static::assertInstanceOf(Message::class, $this->mbox()->getMessage(1));
    }

    #[Test]
    public function measuresMissingFileAsEmpty(): void
    {
        static::assertFalse(MboxScanner::isMboxFile("{$this->directory}/missing"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtureProvider(): array
    {
        return [
            'CRLF' => ['INBOX'],
            'LF'   => ['INBOX.unix'],
        ];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unknownNumberProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-1],
            'past end' => [8],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownUniqueIdProvider(): array
    {
        return [
            'not a number' => ['x'],
            'past end'     => ['8'],
            'zero'         => ['0'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePathProvider(): array
    {
        return [
            'phar'  => ['phar:///tmp/x.phar/inbox'],
            'http'  => ['http://example.com/inbox'],
            'php'   => ['php://filter/resource=/etc/passwd'],
            'data'  => ['data:text/plain,From x'],
            'NUL'   => ["/tmp/inbox\0.txt"],
            'empty' => [''],
        ];
    }
}
