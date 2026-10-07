<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Writable;
use Contenir\Mail\Tests\Trait\ExtractsMaildirFixtureTrait;
use Contenir\Mail\Tests\Trait\UsesProcessTempDirTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_reverse;
use function closedir;
use function copy;
use function fclose;
use function file_exists;
use function fopen;
use function fseek;
use function fwrite;
use function getenv;
use function is_file;
use function mkdir;
use function opendir;
use function readdir;
use function rmdir;
use function strtoupper;
use function substr;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_OS;

class MaildirWritableTest extends TestCase
{
    use ExtractsMaildirFixtureTrait;
    use UsesProcessTempDirTrait;

    /** @var array */
    protected $params;
    /** @var string */
    protected $tmpdir;
    /** @var string[] */
    protected $subdirs = ['.', '.subfolder', '.subfolder.test'];

    public function setUp(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') {
            $this->markTestSkipped('This test does not work on Windows');
            return;
        }

        $originalMaildir = __DIR__ . '/../_files/test.maildir/';

        if (! isset($this->tmpdir)) {
            if (getenv('TESTS_CONTENIR_MAIL_TEMPDIR') != null) {
                $this->tmpdir = getenv('TESTS_CONTENIR_MAIL_TEMPDIR');
            } else {
                $this->tmpdir = self::processTempDir();
            }
            if (! file_exists($this->tmpdir)) {
                mkdir($this->tmpdir);
            }
            $count = 0;
            $dh    = opendir($this->tmpdir);
            while (readdir($dh) !== false) {
                ++$count;
            }
            closedir($dh);

            if (2 != $count) {
                $this->markTestSkipped('Are you sure your tmp dir is a valid empty dir?');
                return;
            }
        }

        $this->extractMaildirFixture($originalMaildir);

        $this->params            = [];
        $this->params['dirname'] = $this->tmpdir;

