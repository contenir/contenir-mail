<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Folder;

use Contenir\Mail\Exception\InvalidArgumentException as ConfigException;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\Folder\Mbox;
use Contenir\Mail\Storage\Folder\MboxConfig;
use Contenir\Mail\Storage\Folder\MboxTree;
use Contenir\Mail\Storage\LocalPath;
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
use RecursiveIteratorIterator;

use function array_map;
use function chmod;
use function file_put_contents;
use function iterator_to_array;
use function mkdir;
use function str_repeat;
use function symlink;
use function unlink;

#[CoversClass(Mbox::class)]
#[CoversClass(MboxConfig::class)]
#[CoversClass(MboxTree::class)]
#[CoversClass(MboxScanner::class)]
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[CoversClass(FileSystem::class)]
#[CoversClass(LocalPath::class)]
#[CoversClass(Folder::class)]
#[Group('unit')]
final class MboxTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $directory;

    protected function setUp(): void
    {
        $root = $this->setUpTemporaryDirectory();
        mkdir("{$root}/box");
        $this->directory = Fixtures::mboxTree("{$root}/box");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function mbox(string $folder = 'INBOX'): Mbox
    {
        return new Mbox(['dirname' => $this->directory, 'folder' => $folder]);
    }

    /**
     * @return list<string>
     */
    private function globalNames(Mbox $mbox): array
    {
        return array_map(
            static fn(Folder $folder): string => $folder->getGlobalName(),
            iterator_to_array(
                new RecursiveIteratorIterator($mbox->getFolders(), RecursiveIteratorIterator::SELF_FIRST),
                preserve_keys: false,
            ),
        );
    }

    #[Test]
    public function selectsInboxByDefault(): void
    {
        static::assertSame('/INBOX', (new Mbox(['dirname' => $this->directory]))->getCurrentFolder());
    }

    #[Test]
    public function readsTheSelectedFolder(): void
    {
        static::assertSame(1, $this->mbox('subfolder/test')->countMessages());
    }

    #[Test]
    public function changesFolder(): void
    {
        $mbox = $this->mbox();
        $mbox->selectFolder('/subfolder/test');

        static::assertSame('/subfolder/test', $mbox->getCurrentFolder());
    }

    #[Test]
    public function changesFolderByFolder(): void
    {
        $mbox = $this->mbox();
        $mbox->selectFolder($mbox->getFolders()->getFolder('subfolder')->getFolder('test'));

        static::assertSame(1, $mbox->countMessages());
    }

    #[Test]
    public function buildsTheFolderTree(): void
    {
        static::assertSame(['/INBOX', '/subfolder', '/subfolder/test'], $this->globalNames($this->mbox()));
    }

    #[Test]
    public function returnsSubtree(): void
    {
        static::assertSame('/subfolder/test', $this->mbox()->getFolders('subfolder/test')->getGlobalName());
    }

    #[Test]
    public function knowsDirectoriesCannotBeSelected(): void
    {
        static::assertFalse($this->mbox()->getFolders('/subfolder')->isSelectable());
    }

    #[Test]
    public function refusesToSelectDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subfolder is not selectable');

        $this->mbox()->selectFolder('subfolder');
    }

    /**
     * Path traversal: only folders found in the tree can be selected.
     */
    #[DataProvider('unknownFolderProvider')]
    #[Test]
    public function refusesFolderOutsideTheTree(string $folder): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->mbox()->selectFolder($folder);
    }

    /**
     * Symlink traversal: links are not folders, so a link to a file outside the tree cannot be read.
     */
    #[Test]
    public function skipsSymbolicLinks(): void
    {
        file_put_contents("{$this->directory}/../outside", data: "From a\nSubject: secret\n\n");
        symlink("{$this->directory}/../outside", "{$this->directory}/linked");

        static::assertFalse($this->mbox()->getFolders()->hasFolder('linked'));
    }

    #[Test]
    public function skipsHiddenEntries(): void
    {
        file_put_contents("{$this->directory}/.hidden", data: "From a\nSubject: x\n\n");

        static::assertFalse($this->mbox()->getFolders()->hasFolder('.hidden'));
    }

    #[Test]
    public function skipsUnreadableFiles(): void
    {
        file_put_contents("{$this->directory}/locked", data: "From a\nSubject: x\n\n");
        chmod("{$this->directory}/locked", permissions: 0);

        static::assertFalse($this->mbox()->getFolders()->hasFolder('locked'));
    }

    #[Test]
    public function skipsFilesThatAreNotMbox(): void
    {
        file_put_contents("{$this->directory}/notes.txt", data: 'not mail');

        static::assertFalse($this->mbox()->getFolders()->hasFolder('notes.txt'));
    }

    /**
     * Resource exhaustion: the tree is read only so many directories deep.
     */
    #[Test]
    public function stopsReadingDeepDirectories(): void
    {
        $path = $this->directory . str_repeat('/d', times: Mbox::MAX_DEPTH + 2);
        mkdir($path, permissions: 0o700, recursive: true);
        file_put_contents("{$path}/box", data: "From a\nSubject: x\n\n");
        $global = str_repeat('/d', times: Mbox::MAX_DEPTH + 2) . '/box';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->mbox()->getFolders($global);
    }

    #[Test]
    public function readsDirectoriesUpToTheLimit(): void
    {
        $path = $this->directory . str_repeat('/d', times: Mbox::MAX_DEPTH);
        mkdir($path, permissions: 0o700, recursive: true);
        file_put_contents("{$path}/box", data: "From a\nSubject: x\n\n");

        static::assertTrue(
            $this->mbox()
                ->getFolders(str_repeat('/d', times: Mbox::MAX_DEPTH) . '/box')
                ->isSelectable(),
        );
    }

    #[Test]
    public function rebuildsTreeWhenTheFileHasGone(): void
    {
        $mbox = $this->mbox();
        unlink("{$this->directory}/subfolder/test");
        try {
            $mbox->selectFolder('subfolder/test');
        } catch (RuntimeException) {
            static::assertFalse($mbox->getFolders('subfolder')->hasFolder('test'));

            return;
        }

        static::fail('Selecting a folder whose file has gone must fail');
    }

    #[Test]
    public function reportsFileThatHasGone(): void
    {
        $mbox = $this->mbox();
        unlink("{$this->directory}/subfolder/test");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The mbox file has gone');

        $mbox->selectFolder('subfolder/test');
    }

    #[Test]
    public function refusesDirectoryThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a directory');

        new Mbox(['dirname' => "{$this->directory}/missing"]);
    }

    #[Test]
    public function refusesUnreadableDirectory(): void
    {
        chmod("{$this->directory}/subfolder", permissions: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read directory');

        $this->mbox();
    }

    #[Test]
    public function takesConfigObject(): void
    {
        static::assertSame(7, (new Mbox(new MboxConfig($this->directory)))->countMessages());
    }

    #[Test]
    public function readsMboxrdFolders(): void
    {
        file_put_contents("{$this->directory}/rd", data: "From a\nSubject: x\n\n>From b\n");

        static::assertSame(
            "From b\n",
            (new Mbox(new MboxConfig($this->directory, 'rd', MboxFormat::Mboxrd)))->getMessage(1)
                ->getContent(),
        );
    }

    #[Test]
    public function requiresDirname(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\Folder\MboxConfig: option "dirname" is required');

        MboxConfig::fromIterable(['folder' => 'INBOX']);
    }

    #[Test]
    public function refusesSingleFileSetting(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('unknown option "filename"');

        MboxConfig::fromIterable(['dirname' => $this->directory, 'filename' => 'x']);
    }

    #[Test]
    public function refusesDirnameThatIsNotLocal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dirname must be a local file system path');

        new MboxConfig('phar:///x');
    }

    #[Test]
    public function readsSettings(): void
    {
        $config = MboxConfig::fromIterable(['dirname' => '/mail', 'folder' => 'Archive', 'format' => 'mboxrd']);

        static::assertSame(['/mail', 'Archive', MboxFormat::Mboxrd], [
            $config->dirname,
            $config->folder,
            $config->format,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownFolderProvider(): array
    {
        return [
            'parent directory' => ['../INBOX'],
            'absolute path'    => ['/etc/passwd'],
            'missing'          => ['nothing'],
            'NUL'              => ["INBOX\0x"],
            'hidden'           => ['.hidden'],
        ];
    }
}
