<?php

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayObject;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Tests\Trait\ExtractsMaildirFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_reverse;
use function chmod;
use function clearstatcache;
use function closedir;
use function copy;
use function file_exists;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;
use function opendir;
use function readdir;
use function rmdir;
use function stat;
use function strtoupper;
use function substr;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const PHP_OS;

class MaildirFolderTest extends TestCase
{
    use ExtractsMaildirFixtureTrait;

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
                $this->tmpdir = __DIR__ . '/../_files/test.tmp/';
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
            if ($count != 2) {
                $this->markTestSkipped('Are you sure your tmp dir is a valid empty dir?');
                return;
            }
        }

        $this->extractMaildirFixture($originalMaildir);

        $this->params            = [];
        $this->params['dirname'] = $this->tmpdir;

        foreach ($this->subdirs as $dir) {
            if ($dir != '.') {
                mkdir($this->tmpdir . $dir);
            }
            foreach (['cur', 'new'] as $subdir) {
                if (! file_exists($originalMaildir . $dir . '/' . $subdir)) {
                    continue;
                }
                mkdir($this->tmpdir . $dir . '/' . $subdir);
                $dh = opendir($originalMaildir . $dir . '/' . $subdir);
                while (($entry = readdir($dh)) !== false) {
                    $entry = $dir . '/' . $subdir . '/' . $entry;
                    if (! is_file($originalMaildir . $entry)) {
                        continue;
                    }
                    copy($originalMaildir . $entry, $this->tmpdir . $entry);
                }
                closedir($dh);
            }
        }
    }

    public function tearDown(): void
    {
        chmod($this->tmpdir, 0700);
        foreach (array_reverse($this->subdirs) as $dir) {
            foreach (['cur', 'new'] as $subdir) {
                if (! file_exists($this->tmpdir . $dir . '/' . $subdir)) {
                    continue;
                }
                if (! is_dir($this->tmpdir . $dir . '/' . $subdir)) {
                    continue;
                }
                $dh = opendir($this->tmpdir . $dir . '/' . $subdir);
                while (($entry = readdir($dh)) !== false) {
                    $entry = $this->tmpdir . $dir . '/' . $subdir . '/' . $entry;
                    if (! is_file($entry)) {
                        continue;
                    }
                    unlink($entry);
                }
                closedir($dh);
                rmdir($this->tmpdir . $dir . '/' . $subdir);
            }
            if ($dir != '.' && is_dir($this->tmpdir . $dir)) {
                rmdir($this->tmpdir . $dir);
            }
        }
    }

    #[Test]
    public function loadOk(): void
    {
        $mail = new Folder\Maildir($this->params);
        static::assertSame(Folder\Maildir::class, $mail::class);
    }

    #[Test]
    public function loadConfig(): void
    {
        $mail = new Folder\Maildir(new ArrayObject($this->params));
        static::assertSame(Folder\Maildir::class, $mail::class);
    }

    #[Test]
    public function noParams(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no dirname provided');
        new Folder\Maildir([]);
    }

    #[Test]
    public function loadFailure(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a directory');
        new Folder\Maildir(['dirname' => 'This/Folder/Does/Not/Exist']);
    }

    #[Test]
    public function loadUnkownFolder(): void
    {
        $this->params['folder'] = 'UnknownFolder';
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no subfolder named UnknownFolder');
        new Folder\Maildir($this->params);
    }

    #[Test]
    public function changeFolder(): void
    {
        $mail = new Folder\Maildir($this->params);

        $mail->selectFolder('subfolder.test');

        static::assertSame($mail->getCurrentFolder(), 'subfolder.test');
    }

    #[Test]
    public function unknownFolder(): void
    {
        $mail = new Folder\Maildir($this->params);

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('no subfolder named /Unknown/Folder/');
        $mail->selectFolder('/Unknown/Folder/');
    }

    #[Test]
    public function globalName(): void
    {
        $mail = new Folder\Maildir($this->params);

        static::assertSame($mail->getFolders()->subfolder->__toString(), 'subfolder');
    }

    #[Test]
    public function localName(): void
    {
        $mail = new Folder\Maildir($this->params);

        static::assertSame($mail->getFolders()->subfolder->key(), 'test');
    }

    #[Test]
    public function iterator(): void
    {
        $mail     = new Folder\Maildir($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);
        // we search for this folder because we can't assume an order while iterating
        $searchFolders = [
            'subfolder'      => 'subfolder',
            'subfolder.test' => 'test',
            'INBOX'          => 'INBOX',
        ];
        $foundFolders = [];

        foreach ($iterator as $localName => $folder) {
            if (! isset($searchFolders[$folder->getGlobalName()])) {
                continue;
            }

            // explicit call of __toString() needed for PHP < 5.2
            $foundFolders[$folder->__toString()] = $localName;
        }

        static::assertEquals($searchFolders, $foundFolders);
    }

    #[Test]
    public function keyLocalName(): void
    {
        $mail     = new Folder\Maildir($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);
        // we search for this folder because we can't assume an order while iterating
        $searchFolders = [
            'subfolder'      => 'subfolder',
            'subfolder.test' => 'test',
            'INBOX'          => 'INBOX',
        ];
        $foundFolders = [];

        foreach ($iterator as $localName => $folder) {
            if (! isset($searchFolders[$folder->getGlobalName()])) {
                continue;
            }

            // explicit call of __toString() needed for PHP < 5.2
            $foundFolders[$folder->__toString()] = $localName;
        }

        static::assertEquals($searchFolders, $foundFolders);
    }

    #[Test]
    public function inboxEquals(): void
    {
        $mail     = new Folder\Maildir($this->params);
        $iterator = new RecursiveIteratorIterator(
            $mail->getFolders('INBOX.subfolder'),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        // we search for this folder because we can't assume an order while iterating
        $searchFolders = ['subfolder.test' => 'test'];
        $foundFolders  = [];

        foreach ($iterator as $localName => $folder) {
            if (! isset($searchFolders[$folder->getGlobalName()])) {
                continue;
            }

            // explicit call of __toString() needed for PHP < 5.2
            $foundFolders[$folder->__toString()] = $localName;
        }

        static::assertSame($searchFolders, $foundFolders);
    }

    #[Test]
    public function selectable(): void
    {
        $mail     = new Folder\Maildir($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $localName => $folder) {
            static::assertSame($localName, $folder->getLocalName());
        }
    }

    #[Test]
    public function countsMessages(): void
    {
        $mail = new Folder\Maildir($this->params);

        $count = $mail->countMessages();
        static::assertSame(5, $count);

        $mail->selectFolder('subfolder.test');
        $count = $mail->countMessages();
        static::assertSame(1, $count);
    }

    #[Test]
    public function reportsMessageSizes(): void
    {
        $mail        = new Folder\Maildir($this->params);
        $shouldSizes = [1 => 397, 89, 694, 452, 497];

        $sizes = $mail->getSize();
        static::assertSame($shouldSizes, $sizes);

        $mail->selectFolder('subfolder.test');
        $sizes = $mail->getSize();
        static::assertSame([1 => 410], $sizes);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new Folder\Maildir($this->params);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);

        $mail->selectFolder('subfolder.test');
        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Message in subfolder', $subject);
    }

    #[Test]
    public function notReadableFolder(): void
    {
        $stat = stat($this->params['dirname'] . '.subfolder');
        chmod($this->params['dirname'] . '.subfolder', 0);
        clearstatcache();
        $statcheck = stat($this->params['dirname'] . '.subfolder');
        if (($statcheck['mode'] % (8 * 8 * 8)) !== 0) {
            chmod($this->params['dirname'] . '.subfolder', $stat['mode']);
            static::markTestSkipped(
                'cannot remove read rights, which makes this test useless (maybe you are using Windows?)',
            );
            return;
        }

        try {
            $this->expectException(Exception\RuntimeException::class);
            $this->expectExceptionMessage('error while reading maildir');
            new Folder\Maildir($this->params);
        } finally {
            chmod($this->params['dirname'] . '.subfolder', $stat['mode']);
        }
    }

    #[Test]
    public function notReadableMaildir(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::markTestSkipped('File permissions are not enforced for the root user');
        }

        chmod($this->params['dirname'], 0);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('can\'t read folders in maildir');
        new Folder\Maildir($this->params);
    }

    #[Test]
    public function getInvalidFolder(): void
    {
        $mail         = new Folder\Maildir($this->params);
        $root         = $mail->getFolders();
        $root->foobar = new Folder('foobar', DIRECTORY_SEPARATOR . 'foobar');

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('folder foobar not found');
        $mail->selectFolder('foobar');
    }

    #[Test]
    public function getVanishedFolder(): void
    {
        $mail         = new Folder\Maildir($this->params);
        $root         = $mail->getFolders();
        $root->foobar = new Folder('foobar', 'foobar');

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('seems like the maildir has vanished');
        $mail->selectFolder('foobar');
    }

    #[Test]
    public function getNotSelectableFolder(): void
    {
        $mail         = new Folder\Maildir($this->params);
        $root         = $mail->getFolders();
        $root->foobar = new Folder('foobar', 'foobar', false);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('foobar is not selectable');
        $mail->selectFolder('foobar');
    }

    #[Test]
    public function withAdditionalFolder(): void
    {
        mkdir($this->params['dirname'] . '.xyyx');
        mkdir($this->params['dirname'] . '.xyyx/cur');
        mkdir($this->params['dirname'] . '.xyyx/new');

        $mail = new Folder\Maildir($this->params);
        $mail->selectFolder('xyyx');
        static::assertSame($mail->countMessages(), 0);

        rmdir($this->params['dirname'] . '.xyyx/cur');
        rmdir($this->params['dirname'] . '.xyyx/new');
        rmdir($this->params['dirname'] . '.xyyx');
    }
}
