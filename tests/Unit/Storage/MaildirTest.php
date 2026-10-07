<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayObject;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Tests\Trait\ExtractsMaildirFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function closedir;
use function copy;
use function explode;
use function file_exists;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;
use function opendir;
use function readdir;
use function rename;
use function rmdir;
use function strtoupper;
use function substr;
use function touch;
use function trim;
use function unlink;

use const PHP_OS;

class MaildirTest extends TestCase
{
    use ExtractsMaildirFixtureTrait;

    /** @var string */
    protected $maildir;
    /** @var string */
    protected $tmpdir;

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
            if (2 != $count) {
                $this->markTestSkipped('Are you sure your tmp dir is a valid empty dir?');
                return;
            }
        }

        $this->extractMaildirFixture($originalMaildir);

        $this->maildir = $this->tmpdir;

        foreach (['cur', 'new'] as $dir) {
            mkdir($this->tmpdir . $dir);
            $dh = opendir($originalMaildir . $dir);
            while (($entry = readdir($dh)) !== false) {
                $entry = "{$dir}/{$entry}";
                if (! is_file($originalMaildir . $entry)) {
                    continue;
                }
                copy($originalMaildir . $entry, $this->tmpdir . $entry);
            }
            closedir($dh);
        }
    }

    public function tearDown(): void
    {
        foreach (['cur', 'new'] as $dir) {
            if (! is_dir($this->tmpdir . $dir)) {
                if (is_dir("{$this->tmpdir}{$dir}-isFileTest")) {
                    unlink($this->tmpdir . $dir);
                    rename("{$this->tmpdir}{$dir}-isFileTest", $this->tmpdir . $dir);
                } else {
                    continue;
                }
            }
            chmod($this->tmpdir . $dir, 0o700);
            $dh = opendir($this->tmpdir . $dir);
            while (($entry = readdir($dh)) !== false) {
                $entry = "{$this->tmpdir}{$dir}/{$entry}";
                if (! is_file($entry)) {
                    continue;
                }
                unlink($entry);
            }
            closedir($dh);
            rmdir($this->tmpdir . $dir);
        }

        if (file_exists("{$this->tmpdir}tmp")) {
            unlink("{$this->tmpdir}tmp");
        }
    }

    #[Test]
    public function loadOk(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);
        static::assertSame(Storage\Maildir::class, $mail::class);
    }

    #[Test]
    public function loadConfig(): void
    {
        $mail = new Storage\Maildir(new ArrayObject(['dirname' => $this->maildir]));
        static::assertSame(Storage\Maildir::class, $mail::class);
    }

    #[Test]
    public function loadFailure(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a directory');
        new Storage\Maildir(['dirname' => '/This/Dir/Does/Not/Exist']);
    }

    #[Test]
    public function loadInvalid(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid maildir given');
        new Storage\Maildir(['dirname' => __DIR__]);
    }

    #[Test]
    public function close(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        static::assertNull($mail->close());
    }

    #[Test]
    public function hasFlags(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);
        static::assertTrue($mail->hasFlags);
    }

    #[Test]
    public function hasTop(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        static::assertTrue($mail->hasTop);
    }

    #[Test]
    public function hasCreate(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        static::assertFalse($mail->hasCreate);
    }

    #[Test]
    public function noop(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        static::assertTrue($mail->noop());
    }

    #[Test]
    public function countsMessages(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $count = $mail->countMessages();
        static::assertSame(5, $count);
    }

    #[Test]
    public function reportsMessageSizes(): void
    {
        $mail        = new Storage\Maildir(['dirname' => $this->maildir]);
        $shouldSizes = [1 => 397, 89, 694, 452, 497];

        $sizes = $mail->getSize();
        static::assertSame($shouldSizes, $sizes);
    }

    #[Test]
    public function singleSize(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $size = $mail->getSize(2);
        static::assertSame(89, $size);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageHeader(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageBody(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertSame('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    public function fetchWrongSize(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('id does not exist');
        $mail->getSize(0);
    }

    #[Test]
    public function fetchWrongMessageBody(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('id does not exist');
        $mail->getMessage(0);
    }

    #[Test]
    public function failedRemove(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('maildir is (currently) read-only');
        $mail->removeMessage(1);
    }

    #[Test]
    public function hasFlag(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        static::assertFalse($mail->getMessage(5)->hasFlag(Storage::FLAG_SEEN));
        static::assertTrue($mail->getMessage(5)->hasFlag(Storage::FLAG_RECENT));
        static::assertTrue($mail->getMessage(2)->hasFlag(Storage::FLAG_FLAGGED));
        static::assertFalse($mail->getMessage(2)->hasFlag(Storage::FLAG_ANSWERED));
    }

    #[Test]
    public function getFlags(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $flags = $mail->getMessage(1)->getFlags();
        static::assertTrue(isset($flags[Storage::FLAG_SEEN]));
        static::assertContains(Storage::FLAG_SEEN, $flags);
    }

    #[Test]
    public function uniqueId(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        static::assertTrue($mail->hasUniqueId);
        static::assertSame(1, $mail->getNumberByUniqueId($mail->getUniqueId(1)));

        $ids       = $mail->getUniqueId();
        $shouldIds = [
            1 => '1000000000.P1.example.org',
            '1000000001.P1.example.org',
            '1000000002.P1.example.org',
            '1000000003.P1.example.org',
            '1000000004.P1.example.org',
        ];
        foreach ($ids as $num => $id) {
            static::assertSame($id, $shouldIds[$num]);

            if ($mail->getNumberByUniqueId($id) != $num) {
                static::fail('reverse lookup failed');
            }
        }
    }

    #[Test]
    public function wrongUniqueId(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('unique id not found');
        $mail->getNumberByUniqueId('this_is_an_invalid_id');
    }

    #[Test]
    public function curIsFile(): void
    {
        rename("{$this->maildir}cur", "{$this->maildir}cur-isFileTest");
        touch("{$this->maildir}cur");

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid maildir given');
        new Storage\Maildir(['dirname' => $this->maildir]);
    }

    #[Test]
    public function newIsFile(): void
    {
        rename("{$this->maildir}new", "{$this->maildir}new-isFileTest");
        touch("{$this->maildir}new");

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid maildir given');
        new Storage\Maildir(['dirname' => $this->maildir]);
    }

    #[Test]
    public function tmpIsFile(): void
    {
        touch("{$this->maildir}tmp");

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid maildir given');
        new Storage\Maildir(['dirname' => $this->maildir]);
    }

    #[Test]
    public function notReadableCur(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::markTestSkipped('File permissions are not enforced for the root user');
        }

        chmod("{$this->maildir}cur", 0);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('cannot open maildir');
        new Storage\Maildir(['dirname' => $this->maildir]);
    }

    #[Test]
    public function notReadableNew(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::markTestSkipped('File permissions are not enforced for the root user');
        }

        chmod("{$this->maildir}new", 0);

        $this->expectException(Exception\RuntimeException::class);
        $this->expectExceptionMessage('cannot read recent mails in maildir');
        new Storage\Maildir(['dirname' => $this->maildir]);
    }

    #[Test]
    public function countFlags(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);
        static::assertSame($mail->countMessages(Storage::FLAG_DELETED), 0);
        static::assertSame($mail->countMessages(Storage::FLAG_RECENT), 1);
        static::assertSame($mail->countMessages(Storage::FLAG_FLAGGED), 1);
        static::assertSame($mail->countMessages(Storage::FLAG_SEEN), 4);
        static::assertSame($mail->countMessages([Storage::FLAG_SEEN, Storage::FLAG_FLAGGED]), 1);
        static::assertSame($mail->countMessages([Storage::FLAG_SEEN, Storage::FLAG_RECENT]), 0);
    }

    #[Test]
    public function fetchPart(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);
        static::assertSame($mail->getMessage(4)->getPart(2)->contentType, 'text/x-vertical');
    }

    #[Test]
    public function partSize(): void
    {
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);
        static::assertSame($mail->getMessage(4)->getPart(2)->getSize(), 88);
    }

    #[Test]
    public function sizePlusPlus(): void
    {
        rename(
            "{$this->maildir}/cur/1000000000.P1.example.org:2,S",
            "{$this->maildir}/cur/1000000000.P1.example.org,S=123:2,S",
        );
        rename(
            "{$this->maildir}/cur/1000000001.P1.example.org:2,FS",
            "{$this->maildir}/cur/1000000001.P1.example.org,S=456:2,FS",
        );
        $mail        = new Storage\Maildir(['dirname' => $this->maildir]);
        $shouldSizes = [1 => 123, 456, 694, 452, 497];

        $sizes = $mail->getSize();
        static::assertSame($shouldSizes, $sizes);
    }

    #[Test]
    public function singleSizePlusPlus(): void
    {
        rename(
            "{$this->maildir}/cur/1000000001.P1.example.org:2,FS",
            "{$this->maildir}/cur/1000000001.P1.example.org,S=456:2,FS",
        );
        $mail = new Storage\Maildir(['dirname' => $this->maildir]);

        $size = $mail->getSize(2);
        static::assertSame(456, $size);
    }
}
