<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Writable;

use Closure;
use Contenir\Mail\Message as ComposedMessage;
use Contenir\Mail\Mime\Exception\RuntimeException as MimeException;
use Contenir\Mail\Mime\Part as MimePart;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\FileSystem;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Folder\MaildirTree;
use Contenir\Mail\Storage\MaildirFilename;
use Contenir\Mail\Storage\MaildirFiles;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Storage\RawMessage;
use Contenir\Mail\Storage\Writable\Maildir;
use Contenir\Mail\Storage\Writable\MaildirConfig;
use Contenir\Mail\Storage\Writable\MaildirDelivery;
use Contenir\Mail\Storage\Writable\MaildirName;
use Contenir\Mail\Storage\Writable\MaildirQuota;
use Contenir\Mail\Tests\TestAsset\Storage\Fixtures;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_values;
use function chmod;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function fopen;
use function fwrite;
use function getcwd;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rewind;
use function rmdir;
use function scandir;
use function str_repeat;
use function symlink;
use function touch;
use function unlink;

#[CoversClass(Maildir::class)]
#[CoversClass(FileSystem::class)]
/**
 * @mago-expect lint:cyclomatic-complexity One small test for each behaviour of the writable maildir.
 */
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[CoversClass(MaildirConfig::class)]
#[CoversClass(MaildirDelivery::class)]
#[CoversClass(MaildirName::class)]
#[CoversClass(MaildirQuota::class)]
#[CoversClass(RawMessage::class)]
#[CoversClass(MaildirFiles::class)]
#[CoversClass(MaildirFilename::class)]
#[CoversClass(MaildirTree::class)]
#[Group('unit')]
final class MaildirTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private const string MESSAGE = "From: me@example.org\r\nTo: you@example.org\r\nSubject: append test\r\n\r\nThis is a test\r\n";

    private string $root;

    private string $directory;

    protected function setUp(): void
    {
        $this->root = $this->setUpTemporaryDirectory();
        mkdir("{$this->root}/box");
        $this->directory = Fixtures::maildir("{$this->root}/box");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function maildir(array $settings = []): Maildir
    {
        return new Maildir(['dirname' => $this->directory, ...$settings]);
    }

    /**
     * @return list<string>
     */
    private function files(string $directory): array
    {
        return array_values(array_diff((array) scandir($directory), ['.', '..']));
    }

    #[Test]
    public function appendsMessage(): void
    {
        $maildir = $this->maildir();
        $maildir->appendMessage(self::MESSAGE);

        static::assertSame('append test', $maildir->getMessage(6)->getSubject());
    }

    #[Test]
    public function returnsNoUidForAnAppendedMessage(): void
    {
        static::assertNull($this->maildir()->appendMessage(self::MESSAGE));
    }

    #[Test]
    public function returnsNoUidForACopy(): void
    {
        static::assertNull($this->maildir()->copyMessage(1, 'subfolder.test'));
    }

    #[Test]
    public function appendsMessageAsSeenByDefault(): void
    {
        $maildir = $this->maildir();
        $maildir->appendMessage(self::MESSAGE);

        static::assertSame([Flag::Seen], $maildir->getMessage(6)->getFlags());
    }

    #[Test]
    public function writesFlagsAndSizeIntoTheFileName(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE, flags: [Flag::Seen, Flag::Answered, 'a']);

        static::assertCount(1, (array) glob("{$this->directory}/cur/*,S=83:2,RSa"));
    }

    #[Test]
    public function readsAppendedMessageAgain(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE, flags: [Flag::Flagged]);

        static::assertSame([Flag::Flagged], $this->maildir()->getMessage(5)->getFlags());
    }

    /**
     * Test leak: delivery writes only inside the maildir, never into the working directory.
     */
    #[Test]
    public function writesNothingIntoTheWorkingDirectory(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE);

        static::assertSame([], (array) glob(getcwd() . '/*.M*P*Q*'));
    }

    #[Test]
    public function leavesNothingInTmp(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE);

        static::assertSame([], $this->files("{$this->directory}/tmp"));
    }

    #[DataProvider('messageProvider')]
    #[Test]
    public function appendsMessageInAnyForm(mixed $message): void
    {
        $maildir = $this->maildir();
        $maildir->appendMessage($message instanceof Closure ? $message() : $message);

        static::assertSame('append test', $maildir->getMessage(6)->getSubject());
    }

    #[Test]
    public function appendsComposedMessage(): void
    {
        $maildir = $this->maildir();
        $maildir->appendMessage(
            (new ComposedMessage())->setSubject('composed')
                ->setBody('x'),
        );

        static::assertSame('composed', $maildir->getMessage(6)->getSubject());
    }

    #[Test]
    public function refusesMessageOfUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A message must be a string, a stream, or a message object');

        $this->maildir()->appendMessage(42);
    }

    #[Test]
    public function removesTemporaryFileWhenWritingFails(): void
    {
        try {
            $this->maildir()->appendMessage(42);
        } catch (InvalidArgumentException) {
            static::assertSame([], $this->files("{$this->directory}/tmp"));

            return;
        }

        static::fail('A message of an unknown kind must be refused');
    }

    #[Test]
    public function appendsRecentMessageToNew(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE, recent: true);

        static::assertCount(2, $this->files("{$this->directory}/new"));
    }

    #[Test]
    public function writesNoFlagsForRecentMessage(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE, recent: true);

        static::assertCount(1, (array) glob("{$this->directory}/new/*,S=83"));
    }

    #[Test]
    public function listsRecentMessageAsRecent(): void
    {
        $maildir = $this->maildir();
        $maildir->appendMessage(self::MESSAGE, recent: true);

        static::assertSame([Flag::Recent], $maildir->getMessage(6)->getFlags());
    }

    #[Test]
    public function appendsToOtherFolder(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE, 'subfolder.test');

        static::assertSame(2, $this->maildir(['folder' => 'subfolder.test'])->countMessages());
    }

    #[Test]
    public function doesNotListMessageAppendedToOtherFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->appendMessage(self::MESSAGE, 'subfolder.test');

        static::assertSame(5, $maildir->countMessages());
    }

    #[Test]
    public function createsMissingTmp(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE, 'subfolder');

        static::assertTrue(is_dir("{$this->directory}/.subfolder/tmp"));
    }

    /**
     * File permissions: delivered messages are private to their owner.
     */
    #[Test]
    public function createsMessageFilesPrivately(): void
    {
        $this->maildir()->appendMessage(self::MESSAGE);
        $file = (array) glob("{$this->directory}/cur/*,S=83:2,S");

        static::assertSame(0o600, fileperms((string) ($file[0] ?? '')) & 0o777);
    }

    #[Test]
    public function createsFilesWithConfiguredMode(): void
    {
        $this->maildir(['file_mode' => 0o640])->appendMessage(self::MESSAGE);
        $file = (array) glob("{$this->directory}/cur/*,S=83:2,S");

        static::assertSame(0o640, fileperms((string) ($file[0] ?? '')) & 0o777);
    }

    /**
     * Symlink attacks: nothing is written through a linked tmp/, cur/ or folder.
     */
    #[DataProvider('linkedDirectoryProvider')]
    #[Test]
    public function refusesToWriteThroughSymbolicLink(string $directory): void
    {
        $maildir = $this->maildir();
        mkdir("{$this->root}/elsewhere");
        self::replaceWithLink("{$this->directory}/{$directory}", "{$this->root}/elsewhere");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');

        $maildir->appendMessage(self::MESSAGE, 'subfolder.test');
    }

    /**
     * @return array<string, array{Flag|string}>
     */
    #[DataProvider('unstorableFlagProvider')]
    #[Test]
    public function refusesFlagMaildirCannotStore(Flag|string $flag, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->maildir()->appendMessage(self::MESSAGE, flags: [$flag]);
    }

    #[Test]
    public function refusesToAppendToFolderThatCannotHoldMessages(): void
    {
        mkdir("{$this->directory}/.a.b/cur", permissions: 0o700, recursive: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a cannot hold messages');

        $this->maildir()->appendMessage(self::MESSAGE, 'a');
    }

    #[Test]
    public function copiesMessage(): void
    {
        $maildir = $this->maildir();
        $maildir->copyMessage(1, 'subfolder.test');

        static::assertSame(
            'Simple Message',
            $this->maildir(['folder' => 'subfolder.test'])->getMessage(2)->getSubject(),
        );
    }

    #[Test]
    public function copiesMessageWithoutRecent(): void
    {
        $maildir = $this->maildir();
        $maildir->copyMessage(5, 'subfolder.test');

        static::assertSame([], $this->maildir(['folder' => 'subfolder.test'])->getMessage(2)->getFlags());
    }

    #[Test]
    public function copiesMessageIntoCurrentFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->copyMessage(2, 'INBOX');

        static::assertSame([Flag::Flagged, Flag::Seen], $maildir->getMessage(6)->getFlags());
    }

    #[Test]
    public function refusesToCopyMissingMessage(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no message 9');

        $this->maildir()->copyMessage(9, 'subfolder');
    }

    #[Test]
    public function reportsCopyOfFileThatHasGone(): void
    {
        $maildir = $this->maildir();
        unlink("{$this->directory}/cur/1000000000.P1.example.org:2,S");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read the message file');

        $maildir->copyMessage(1, 'subfolder');
    }

    #[Test]
    public function movesMessage(): void
    {
        $maildir = $this->maildir();
        $maildir->moveMessage(1, 'subfolder.test');

        static::assertSame(2, $this->maildir(['folder' => 'subfolder.test'])->countMessages());
    }

    #[Test]
    public function removesMovedMessageFromCurrentFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->moveMessage(1, 'subfolder.test');

        static::assertSame('A Really Simple Message', $maildir->getMessage(1)->getSubject());
    }

    #[Test]
    public function refusesToMoveIntoCurrentFolder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The target is the current folder');

        $this->maildir()->moveMessage(1, 'INBOX');
    }

    #[Test]
    public function reportsMoveOfFileThatHasGone(): void
    {
        $maildir = $this->maildir();
        unlink("{$this->directory}/cur/1000000000.P1.example.org:2,S");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot move the message file');

        $maildir->moveMessage(1, 'subfolder');
    }

    #[Test]
    public function setsFlags(): void
    {
        $maildir = $this->maildir();
        $maildir->setFlags(1, [Flag::Draft, Flag::Seen]);

        static::assertSame([Flag::Draft, Flag::Seen], $this->maildir()->getMessage(1)->getFlags());
    }

    #[Test]
    public function setsFlagsInTheList(): void
    {
        $maildir = $this->maildir();
        $maildir->setFlags(1, ['\Answered']);

        static::assertSame([Flag::Answered], $maildir->getMessage(1)->getFlags());
    }

    #[Test]
    public function movesMessageFromNewToCurWhenFlagged(): void
    {
        $this->maildir()->setFlags(5, [Flag::Seen]);

        static::assertTrue(is_file("{$this->directory}/cur/1000000004.P1.example.org:2,S"));
    }

    #[Test]
    public function refusesToSetRecent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Recent flag may not be set');

        $this->maildir()->setFlags(1, [Flag::Recent]);
    }

    #[Test]
    public function reportsFlagsOfFileThatHasGone(): void
    {
        $maildir = $this->maildir();
        unlink("{$this->directory}/cur/1000000000.P1.example.org:2,S");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot rename the message file');

        $maildir->setFlags(1, [Flag::Seen]);
    }

    #[Test]
    public function refusesToRenameIntoLinkedCur(): void
    {
        $maildir = $this->maildir();
        mkdir("{$this->root}/elsewhere");
        self::replaceWithLink("{$this->directory}/cur", "{$this->root}/elsewhere");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot rename the message file');

        $maildir->setFlags(5, [Flag::Seen]);
    }

    #[Test]
    public function removesMessage(): void
    {
        $maildir = $this->maildir();
        $maildir->removeMessage(1);

        static::assertSame(4, $this->maildir()->countMessages());
    }

    #[Test]
    public function removesMessageFromTheList(): void
    {
        $maildir = $this->maildir();
        $maildir->removeMessage(1);

        static::assertSame('A Really Simple Message', $maildir->getMessage(1)->getSubject());
    }

    #[Test]
    public function reportsRemovalOfFileThatHasGone(): void
    {
        $maildir = $this->maildir();
        unlink("{$this->directory}/cur/1000000000.P1.example.org:2,S");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove the message');

        $maildir->removeMessage(1);
    }

    #[Test]
    public function createsFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->createFolder('subfolder.test1');

        static::assertTrue($maildir->getFolders('subfolder.test1')->isSelectable());
    }

    #[Test]
    public function createsFolderInParent(): void
    {
        $maildir = $this->maildir();
        $maildir->createFolder('test2', 'INBOX.subfolder');

        static::assertTrue(is_dir("{$this->directory}/.subfolder.test2/cur"));
    }

    #[Test]
    public function createsFolderWithMissingParent(): void
    {
        $maildir = $this->maildir();
        $maildir->createFolder('new.child');

        static::assertFalse($maildir->getFolders('new')->isSelectable());
    }

    /**
     * File permissions: created folders are private to their owner.
     */
    #[Test]
    public function createsFoldersPrivately(): void
    {
        $this->maildir()->createFolder('private');

        static::assertSame(0o700, fileperms("{$this->directory}/.private/tmp") & 0o777);
    }

    #[Test]
    public function refusesFolderThatExists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Folder subfolder already exists');

        $this->maildir()->createFolder('subfolder');
    }

    #[Test]
    public function refusesToCreateInbox(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Folder INBOX already exists');

        $this->maildir()->createFolder('INBOX');
    }

    /**
     * Path traversal: folder names cannot reach outside the maildir.
     */
    #[DataProvider('unsafeFolderNameProvider')]
    #[Test]
    public function refusesUnsafeFolderName(string $name, string $delim): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid folder name');

        $this->maildir(['delim' => $delim])->createFolder($name);
    }

    #[Test]
    public function refusesUnsafeFolderNameWithoutCreatingAnything(): void
    {
        try {
            $this->maildir()->createFolder('../../escape');
        } catch (InvalidArgumentException) {
            static::assertFalse(is_dir("{$this->root}/escape"));

            return;
        }

        static::fail('An unsafe folder name must be refused');
    }

    #[Test]
    public function refusesTooLongFolderName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it is too long');

        $this->maildir()->createFolder(str_repeat('a', times: 255));
    }

    #[Test]
    public function acceptsFolderNameOfTheLengthLimit(): void
    {
        $maildir = $this->maildir();
        $maildir->createFolder(str_repeat('a', times: 254));

        static::assertTrue($maildir->getFolders(str_repeat('a', times: 254))->isSelectable());
    }

    #[Test]
    public function removesFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->removeFolder('subfolder.test');

        static::assertFalse(is_dir("{$this->directory}/.subfolder.test"));
    }

    #[Test]
    public function removesFolderFromTheTree(): void
    {
        $maildir = $this->maildir();
        $maildir->removeFolder($maildir->getFolders('subfolder.test'));

        static::assertTrue($maildir->getFolders('subfolder')->isLeaf());
    }

    #[Test]
    public function removesLinksInFolderWithoutFollowingThem(): void
    {
        file_put_contents("{$this->root}/keep", data: 'keep');
        symlink("{$this->root}/keep", "{$this->directory}/.subfolder.test/cur/link");
        $this->maildir()->removeFolder('subfolder.test');

        static::assertSame('keep', file_get_contents("{$this->root}/keep"));
    }

    #[Test]
    public function refusesToRemoveFolderWithChildren(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Remove the subfolders first');

        $this->maildir()->removeFolder('subfolder');
    }

    #[Test]
    public function refusesToRemoveInbox(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Will not remove INBOX');

        $this->maildir()->removeFolder('INBOX');
    }

    #[Test]
    public function refusesToRemoveSelectedFolder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Will not remove the selected folder');

        $this->maildir(['folder' => 'subfolder.test'])->removeFolder('subfolder.test');
    }

    #[Test]
    public function refusesToRemoveFolderAboveSelectedOne(): void
    {
        $maildir = $this->maildir(['folder' => 'subfolder.test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Will not rename the selected folder');

        $maildir->renameFolder('subfolder', 'other');
    }

    #[Test]
    public function refusesToRemoveFolderHoldingADirectory(): void
    {
        mkdir("{$this->directory}/.subfolder.test/extra");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove');

        $this->maildir()->removeFolder('subfolder.test');
    }

    #[Test]
    public function refusesToRemoveLinkedFolderDirectory(): void
    {
        mkdir("{$this->root}/elsewhere");
        self::replaceWithLink("{$this->directory}/.subfolder.test/cur", "{$this->root}/elsewhere");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not be a symbolic link');

        $this->maildir()->removeFolder('subfolder.test');
    }

    #[Test]
    public function reportsDirectoryThatCannotBeRead(): void
    {
        chmod("{$this->directory}/.subfolder.test/cur", permissions: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove');

        $this->maildir()->removeFolder('subfolder.test');
    }

    #[Test]
    public function reportsFileThatCannotBeRemoved(): void
    {
        chmod("{$this->directory}/.subfolder.test/cur", permissions: 0o500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove');

        $this->maildir()->removeFolder('subfolder.test');
    }

    #[Test]
    public function renamesFolder(): void
    {
        $maildir = $this->maildir();
        $maildir->renameFolder('subfolder.test', 'renamed');

        static::assertSame(1, $this->maildir(['folder' => 'renamed'])->countMessages());
    }

    #[Test]
    public function renamesFolderWithChildren(): void
    {
        $maildir = $this->maildir();
        $maildir->renameFolder($maildir->getFolders('subfolder'), 'moved');

        static::assertSame(1, $this->maildir(['folder' => 'moved.test'])->countMessages());
    }

    #[Test]
    public function renamesFolderWithMissingParent(): void
    {
        mkdir("{$this->directory}/.a.b/cur", permissions: 0o700, recursive: true);
        $maildir = $this->maildir();
        $maildir->renameFolder('a', 'c');

        static::assertTrue($maildir->getFolders('c.b')->isSelectable());
    }

    #[Test]
    public function refusesToRenameToChild(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The new folder cannot be the old folder or one of its children');

        $this->maildir()->renameFolder('subfolder.test', 'subfolder.test.foo');
    }

    #[Test]
    public function refusesToRenameToItself(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The new folder cannot be the old folder or one of its children');

        $this->maildir()->renameFolder('subfolder.test', 'INBOX.subfolder.test');
    }

    #[Test]
    public function refusesToRenameOntoExistingFolder(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Folder subfolder already exists');

        $this->maildir()->renameFolder('subfolder.test', 'subfolder');
    }

    #[Test]
    public function refusesToRenameInbox(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Will not rename INBOX');

        $this->maildir()->renameFolder('INBOX', 'other');
    }

    #[Test]
    public function refusesToRenameToUnsafeName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid folder name');

        $this->maildir()->renameFolder('subfolder.test', '../escape');
    }

    #[Test]
    public function refusesToRenameLinkedFolder(): void
    {
        mkdir("{$this->root}/elsewhere/cur", permissions: 0o700, recursive: true);
        mkdir("{$this->directory}/.linked");
        $maildir = $this->maildir();
        self::replaceWithLink("{$this->directory}/.subfolder.test", "{$this->root}/elsewhere");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot move subfolder.test');

        $maildir->renameFolder('subfolder.test', 'renamed');
    }

    #[Test]
    public function initializesMaildir(): void
    {
        Maildir::initMaildir("{$this->root}/new");

        static::assertSame([true, true, true], [
            is_dir("{$this->root}/new/cur"),
            is_dir("{$this->root}/new/new"),
            is_dir("{$this->root}/new/tmp"),
        ]);
    }

    #[Test]
    public function initializesMaildirPrivately(): void
    {
        Maildir::initMaildir("{$this->root}/new");

        static::assertSame(0o700, fileperms("{$this->root}/new") & 0o777);
    }

    #[Test]
    public function leavesExistingMaildirAlone(): void
    {
        Maildir::initMaildir($this->directory);

        static::assertSame(5, $this->maildir()->countMessages());
    }

    #[Test]
    public function takesConfigObject(): void
    {
        static::assertSame(5, (new Maildir(new MaildirConfig($this->directory)))->countMessages());
    }

    #[Test]
    public function removesTemporaryFileWhenComposingFails(): void
    {
        $message = (new ComposedMessage())->embed(new MimePart('x', id: 'logo'));
        try {
            $this->maildir()->appendMessage($message);
        } catch (MimeException) {
            static::assertSame([], $this->files("{$this->directory}/tmp"));

            return;
        }

        static::fail('A message that cannot be written must be refused');
    }

    #[Test]
    public function createsMaildirWhenAsked(): void
    {
        static::assertSame(0, (new Maildir(['dirname' => "{$this->root}/created", 'create' => true]))->countMessages());
    }

    #[Test]
    public function refusesToInitializeOverFile(): void
    {
        touch("{$this->root}/file");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The maildir must be a directory, not a file or a symbolic link');

        Maildir::initMaildir("{$this->root}/file");
    }

    #[Test]
    public function refusesToInitializeOverLink(): void
    {
        mkdir("{$this->root}/elsewhere");
        symlink("{$this->root}/elsewhere", "{$this->root}/link");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The maildir must be a directory, not a file or a symbolic link');

        Maildir::initMaildir("{$this->root}/link");
    }

    #[Test]
    public function refusesToInitializeWithoutParent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The parent of the maildir does not exist');

        Maildir::initMaildir("{$this->root}/missing/maildir");
    }

    #[Test]
    public function refusesToInitializeOverLinkedSubdirectory(): void
    {
        mkdir("{$this->root}/new");
        mkdir("{$this->root}/elsewhere");
        symlink("{$this->root}/elsewhere", "{$this->root}/new/cur");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may not be a symbolic link');

        Maildir::initMaildir("{$this->root}/new");
    }

    #[Test]
    public function reportsDirectoryThatCannotBeCreated(): void
    {
        mkdir("{$this->root}/locked", permissions: 0o500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create');

        Maildir::initMaildir("{$this->root}/locked/maildir");
    }

    #[Test]
    public function refusesLocalPathThatIsNotLocal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dirname must be a local file system path');

        Maildir::initMaildir('phar:///x');
    }

    #[Test]
    public function hasQuotaChecksOffByDefault(): void
    {
        static::assertFalse($this->maildir()->getQuota());
    }

    #[Test]
    public function keepsQuotaSetting(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(['size' => 100, 'count' => 2]);

        static::assertSame(['size' => 100, 'count' => 2], $maildir->getQuota());
    }

    #[Test]
    public function readsQuotaFromMaildirsize(): void
    {
        static::assertSame(['count' => 10, 'size' => 3000], $this->maildir()->getQuota(fromStorage: true));
    }

    #[Test]
    public function refusesMissingMaildirsize(): void
    {
        unlink("{$this->directory}/maildirsize");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read maildirsize');

        $this->maildir()->getQuota(fromStorage: true);
    }

    #[Test]
    public function checksQuotaFromMaildirsize(): void
    {
        static::assertFalse($this->maildir()->checkQuota());
    }

    #[Test]
    public function reportsUsage(): void
    {
        file_put_contents("{$this->directory}/maildirsize", data: "10000S,1000C\n5000 100\n");
        $usage = $this->maildir()->checkQuota(detailedResponse: true);

        static::assertSame([5000, 100], [$usage['size'] ?? null, $usage['count'] ?? null]);
    }

    #[Test]
    public function recalculatesWhenMaildirsizeSaysOverQuota(): void
    {
        $usage = $this->maildir()->checkQuota(detailedResponse: true);

        static::assertSame(2539, $usage['size'] ?? null);
    }

    #[Test]
    public function recalculatesWhenForced(): void
    {
        $usage = $this->maildir()->checkQuota(
            detailedResponse: true,
            forceRecalc: true,
        );

        static::assertSame([2539, 6], [$usage['size'] ?? null, $usage['count'] ?? null]);
    }

    #[Test]
    public function writesRecalculatedMaildirsize(): void
    {
        $this->maildir()->checkQuota(forceRecalc: true);

        static::assertSame("3000S,10C\n2539 6\n", file_get_contents("{$this->directory}/maildirsize"));
    }

    #[Test]
    public function writesMaildirsizePrivately(): void
    {
        $this->maildir()->checkQuota(forceRecalc: true);
        clearstatcache();

        static::assertSame(0o600, fileperms("{$this->directory}/maildirsize") & 0o777);
    }

    #[Test]
    public function checksAgainstQuotaSetting(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(['count' => 2]);

        static::assertTrue($maildir->checkQuota());
    }

    #[Test]
    public function refusesToStoreWhenOverQuota(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(['count' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The storage is over quota');

        $maildir->appendMessage(self::MESSAGE);
    }

    #[Test]
    public function refusesToCopyWhenOverQuota(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(['count' => 2]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The storage is over quota');

        $maildir->copyMessage(1, 'subfolder');
    }

    #[Test]
    public function storesWhenUnderQuota(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(true);
        $maildir->appendMessage(self::MESSAGE);

        static::assertSame(6, $maildir->countMessages());
    }

    #[Test]
    public function addsQuotaEntryWhenStoring(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(['size' => 100_000, 'count' => 100]);
        $maildir->checkQuota(forceRecalc: true);
        $maildir->appendMessage(self::MESSAGE);

        static::assertSame("100000S,100C\n2539 6\n83 1\n", file_get_contents("{$this->directory}/maildirsize"));
    }

    #[Test]
    public function addsQuotaEntryWhenRemoving(): void
    {
        $maildir = $this->maildir();
        $maildir->setQuota(['size' => 100_000, 'count' => 100]);
        $maildir->checkQuota(forceRecalc: true);
        $maildir->removeMessage(2);

        static::assertSame("100000S,100C\n2539 6\n-89 -1\n", file_get_contents("{$this->directory}/maildirsize"));
    }

    #[Test]
    public function addsNoQuotaEntryWithChecksOff(): void
    {
        $this->maildir()->removeMessage(2);

        static::assertSame("10C,1L,3000S\n5000 100", file_get_contents("{$this->directory}/maildirsize"));
    }

    #[Test]
    public function refusesToCheckWithoutAnyQuota(): void
    {
        unlink("{$this->directory}/maildirsize");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No quota is set or defined in maildirsize');

        $this->maildir()->checkQuota();
    }

    /**
     * Symlink attacks: a linked maildirsize is neither read nor appended to.
     */
    #[Test]
    public function ignoresLinkedMaildirsize(): void
    {
        file_put_contents("{$this->root}/target", data: "1S\n");
        unlink("{$this->directory}/maildirsize");
        symlink("{$this->root}/target", "{$this->directory}/maildirsize");
        $maildir = $this->maildir();
        $maildir->setQuota(['size' => 100_000]);
        $maildir->appendMessage(self::MESSAGE);

        static::assertSame("1S\n", file_get_contents("{$this->root}/target"));
    }

    /**
     * Resource exhaustion: an oversized maildirsize is recalculated, not read.
     */
    #[Test]
    public function recalculatesOversizedMaildirsize(): void
    {
        file_put_contents("{$this->directory}/maildirsize", data: "3000S\n" . str_repeat("1 1\n", times: 2000));
        $maildir = $this->maildir();
        $maildir->setQuota(['size' => 3000]);

        static::assertSame(6, $maildir->checkQuota(detailedResponse: true)['count'] ?? null);
    }

    #[Test]
    public function hasCreateAndDelete(): void
    {
        $capabilities = $this->maildir()->getCapabilities();

        static::assertSame([true, true], [$capabilities['create'], $capabilities['delete']]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function messageProvider(): array
    {
        return [
            'string'         => [self::MESSAGE],
            'stream'         => [static function () {
                $stream = fopen('php://temp', mode: 'w+b');
                fwrite($stream, data: self::MESSAGE);
                rewind($stream);

                return $stream;
            }],
            'stored message' => [static fn(): Message => Message::fromString(self::MESSAGE)],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function linkedDirectoryProvider(): array
    {
        return [
            'tmp'    => ['.subfolder.test/tmp'],
            'cur'    => ['.subfolder.test/cur'],
            'folder' => ['.subfolder.test'],
        ];
    }

    /**
     * @return array<string, array{Flag|string, string}>
     */
    public static function unstorableFlagProvider(): array
    {
        return [
            'recent'            => [Flag::Recent, 'The Recent flag may not be set'],
            'recent by name'    => ['\Recent', 'The Recent flag may not be set'],
            'imap keyword'      => ['$Junk', 'Unknown flag $Junk'],
            'upper case letter' => ['X', 'Unknown flag X'],
            'two letters'       => ['ab', 'Unknown flag ab'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeFolderNameProvider(): array
    {
        return [
            'parent path'  => ['../../escape', '.'],
            'slash'        => ['a/b', '.'],
            'backslash'    => ['a\\b', '.'],
            'NUL'          => ["a\0b", '.'],
            'line break'   => ["a\nb", '.'],
            'empty part'   => ['a..b', '.'],
            'dot part'     => ['a:.', ':'],
            'dot dot part' => ['a:..', ':'],
            'only dot dot' => ['..', ':'],
            'DEL'          => ["a\x7Fb", '.'],
        ];
    }

    private static function replaceWithLink(string $path, string $target): void
    {
        self::remove($path);
        symlink($target, $path);
    }

    private static function remove(string $path): void
    {
        if (! is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (! ('.' !== $entry && '..' !== $entry)) {
                continue;
            }

            self::remove("{$path}/{$entry}");
        }

        rmdir($path);
    }
}
