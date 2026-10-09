<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Exception\InvalidArgumentException as ConfigException;
use Contenir\Mail\Storage\AbstractStorage;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Maildir;
use Contenir\Mail\Storage\MaildirConfig;
use Contenir\Mail\Storage\MaildirFilename;
use Contenir\Mail\Storage\MaildirFiles;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\Lines;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Tests\Unit\Storage\TestAsset\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function count;
use function file_put_contents;
use function get_resources;
use function iterator_to_array;
use function mkdir;
use function rename;
use function rmdir;
use function scandir;
use function symlink;
use function unlink;

#[CoversClass(Maildir::class)]
#[CoversClass(MaildirConfig::class)]
#[CoversClass(MaildirFiles::class)]
#[CoversClass(MaildirFilename::class)]
#[CoversClass(FileSystem::class)]
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(Lines::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[CoversClass(AbstractStorage::class)]
#[Group('unit')]
final class MaildirTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $directory;

    protected function setUp(): void
    {
        $root = $this->setUpTemporaryDirectory();
        mkdir("{$root}/box");
        $this->directory = Fixtures::maildir("{$root}/box");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function maildir(): Maildir
    {
        return new Maildir(['dirname' => $this->directory]);
    }

    #[Test]
    public function countsMessages(): void
    {
        static::assertSame(5, $this->maildir()->countMessages());
    }

    #[DataProvider('flagCountProvider')]
    #[Test]
    public function countsMessagesWithFlags(array $flags, int $expected): void
    {
        static::assertSame($expected, $this->maildir()->countMessages(...$flags));
    }

    #[Test]
    public function readsMessagesInFileNameOrder(): void
    {
        static::assertSame('A Really Simple Message', $this->maildir()->getMessage(2)->getSubject());
    }

    #[Test]
    public function readsFlags(): void
    {
        static::assertSame([Flag::Flagged, Flag::Seen], $this->maildir()->getMessage(2)->getFlags());
    }

    #[Test]
    public function marksMessagesInNewAsRecent(): void
    {
        static::assertTrue($this->maildir()->getMessage(5)->hasFlag(Flag::Recent));
    }

    #[Test]
    public function readsBody(): void
    {
        static::assertSame("Message\r\n", $this->maildir()->getMessage(2)->getContent());
    }

    #[Test]
    public function readsMultipart(): void
    {
        static::assertSame(2, $this->maildir()->getMessage(4)->countParts());
    }

    #[Test]
    public function readsRawHeader(): void
    {
        static::assertSame(
            "To: bar@example.com\r\nSubject: A Really Simple Message\r\nFrom: foo@example.com\r\n\r\n",
            $this->maildir()->getRawHeader(2),
        );
    }

    #[Test]
    public function readsRawContent(): void
    {
        static::assertSame("Message\r\n", $this->maildir()->getRawContent(2));
    }

    #[Test]
    public function measuresMessage(): void
    {
        static::assertSame(89, $this->maildir()->getSize(2));
    }

    #[Test]
    public function takesSizeFromFileName(): void
    {
        rename(
            "{$this->directory}/cur/1000000001.P1.example.org:2,FS",
            "{$this->directory}/cur/1000000001.P1.example.org,S=1234:2,FS",
        );

        static::assertSame(1234, $this->maildir()->getSize(2));
    }

    #[Test]
    public function measuresEveryMessage(): void
    {
        static::assertSame([1 => 397, 2 => 89, 3 => 694, 4 => 452, 5 => 497], $this->maildir()->getSizes());
    }

    #[Test]
    public function readsUniqueIdFromFileName(): void
    {
        static::assertSame('1000000001.P1.example.org', $this->maildir()->getUniqueId(2));
    }

    #[Test]
    public function listsUniqueIds(): void
    {
        static::assertSame(
            [
                1 => '1000000000.P1.example.org',
                2 => '1000000001.P1.example.org',
                3 => '1000000002.P1.example.org',
                4 => '1000000003.P1.example.org',
                5 => '1000000004.P1.example.org',
            ],
            $this->maildir()->getUniqueIds(),
        );
    }

    #[Test]
    public function findsNumberByUniqueId(): void
    {
        static::assertSame(3, $this->maildir()->getNumberByUniqueId('1000000002.P1.example.org'));
    }

    /**
     * Path traversal: a unique ID is only ever compared, never used as a path.
     */
    #[DataProvider('unknownUniqueIdProvider')]
    #[Test]
    public function refusesUnknownUniqueId(string $id): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Unique ID not found');

        $this->maildir()->getNumberByUniqueId($id);
    }

    #[DataProvider('unknownNumberProvider')]
    #[Test]
    public function refusesMessageNumberThatDoesNotExist(int $id): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage("There is no message {$id}");

        $this->maildir()->getMessage($id);
    }

    #[Test]
    public function isReadOnly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Maildir is read-only; use Writable\Maildir');

        $this->maildir()->removeMessage(1);
    }

    /**
     * Each held message used to keep its file open, so holding a thousand messages ran out of file descriptors.
     */
    #[Test]
    public function holdsNoOpenFilesForHeldMessages(): void
    {
        $maildir  = $this->maildir();
        $before   = count(get_resources('stream'));
        $messages = [];
        foreach ($maildir as $number => $message) {
            $message->getSubject();
            $message->getContent();
            $messages[$number] = $message;
        }

        static::assertSame([5, $before], [count($messages), count(get_resources('stream'))]);
    }

    /**
     * Changing a message's flags renames its file, and a message seen for the first time
     * moves from new to cur; a message held from before still reads.
     */
    #[Test]
    #[DataProvider('renameProvider')]
    public function readsAMessageWhoseFileWasRenamedSinceItWasRead(string $from, string $to): void
    {
        $held     = $this->maildir()->getMessage(1);
        $expected = $held->getContent();

        rename("{$this->directory}/{$from}", "{$this->directory}/{$to}");

        static::assertSame($expected, $held->getContent());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function renameProvider(): array
    {
        return [
            'flags changed in cur'  => ['cur/1000000000.P1.example.org:2,S', 'cur/1000000000.P1.example.org:2,RS'],
            'moved from cur to new' => ['cur/1000000000.P1.example.org:2,S', 'new/1000000000.P1.example.org'],
        ];
    }

    #[Test]
    public function reportsMessageFileThatHasGoneSinceItWasRead(): void
    {
        $message = $this->maildir()->getMessage(2);
        unlink("{$this->directory}/cur/1000000001.P1.example.org:2,FS");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open the message file; it may have been moved');

        $message->getContent();
    }

    #[Test]
    public function reportsMessageFileThatHasGone(): void
    {
        $maildir = $this->maildir();
        unlink("{$this->directory}/cur/1000000001.P1.example.org:2,FS");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot open the message file; it may have been moved');

        $maildir->getMessage(2);
    }

    #[Test]
    public function hasNoMessagesOnceClosed(): void
    {
        $maildir = $this->maildir();
        $maildir->close();

        static::assertSame(0, $maildir->countMessages());
    }

    #[Test]
    public function doesNothingOnNoop(): void
    {
        $maildir = $this->maildir();
        $maildir->noop();

        static::assertSame(5, $maildir->countMessages());
    }

    #[Test]
    public function iteratesNothingInEmptyMaildir(): void
    {
        static::assertSame([], iterator_to_array(new Maildir(['dirname' => "{$this->directory}/.subfolder"])));
    }

    #[Test]
    public function hasFlags(): void
    {
        static::assertTrue($this->maildir()->getCapabilities()['flags']);
    }

    /**
     * Symlink traversal: a link in cur/ is not a message, so a link to another file cannot be read.
     */
    #[Test]
    public function skipsSymbolicLinks(): void
    {
        file_put_contents("{$this->directory}/../secret", data: "Subject: secret\n\nsecret");
        symlink("{$this->directory}/../secret", "{$this->directory}/cur/2000000000.P1.example.org:2,S");

        static::assertSame(5, $this->maildir()->countMessages());
    }

    /**
     * Symlink traversal: a cur/ that is a link is not read, so it cannot point the maildir elsewhere.
     */
    #[Test]
    public function skipsLinkedCur(): void
    {
        $maildir = "{$this->directory}/.subfolder.test";
        foreach ((array) scandir("{$maildir}/cur") as $entry) {
            if (! ('.' !== $entry && '..' !== $entry)) {
                continue;
            }

            unlink("{$maildir}/cur/{$entry}");
        }

        rmdir("{$maildir}/cur");
        symlink("{$this->directory}/cur", "{$maildir}/cur");

        static::assertSame(0, (new Maildir(['dirname' => $maildir]))->countMessages());
    }

    #[Test]
    public function skipsHiddenFiles(): void
    {
        file_put_contents("{$this->directory}/cur/.hidden", data: "Subject: x\n\nx");

        static::assertSame(5, $this->maildir()->countMessages());
    }

    #[Test]
    public function skipsDirectories(): void
    {
        mkdir("{$this->directory}/cur/directory");

        static::assertSame(5, $this->maildir()->countMessages());
    }

    #[Test]
    public function readsMaildirWithoutNew(): void
    {
        unlink("{$this->directory}/new/1000000004.P1.example.org");
        rmdir("{$this->directory}/new");

        static::assertSame(4, $this->maildir()->countMessages());
    }

    #[DataProvider('notMaildirProvider')]
    #[Test]
    public function refusesDirectoryThatIsNotMaildir(string $remove, string $file): void
    {
        if ('' !== $remove) {
            self::removeFiles("{$this->directory}/{$remove}");
        }

        file_put_contents("{$this->directory}/{$file}", data: 'x');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a maildir');

        $this->maildir();
    }

    #[Test]
    public function refusesMissingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a maildir');

        new Maildir(['dirname' => "{$this->directory}/missing"]);
    }

    #[Test]
    public function refusesUnreadableCur(): void
    {
        chmod("{$this->directory}/cur", permissions: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read directory');

        $this->maildir();
    }

    #[Test]
    public function takesConfigObject(): void
    {
        static::assertSame(5, (new Maildir(new MaildirConfig($this->directory)))->countMessages());
    }

    #[Test]
    public function requiresDirname(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\MaildirConfig: option "dirname" is required');

        MaildirConfig::fromIterable([]);
    }

    #[Test]
    public function refusesDirnameThatIsNotLocal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dirname must be a local file system path');

        new MaildirConfig('http://example.com/');
    }

    #[DataProvider('filenameProvider')]
    #[Test]
    public function parsesFileName(string $name, array $expected): void
    {
        static::assertSame($expected, MaildirFilename::parse($name, []));
    }

    #[Test]
    public function keepsDefaultFlagsWhenParsing(): void
    {
        static::assertSame([Flag::Recent, Flag::Seen], MaildirFilename::parse('a:2,S', [Flag::Recent])['flags']);
    }

    /**
     * @return array<string, array{list<Flag|string>, int}>
     */
    public static function flagCountProvider(): array
    {
        return [
            'none'      => [[], 5],
            'seen'      => [[Flag::Seen], 4],
            'imap name' => [['\Flagged'], 1],
            'two'       => [[Flag::Seen, Flag::Flagged], 1],
            'recent'    => [[Flag::Recent], 1],
            'not set'   => [[Flag::Draft], 0],
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
            'past end' => [6],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownUniqueIdProvider(): array
    {
        return [
            'traversal' => ['../../etc/passwd'],
            'partial'   => ['1000000001'],
            'empty'     => [''],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function notMaildirProvider(): array
    {
        return [
            'no cur'      => ['cur', 'unused'],
            'new is file' => ['new', 'new'],
            'tmp is file' => ['', 'tmp'],
        ];
    }

    /**
     * @return array<string, array{string, array{uniq: string, flags: list<Flag|string>, size: int|null}}>
     */
    public static function filenameProvider(): array
    {
        return [
            'plain'             => ['1.P1.host', ['uniq' => '1.P1.host', 'flags' => [], 'size' => null]],
            'flags'             => [
                '1.P1.host:2,FRS',
                ['uniq' => '1.P1.host', 'flags' => [Flag::Flagged, Flag::Answered, Flag::Seen], 'size' => null],
            ],
            'size'              => ['1.P1.host,S=42:2,', ['uniq' => '1.P1.host,S=42', 'flags' => [], 'size' => 42]],
            'size then field'   => [
                '1.P1.host,S=42,W=44:2,T',
                ['uniq' => '1.P1.host,S=42,W=44', 'flags' => [Flag::Deleted], 'size' => 42],
            ],
            'keyword'           => ['1:2,a', ['uniq' => '1', 'flags' => ['a'], 'size' => null]],
            'repeated flag'     => ['1:2,SS', ['uniq' => '1', 'flags' => [Flag::Seen], 'size' => null]],
            'version 1 info'    => ['1:1,S', ['uniq' => '1', 'flags' => [], 'size' => null]],
            'size not a number' => ['1,S=x', ['uniq' => '1,S=x', 'flags' => [], 'size' => null]],
            'huge size ignored' => [
                '1,S=9999999999999999999',
                ['uniq' => '1,S=9999999999999999999', 'flags' => [], 'size' => null],
            ],
            'no comma before S' => ['1S=42', ['uniq' => '1S=42', 'flags' => [], 'size' => null]],
        ];
    }

    private static function removeFiles(string $path): void
    {
        foreach ((array) scandir($path) as $entry) {
            if (! ('.' !== $entry && '..' !== $entry)) {
                continue;
            }

            unlink("{$path}/{$entry}");
        }

        rmdir($path);
    }
}
