<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Folder;

use Contenir\Mail\Exception\InvalidArgumentException as ConfigException;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\Folder\Maildir;
use Contenir\Mail\Storage\Folder\MaildirConfig;
use Contenir\Mail\Storage\Folder\MaildirTree;
use Contenir\Mail\Storage\LocalPath;
use Contenir\Mail\Storage\MaildirFiles;
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
use function iterator_to_array;
use function mkdir;
use function rename;
use function symlink;

#[CoversClass(Maildir::class)]
#[CoversClass(MaildirConfig::class)]
#[CoversClass(MaildirTree::class)]
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[CoversClass(MaildirFiles::class)]
#[CoversClass(FileSystem::class)]
#[CoversClass(LocalPath::class)]
#[CoversClass(Folder::class)]
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

    private function maildir(string $folder = 'INBOX'): Maildir
    {
        return new Maildir(['dirname' => $this->directory, 'folder' => $folder]);
    }

    /**
     * @return list<string>
     */
    private function globalNames(Maildir $maildir): array
    {
        return array_map(
            static fn(Folder $folder): string => $folder->getGlobalName(),
            iterator_to_array(
                new RecursiveIteratorIterator($maildir->getFolders(), RecursiveIteratorIterator::SELF_FIRST),
                preserve_keys: false,
            ),
        );
    }

    #[Test]
    public function selectsInboxByDefault(): void
    {
        static::assertSame('INBOX', (new Maildir(['dirname' => $this->directory]))->getCurrentFolder());
    }

    #[Test]
    public function readsInbox(): void
    {
        static::assertSame(5, $this->maildir()->countMessages());
    }

    #[DataProvider('subfolderNameProvider')]
    #[Test]
    public function selectsSubfolder(string $name): void
    {
        static::assertSame(1, $this->maildir($name)->countMessages());
    }

    #[Test]
    public function namesSelectedFolderByGlobalName(): void
    {
        static::assertSame('subfolder.test', $this->maildir('INBOX.subfolder.test')->getCurrentFolder());
    }

    #[Test]
    public function changesFolderByFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->selectFolder($maildir->getFolders('subfolder.test'));

        static::assertSame(1, $maildir->countMessages());
    }

    #[Test]
    public function changesBackToInbox(): void
    {
        $maildir = $this->maildir('subfolder.test');
        $maildir->selectFolder('INBOX');

        static::assertSame(5, $maildir->countMessages());
    }

    #[Test]
    public function buildsTheFolderTree(): void
    {
        static::assertSame(['INBOX', 'subfolder', 'subfolder.test'], $this->globalNames($this->maildir()));
    }

    #[Test]
    public function returnsRootForInbox(): void
    {
        static::assertSame('/', $this->maildir()->getFolders('INBOX')->getGlobalName());
    }

    #[Test]
    public function addsMissingParentThatCannotBeSelected(): void
    {
        mkdir("{$this->directory}/.a.b/cur", permissions: 0o700, recursive: true);

        static::assertFalse($this->maildir()->getFolders('a')->isSelectable());
    }

    #[Test]
    public function addsChildOfMissingParent(): void
    {
        mkdir("{$this->directory}/.a.b/cur", permissions: 0o700, recursive: true);

        static::assertTrue($this->maildir()->getFolders('a.b')->isSelectable());
    }

    #[Test]
    public function refusesToSelectMissingParent(): void
    {
        mkdir("{$this->directory}/.a.b/cur", permissions: 0o700, recursive: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a is not selectable');

        $this->maildir('a');
    }

    #[Test]
    public function usesOtherDelimiter(): void
    {
        rename("{$this->directory}/.subfolder.test", "{$this->directory}/.subfolder:test");
        $maildir = new Maildir(new MaildirConfig($this->directory, ':', 'subfolder:test'));

        static::assertSame(1, $maildir->countMessages());
    }

    /**
     * Malformed trees: a folder name with an empty part is skipped.
     */
    #[Test]
    public function skipsFolderWithEmptyPart(): void
    {
        mkdir("{$this->directory}/.a..b/cur", permissions: 0o700, recursive: true);

        static::assertFalse($this->maildir()->getFolders()->hasFolder('a'));
    }

    #[Test]
    public function skipsDirectoriesThatAreNotMaildirs(): void
    {
        mkdir("{$this->directory}/.notmaildir");

        static::assertFalse($this->maildir()->getFolders()->hasFolder('notmaildir'));
    }

    #[Test]
    public function skipsEntriesWithoutLeadingDot(): void
    {
        mkdir("{$this->directory}/plain/cur", permissions: 0o700, recursive: true);

        static::assertFalse($this->maildir()->getFolders()->hasFolder('plain'));
    }

    /**
     * Symlink traversal: a linked maildir is not a folder.
     */
    #[Test]
    public function skipsSymbolicLinks(): void
    {
        mkdir("{$this->directory}/../elsewhere/cur", permissions: 0o700, recursive: true);
        symlink("{$this->directory}/../elsewhere", "{$this->directory}/.linked");

        static::assertFalse($this->maildir()->getFolders()->hasFolder('linked'));
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

        $this->maildir($folder);
    }

    #[Test]
    public function readsNameOfOnlyDelimitersAsInbox(): void
    {
        static::assertSame('INBOX', $this->maildir('..')->getCurrentFolder());
    }

    #[Test]
    public function reportsFolderThatHasGone(): void
    {
        $maildir = $this->maildir();
        chmod("{$this->directory}/.subfolder.test/cur", permissions: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The maildir has gone');

        $maildir->selectFolder('subfolder.test');
    }

    #[Test]
    public function refusesUnreadableRoot(): void
    {
        chmod($this->directory, permissions: 0o300);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read directory');

        $this->maildir();
    }

    #[Test]
    public function readsSettings(): void
    {
        $config = MaildirConfig::fromIterable(['dirname' => '/mail', 'delim' => ':', 'folder' => 'Archive']);

        static::assertSame(['/mail', ':', 'Archive'], [$config->dirname, $config->delim, $config->folder]);
    }

    #[Test]
    public function requiresDirname(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\Folder\MaildirConfig: option "dirname" is required');

        MaildirConfig::fromIterable([]);
    }

    /**
     * Path traversal: the delimiter becomes part of directory names, so a path separator is refused.
     */
    #[DataProvider('unsafeDelimiterProvider')]
    #[Test]
    public function refusesUnsafeDelimiter(string $delim): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('delim must be one character other than "/", "\" and NUL');

        new MaildirConfig('/mail', $delim);
    }

    #[Test]
    public function refusesDirnameThatIsNotLocal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dirname must be a local file system path');

        new MaildirConfig('phar:///x');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function subfolderNameProvider(): array
    {
        return [
            'global name'     => ['subfolder.test'],
            'under INBOX'     => ['INBOX.subfolder.test'],
            'with delimiters' => ['.subfolder.test.'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownFolderProvider(): array
    {
        return [
            'parent path'   => ['../x'],
            'absolute path' => ['/etc'],
            'missing'       => ['nothing'],
            'NUL'           => ["subfolder\0"],
            'missing child' => ['subfolder.nothing'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeDelimiterProvider(): array
    {
        return [
            'slash'     => ['/'],
            'backslash' => ['\\'],
            'NUL'       => ["\0"],
            'empty'     => [''],
            'two'       => ['::'],
        ];
    }
}