        foreach ($this->subdirs as $dir) {
            if ('.' != $dir) {
                mkdir($this->tmpdir . $dir);
            }
            foreach (['cur', 'new'] as $subdir) {
                if (! file_exists("{$originalMaildir}{$dir}/{$subdir}")) {
                    continue;
                }
                mkdir("{$this->tmpdir}{$dir}/{$subdir}");
                $dh = opendir("{$originalMaildir}{$dir}/{$subdir}");
                while (($entry = readdir($dh)) !== false) {
                    $entry = "{$dir}/{$subdir}/{$entry}";
                    if (! is_file($originalMaildir . $entry)) {
                        continue;
                    }
                    copy($originalMaildir . $entry, $this->tmpdir . $entry);
                }
                closedir($dh);
            }
            copy("{$originalMaildir}maildirsize", "{$this->tmpdir}maildirsize");
        }
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->subdirs) as $dir) {
            if (! file_exists($this->tmpdir . $dir)) {
                continue;
            }
            foreach (['cur', 'new', 'tmp'] as $subdir) {
                if (! file_exists("{$this->tmpdir}{$dir}/{$subdir}")) {
                    continue;
                }
                $dh = opendir("{$this->tmpdir}{$dir}/{$subdir}");
                while (($entry = readdir($dh)) !== false) {
                    $entry = "{$this->tmpdir}{$dir}/{$subdir}/{$entry}";
                    if (! is_file($entry)) {
                        continue;
                    }
                    unlink($entry);
                }
                closedir($dh);
                rmdir("{$this->tmpdir}{$dir}/{$subdir}");
            }
            if ('.' != $dir) {
                rmdir($this->tmpdir . $dir);
            }
        }
        @unlink("{$this->tmpdir}maildirsize");
    }

    #[Test]
    public function createFolder(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->createFolder('subfolder.test1');
        $mail->createFolder('test2', 'INBOX.subfolder');
        $mail->createFolder('test3', $mail->getFolders()->subfolder);
        $mail->createFolder('foo.bar');

        $this->subdirs[] = '.subfolder.test1';
        $this->subdirs[] = '.subfolder.test2';
        $this->subdirs[] = '.subfolder.test3';
        $this->subdirs[] = '.foo';
        $this->subdirs[] = '.foo.bar';

        $selected = [];
        foreach (['subfolder.test1', 'subfolder.test2', 'subfolder.test3', 'foo.bar'] as $globalName) {
            $mail->selectFolder($mail->getFolders($globalName));
            $selected[] = (string) $mail->getCurrentFolder();
        }

        static::assertSame(['subfolder.test1', 'subfolder.test2', 'subfolder.test3', 'foo.bar'], $selected);
    }

    #[Test]
    public function createFolderEmptyPart(): void
    {
        $mail = new Writable\Maildir($this->params);
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('invalid name - folder parts may not be empty');
        $mail->createFolder('foo..bar');
    }

    #[Test]
    public function createFolderSlash(): void
    {
        $mail = new Writable\Maildir($this->params);
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('invalid name - no directory separator allowed in folder name');
        $mail->createFolder('foo/bar');
    }

    #[Test]
    public function createFolderDirectorySeparator(): void
    {
        $mail = new Writable\Maildir($this->params);
        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('invalid name - no directory separator allowed in folder name');
        $mail->createFolder('foo' . DIRECTORY_SEPARATOR . 'bar');
    }

    #[Test]
    public function createFolderExistingDir(): void
    {
        $mail = new Writable\Maildir($this->params);
        unset($mail->getFolders()->subfolder->test);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('error while creating new folder, may be created incompletely');
        $mail->createFolder('subfolder.test');
    }

    #[Test]
    public function createExistingFolder(): void
    {
        $mail = new Writable\Maildir($this->params);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('folder already exists');
        $mail->createFolder('subfolder.test');
    }

    #[Test]
    public function removeFolderName(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->removeFolder('INBOX.subfolder.test');

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no subfolder named test');
        $mail->selectFolder($mail->getFolders()->subfolder->test);
    }

    #[Test]
    public function removeFolderInstance(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->removeFolder($mail->getFolders()->subfolder->test);

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no subfolder named test');
        $mail->selectFolder($mail->getFolders()->subfolder->test);
    }

    #[Test]
    public function removeFolderWithChildren(): void
    {
        $mail = new Writable\Maildir($this->params);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('delete children first');
        $mail->removeFolder($mail->getFolders()->subfolder);
    }

    #[Test]
    public function removeSelectedFolder(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->selectFolder('subfolder.test');

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('wont delete selected folder');
        $mail->removeFolder('subfolder.test');
    }

    #[Test]
    public function removeInvalidFolder(): void
    {
        $mail = new Writable\Maildir($this->params);

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no subfolder named thisFolderDoestNotExist');
        $mail->removeFolder('thisFolderDoestNotExist');
    }

    #[Test]
    public function renameFolder(): void
    {
        $mail = new Writable\Maildir($this->params);

        $mail->renameFolder('INBOX.subfolder', 'INBOX.foo');
        $mail->renameFolder($mail->getFolders()->foo, 'subfolder');

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('wont rename INBOX');
        $mail->renameFolder('INBOX', 'foo');
    }

    #[Test]
    public function renameSelectedFolder(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->selectFolder('subfolder.test');

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('wont rename selected folder');
        $mail->renameFolder('subfolder.test', 'foo');
    }

    #[Test]
    public function renameToChild(): void
    {
        $mail = new Writable\Maildir($this->params);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('new folder cannot be a child of old folder');
        $mail->renameFolder('subfolder.test', 'subfolder.test.foo');
    }

    #[Test]
    public function append(): void
    {
        $mail  = new Writable\Maildir($this->params);
        $count = $mail->countMessages();

        $message = '';
        $message .= "From: me@example.org\r\n";
        $message .= "To: you@example.org\r\n";
        $message .= "Subject: append test\r\n";
        $message .= "\r\n";
        $message .= "This is a test\r\n";
        $mail->appendMessage($message);

        static::assertSame($count + 1, $mail->countMessages());
        static::assertSame($mail->getMessage($count + 1)->subject, 'append test');
    }

    #[Test]
    public function copy(): void
    {
        $mail = new Writable\Maildir($this->params);

        $mail->selectFolder('subfolder.test');
        $count = $mail->countMessages();
        $mail->selectFolder('INBOX');
        $message = $mail->getMessage(1);

        $mail->copyMessage(1, 'subfolder.test');
        $mail->selectFolder('subfolder.test');
        static::assertSame($count + 1, $mail->countMessages());
        static::assertSame($mail->getMessage($count + 1)->subject, $message->subject);
        static::assertSame($mail->getMessage($count + 1)->from, $message->from);
        static::assertSame($mail->getMessage($count + 1)->to, $message->to);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->copyMessage(1, 'justARandomFolder');
    }

    #[Test]
    public function setFlags(): void
    {
        $mail = new Writable\Maildir($this->params);

        $mail->setFlags(1, [Storage::FLAG_SEEN]);
        $message = $mail->getMessage(1);
        static::assertTrue($message->hasFlag(Storage::FLAG_SEEN));
        static::assertFalse($message->hasFlag(Storage::FLAG_FLAGGED));

        $mail->setFlags(1, [Storage::FLAG_SEEN, Storage::FLAG_FLAGGED]);
        $message = $mail->getMessage(1);
        static::assertTrue($message->hasFlag(Storage::FLAG_SEEN));
        static::assertTrue($message->hasFlag(Storage::FLAG_FLAGGED));

        $mail->setFlags(1, [Storage::FLAG_FLAGGED]);
        $message = $mail->getMessage(1);
        static::assertFalse($message->hasFlag(Storage::FLAG_SEEN));
        static::assertTrue($message->hasFlag(Storage::FLAG_FLAGGED));

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('recent flag may not be set');
        $mail->setFlags(1, [Storage::FLAG_RECENT]);
    }

    #[Test]
    public function setFlagsRemovedFile(): void
    {
        $mail = new Writable\Maildir($this->params);
        unlink("{$this->params['dirname']}cur/1000000000.P1.example.org:2,S");

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('cannot rename file');
        $mail->setFlags(1, [Storage::FLAG_FLAGGED]);
    }

    #[Test]
    public function remove(): void
    {
        $mail  = new Writable\Maildir($this->params);
        $count = $mail->countMessages();

        $mail->removeMessage(1);
        static::assertSame($mail->countMessages(), --$count);

        unset($mail[2]);
        static::assertSame($mail->countMessages(), --$count);
    }

    #[Test]
    public function removeRemovedFile(): void
    {
        $mail = new Writable\Maildir($this->params);
        unlink("{$this->params['dirname']}cur/1000000000.P1.example.org:2,S");

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('cannot remove message');
        $mail->removeMessage(1);
    }

    #[Test]
    public function checkQuota(): void
    {
        $mail = new Writable\Maildir($this->params);
        static::assertFalse($mail->checkQuota());
    }

    #[Test]
    public function checkQuotaDetailed(): void
    {
        $mail        = new Writable\Maildir($this->params);
        $quotaResult = [
            'size'       => 2539,
            'count'      => 6,
            'quota'      => [
                'count' => 10,
                'L'     => 1,
                'size'  => 3000,
            ],
            'over_quota' => false,
        ];
        static::assertEquals($quotaResult, $mail->checkQuota(true));
    }

    #[Test]
    public function setQuota(): void
    {
        $mail = new Writable\Maildir($this->params);
        static::assertNull($mail->getQuota());

        $mail->setQuota(true);
        static::assertTrue($mail->getQuota());

        $mail->setQuota(false);
        static::assertFalse($mail->getQuota());

        $mail->setQuota(['size' => 100, 'count' => 2, 'X' => 0]);
        static::assertSame($mail->getQuota(), ['size' => 100, 'count' => 2, 'X' => 0]);
        static::assertEquals($mail->getQuota(true), ['size' => 3000, 'L' => 1, 'count' => 10]);

        $quotaResult = [
            'size'       => 2539,
            'count'      => 6,
            'quota'      => [
                'size'  => 100,
                'count' => 2,
                'X'     => 0,
            ],
            'over_quota' => true,
        ];
        static::assertSame($quotaResult, $mail->checkQuota(true, true));
        static::assertEquals(['size' => 100, 'count' => 2, 'X' => 0], $mail->getQuota(true));
    }

    #[Test]
    public function missingMaildirsize(): void
    {
        $mail = new Writable\Maildir($this->params);
        static::assertEquals($mail->getQuota(true), ['size' => 3000, 'L' => 1, 'count' => 10]);

        unlink("{$this->tmpdir}maildirsize");

        static::assertNull($mail->getQuota());

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('cannot open maildirsize');
        $mail->getQuota(true);
    }

    #[Test]
    public function missingMaildirsizeWithFixedQuota(): void
    {
        $mail = new Writable\Maildir($this->params);
        unlink("{$this->tmpdir}maildirsize");
        $mail->setQuota(['size' => 100, 'count' => 2, 'X' => 0]);

        $quotaResult = [
            'size'       => 2539,
            'count'      => 6,
            'quota'      => [
                'size'  => 100,
                'count' => 2,
                'X'     => 0,
            ],
            'over_quota' => true,
        ];
        static::assertSame($quotaResult, $mail->checkQuota(true));

        static::assertEquals($quotaResult['quota'], $mail->getQuota(true));
    }

    #[Test]
    public function appendMessage(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->setQuota(['size' => 3000, 'count' => 6, 'X' => 0]);
        static::assertFalse($mail->checkQuota(false, true));
        $mail->appendMessage("Subject: test\r\n\r\n");
        $quotaResult = [
            'size'       => 2556,
            'count'      => 7,
            'quota'      => [
                'size'  => 3000,
                'count' => 6,
                'X'     => 0,
            ],
            'over_quota' => true,
        ];
        static::assertSame($quotaResult, $mail->checkQuota(true));

        $mail->setQuota(false);
        static::assertTrue($mail->checkQuota());

        $mail->appendMessage("Subject: test\r\n\r\n");

        $mail->setQuota(true);
        static::assertTrue($mail->checkQuota());

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('storage is over quota!');
        $mail->appendMessage("Subject: test\r\n\r\n");
    }

    #[Test]
    public function removeMessage(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->setQuota(['size' => 3000, 'count' => 5, 'X' => 0]);
        static::assertTrue($mail->checkQuota(false, true));

        $mail->removeMessage(1);
        static::assertFalse($mail->checkQuota());
    }

    #[Test]
    public function copyMessage(): void
    {
        $mail = new Writable\Maildir($this->params);
        $mail->setQuota(['size' => 3000, 'count' => 6, 'X' => 0]);
        static::assertFalse($mail->checkQuota(false, true));
        $mail->copyMessage(1, 'subfolder');
        $quotaResult = [
            'size'       => 2936,
            'count'      => 7,
            'quota'      => [
                'size'  => 3000,
                'count' => 6,
                'X'     => 0,
            ],
            'over_quota' => true,
        ];
        static::assertSame($quotaResult, $mail->checkQuota(true));
    }

    #[Test]
    public function appendStream(): void
    {
        $mail = new Writable\Maildir($this->params);
        $fh   = fopen('php://memory', 'rw');
        fwrite($fh, "Subject: test\r\n\r\n");
        fseek($fh, 0);
        $mail->appendMessage($fh);
        fclose($fh);

        static::assertSame($mail->getMessage($mail->countMessages())->subject, 'test');
    }

    #[Test]
    public function move(): void
    {
        $mail   = new Writable\Maildir($this->params);
        $target = $mail->getFolders()->subfolder->test;
        $mail->selectFolder($target);
        $toCount = $mail->countMessages();
        $mail->selectFolder('INBOX');
        $fromCount = $mail->countMessages();
        $mail->moveMessage(1, $target);

        static::assertSame($fromCount - 1, $mail->countMessages());
        $mail->selectFolder($target);
        static::assertSame($toCount + 1, $mail->countMessages());
    }

    #[Test]
    public function initExisting(): void
    {
        // this should be a noop
        Writable\Maildir::initMaildir($this->params['dirname']);
        $mail = new Writable\Maildir($this->params);
        static::assertSame($mail->countMessages(), 5);
    }

    #[Test]
    public function init(): void
    {
        $this->tearDown();

        // should fail now
        $e = null;
        try {
            $mail = new Writable\Maildir($this->params);
            static::fail('empty maildir should not be accepted');
        } catch (\Exception) {
        }

        Writable\Maildir::initMaildir($this->params['dirname']);
        $mail = new Writable\Maildir($this->params);
        static::assertSame($mail->countMessages(), 0);
    }

    #[Test]
    public function create(): void
    {
        $this->tearDown();

        // should fail now
        $e = null;
        try {
            $mail = new Writable\Maildir($this->params);
            static::fail('empty maildir should not be accepted');
        } catch (\Exception) {
        }

        $this->params['create'] = true;
        $mail                   = new Writable\Maildir($this->params);
        static::assertSame($mail->countMessages(), 0);
    }
}
