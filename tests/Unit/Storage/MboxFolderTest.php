<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayObject;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Tests\Trait\UsesProcessTempDirTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_reverse;
use function chmod;
use function clearstatcache;
use function closedir;
use function copy;
use function file_exists;
use function function_exists;
use function getenv;
use function is_file;
use function mkdir;
use function opendir;
use function posix_getuid;
use function readdir;
use function rmdir;
use function serialize;
use function stat;
use function touch;
use function unlink;
use function unserialize;

use const DIRECTORY_SEPARATOR;

class MboxFolderTest extends TestCase
{
    use UsesProcessTempDirTrait;

    /** @var array */
    protected $params;
    /** @var string */
    protected $originalDir;
    /** @var string */
    protected $tmpdir;
    /** @var string[] */
    protected $subdirs = ['.', 'subfolder'];

    public function setUp(): void
    {
        $this->originalDir = __DIR__ . '/../_files/test.mbox/';

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

        $this->params            = [];
        $this->params['dirname'] = $this->tmpdir;
        $this->params['folder']  = 'INBOX';

        foreach ($this->subdirs as $dir) {
            if ('.' != $dir) {
                mkdir($this->tmpdir . $dir);
            }
            $dh = opendir($this->originalDir . $dir);
            while (($entry = readdir($dh)) !== false) {
                $entry = "{$dir}/{$entry}";
                if (! is_file($this->originalDir . $entry)) {
                    continue;
                }
                copy($this->originalDir . $entry, $this->tmpdir . $entry);
            }
            closedir($dh);
        }
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->subdirs) as $dir) {
            $dh = opendir($this->tmpdir . $dir);
            while (($entry = readdir($dh)) !== false) {
                $entry = "{$this->tmpdir}{$dir}/{$entry}";
                if (! is_file($entry)) {
                    continue;
                }
                unlink($entry);
            }
            closedir($dh);
            if ('.' != $dir) {
                rmdir($this->tmpdir . $dir);
            }
        }
    }

    #[Test]
    public function loadOk(): void
    {
        new Folder\Mbox($this->params);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function loadConfig(): void
    {
        new Folder\Mbox(new ArrayObject($this->params));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function noParams(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Folder\Mbox([]);
    }

    #[Test]
    public function filenameParam(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        // filename is not allowed in this subclass
        new Folder\Mbox(['filename' => 'foobar']);
    }

    #[Test]
    public function loadFailure(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Folder\Mbox(['dirname' => 'This/Folder/Does/Not/Exist']);
    }

    #[Test]
    public function loadUnknownFolder(): void
    {
        $this->params['folder'] = 'UnknownFolder';

        $this->expectException(Exception\InvalidArgumentException::class);
        new Folder\Mbox($this->params);
    }

    #[Test]
    public function changeFolder(): void
    {
        $mail = new Folder\Mbox($this->params);

        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test');

        static::assertSame(
            $mail->getCurrentFolder(),
            DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test',
        );
    }

    #[Test]
    public function changeFolderUnselectable(): void
    {
        $mail = new Folder\Mbox($this->params);
        $this->expectException(Exception\RuntimeException::class);
        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder');
    }

    #[Test]
    public function unknownFolder(): void
    {
        $mail = new Folder\Mbox($this->params);
        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->selectFolder('/Unknown/Folder/');
    }

    #[Test]
    public function globalName(): void
    {
        $mail = new Folder\Mbox($this->params);

        static::assertSame($mail->getFolders()->subfolder->__toString(), DIRECTORY_SEPARATOR . 'subfolder');
    }

    #[Test]
    public function localName(): void
    {
        $mail = new Folder\Mbox($this->params);

        static::assertSame($mail->getFolders()->subfolder->key(), 'test');
    }

    #[Test]
    public function iterator(): void
    {
        $mail     = new Folder\Mbox($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);

        // we search for this folder because we cannot assume an order while iterating
        $searchFolders = [
            DIRECTORY_SEPARATOR . 'subfolder'                                => 'subfolder',
            DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test' => 'test',
            DIRECTORY_SEPARATOR . 'INBOX'                                    => 'INBOX',
        ];
        $foundFolders = [];

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
    public function keyLocalName(): void
    {
        $mail     = new Folder\Mbox($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);
        // we search for this folder because we cannot assume an order while iterating
        $searchFolders = [
            DIRECTORY_SEPARATOR . 'subfolder'                                => 'subfolder',
            DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test' => 'test',
            DIRECTORY_SEPARATOR . 'INBOX'                                    => 'INBOX',
        ];
        $foundFolders = [];

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
        $mail     = new Folder\Mbox($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $localName => $folder) {
            static::assertSame($localName, $folder->getLocalName());
        }
    }

    #[Test]
    public function countsMessages(): void
    {
        $mail = new Folder\Mbox($this->params);

        $count = $mail->countMessages();
        static::assertSame(7, $count);

        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test');
        $count = $mail->countMessages();
        static::assertSame(1, $count);
    }

    #[Test]
    public function reportsMessageSizes(): void
    {
        $mail        = new Folder\Mbox($this->params);
        $shouldSizes = [1 => 397, 89, 694, 452, 497, 101, 139];

        $sizes = $mail->getSize();
        static::assertSame($shouldSizes, $sizes);

        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test');
        $sizes = $mail->getSize();
        static::assertSame([1 => 410], $sizes);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new Folder\Mbox($this->params);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);

        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test');
        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Message in subfolder', $subject);
    }

    #[Test]
    public function sleepWake(): void
    {
        $mail = new Folder\Mbox($this->params);

        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test');
        $count   = $mail->countMessages();
        $content = $mail->getMessage(1)->getContent();

        $serialzed = serialize($mail);
        $mail      = unserialize($serialzed);

        static::assertSame($mail->countMessages(), $count);
        static::assertSame($mail->getMessage(1)->getContent(), $content);

        $mail->selectFolder(DIRECTORY_SEPARATOR . 'subfolder' . DIRECTORY_SEPARATOR . 'test');
        static::assertSame($mail->countMessages(), $count);
        static::assertSame($mail->getMessage(1)->getContent(), $content);
    }

    #[Test]
    public function notMboxFile(): void
    {
        touch("{$this->params['dirname']}foobar");
        $mail = new Folder\Mbox($this->params);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getFolders()->foobar;
    }

    #[Test]
    public function notReadableFolder(): void
    {
        static::assertDirectoryExists("{$this->params['dirname']}subfolder");

        $stat = stat("{$this->params['dirname']}subfolder");
        chmod("{$this->params['dirname']}subfolder", 0);
        clearstatcache();
        $statcheck = stat("{$this->params['dirname']}subfolder");
        if (($statcheck['mode'] % (8 * 8 * 8)) !== 0) {
            chmod("{$this->params['dirname']}subfolder", $stat['mode']);
            static::markTestSkipped(
                'cannot remove read rights, which makes this test useless (maybe you are using Windows?)',
            );
            return;
        }

        $check = false;
        try {
            $mail = new Folder\Mbox($this->params);
        } catch (\Exception) {
            $check = true;

            // test ok
        }

        static::assertIsArray($this->params);
        static::assertArrayHasKey('dirname', $this->params);
        static::assertIsString($this->params['dirname']);

        chmod("{$this->params['dirname']}subfolder", $stat['mode']);

        if (! $check) {
            if (function_exists('posix_getuid') && posix_getuid() === 0) {
                static::markTestSkipped('seems like you are root and we therefore cannot test the error handling');
            } elseif (! function_exists('posix_getuid')) {
                static::markTestSkipped('Can\t test if you\'re root and we therefore cannot test the error handling');
            }
            static::fail('no exception while loading invalid dir with subfolder not readable');
        }
    }

    #[Test]
    public function getInvalidFolder(): void
    {
        $mail         = new Folder\Mbox($this->params);
        $root         = $mail->getFolders();
        $root->foobar = new Folder('x', 'x');
        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getFolders('foobar');
    }

    #[Test]
    public function getVanishedFolder(): void
    {
        $mail         = new Folder\Mbox($this->params);
        $root         = $mail->getFolders();
        $root->foobar = new Folder('foobar', DIRECTORY_SEPARATOR . 'foobar');

        $this->expectException(Exception\RuntimeException::class);
        $mail->selectFolder('foobar');
    }
}
