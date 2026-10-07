<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayObject;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function clearstatcache;
use function closedir;
use function copy;
use function explode;
use function fclose;
use function file_exists;
use function fopen;
use function function_exists;
use function fwrite;
use function getenv;
use function mkdir;
use function opendir;
use function posix_getuid;
use function readdir;
use function serialize;
use function sleep;
use function stat;
use function trim;
use function unlink;
use function unserialize;

use const INF;

class MboxTest extends TestCase
{
    /** @var string */
    protected $mboxOriginalFile;
    /** @var string */
    protected $mboxFile;
    /** @var string */
    protected $mboxFileUnix;
    /** @var string */
    protected $tmpdir;

    public function setUp(): void
    {
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
            if (2 != $count) {
                $this->markTestSkipped('Are you sure your tmp dir is a valid empty dir?');
                return;
            }
        }

        $this->mboxOriginalFile = __DIR__ . '/../_files/test.mbox/INBOX';
        $this->mboxFile         = "{$this->tmpdir}INBOX";

        copy($this->mboxOriginalFile, $this->mboxFile);
    }

    public function tearDown(): void
    {
        unlink($this->mboxFile);

        if ($this->mboxFileUnix) {
            unlink($this->mboxFileUnix);
        }
    }

    #[Test]
    public function loadOk(): void
    {
        new Storage\Mbox(['filename' => $this->mboxFile]);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function loadConfig(): void
    {
        new Storage\Mbox(new ArrayObject(['filename' => $this->mboxFile]));
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function noParams(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Mbox([]);
    }

    #[Test]
    public function loadFailure(): void
    {
        $this->expectException(Exception\RuntimeException::class);
        new Storage\Mbox(['filename' => 'ThisFileDoesNotExist']);
    }

    #[Test]
    public function loadInvalid(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Mbox(['filename' => __FILE__]);
    }

    #[Test]
    public function close(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $mail->close();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function hasTop(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertTrue($mail->hasTop);
    }

    #[Test]
    public function hasCreate(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertFalse($mail->hasCreate);
    }

    #[Test]
    public function noop(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertTrue($mail->noop());
    }

    #[Test]
    public function countsMessages(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $count = $mail->countMessages();
        static::assertSame(7, $count);
    }

    #[Test]
    public function reportsMessageSizes(): void
    {
        $mail        = new Storage\Mbox(['filename' => $this->mboxFile]);
        $shouldSizes = [1 => 397, 89, 694, 452, 497, 101, 139];

        $sizes = $mail->getSize();
        static::assertSame($shouldSizes, $sizes);
    }

    #[Test]
    public function singleSize(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $size = $mail->getSize(2);
        static::assertSame(89, $size);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    /*
     * public function testFetchTopBody()
     * {
     * $mail = new Storage\Mbox(array('filename' => $this->mboxFile));
     *
     * $content = $mail->getHeader(3, 1)->getContent();
     * $this->assertEquals('Fair river! in thy bright, clear flow', trim($content));
     * }
     */

    #[Test]
    #[Group('6775')]
    public function fetchMessageHeaderUnix(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->getUnixMboxFile(), 'messageEOL' => "\n"]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageHeader(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageBody(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertSame('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    #[Group('6775')]
    public function fetchMessageBodyUnix(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->getUnixMboxFile(), 'messageEOL' => "\n"]);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertSame('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    public function failedRemove(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $this->expectException(Exception\RuntimeException::class);
        $mail->removeMessage(1);
    }

    #[Test]
    public function capabilities(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);
        $capa = $mail->getCapabilities();
        static::assertTrue(isset($capa['uniqueid']));
    }

    #[Test]
    public function valid(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertFalse($mail->valid());
        $mail->rewind();
        static::assertTrue($mail->valid());
    }

    #[Test]
    public function outOfBounds(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $this->expectException(Exception\OutOfBoundsException::class);
        $mail->seek(INF);
    }

    #[Test]
    public function sleepWake(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $count   = $mail->countMessages();
        $content = $mail->getMessage(1)->getContent();

        $serialzed = serialize($mail);
        $mail      = null;
        unlink($this->mboxFile);
        // otherwise this test is to fast for a mtime change
        sleep(2);
        copy($this->mboxOriginalFile, $this->mboxFile);
        $mail = unserialize($serialzed);

        static::assertSame($mail->countMessages(), $count);
        static::assertSame($mail->getMessage(1)->getContent(), $content);
    }

    #[Test]
    public function sleepWakeRemoved(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        $count   = $mail->countMessages();
        $content = $mail->getMessage(1)->getContent();

        $serialzed = serialize($mail);
        $mail      = null;

        static::assertFileExists($this->mboxFile);

        $stat = stat($this->mboxFile);
        chmod($this->mboxFile, 0);
        clearstatcache();
        $statcheck = stat($this->mboxFile);
        if (($statcheck['mode'] % (8 * 8 * 8)) !== 0) {
            chmod($this->mboxFile, $stat['mode']);
            static::markTestSkipped(
                'cannot remove read rights, which makes this test useless (maybe you are using Windows?)',
            );
            return;
        }

        $check = false;
        try {
            $mail = unserialize($serialzed);
        } catch (\Exception) {
            $check = true;

            // test ok
        }

        chmod($this->mboxFile, $stat['mode']);

        if (! $check) {
            if (function_exists('posix_getuid') && posix_getuid() === 0) {
                static::markTestSkipped('seems like you are root and we therefore cannot test the error handling');
            } elseif (! function_exists('posix_getuid')) {
                static::markTestSkipped('Can\t test if you\'re root and we therefore cannot test the error handling');
            }
            static::fail('no exception while waking with non readable file');
        }
    }

    #[Test]
    public function uniqueId(): void
    {
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertFalse($mail->hasUniqueId);
        static::assertSame(1, $mail->getNumberByUniqueId($mail->getUniqueId(1)));

        $ids = $mail->getUniqueId();
        foreach ($ids as $num => $id) {
            static::assertSame($num, $id);

            if ($mail->getNumberByUniqueId($id) != $num) {
                static::fail('reverse lookup failed');
            }
        }
    }

    #[Test]
    public function shortMbox(): void
    {
        $fh = fopen($this->mboxFile, 'w');
        fwrite($fh, "From \r\nSubject: test\r\nFrom \r\nSubject: test2\r\n");
        fclose($fh);
        $mail = new Storage\Mbox(['filename' => $this->mboxFile]);
        static::assertSame($mail->countMessages(), 2);
        static::assertSame($mail->getMessage(1)->subject, 'test');
        static::assertSame($mail->getMessage(1)->getContent(), '');
        static::assertSame($mail->getMessage(2)->subject, 'test2');
        static::assertSame($mail->getMessage(2)->getContent(), '');
    }

    private function getUnixMboxFile(): string
    {
        $this->mboxFileUnix = "{$this->tmpdir}INBOX.unix";

        copy(__DIR__ . '/../_files/test.mbox/INBOX.unix', $this->mboxFileUnix);

        return $this->mboxFileUnix;
    }
}
