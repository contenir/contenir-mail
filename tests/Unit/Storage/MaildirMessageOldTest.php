<?php

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage;
use Contenir\Mail\Tests\Trait\ExtractsMaildirFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
use function rmdir;
use function strtoupper;
use function substr;
use function trim;
use function unlink;

use const PHP_OS;

class MaildirMessageOldTest extends TestCase
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
            if ($count != 2) {
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
                $entry = $dir . '/' . $entry;
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
                continue;
            }
            $dh = opendir($this->tmpdir . $dir);
            while (($entry = readdir($dh)) !== false) {
                $entry = $this->tmpdir . $dir . '/' . $entry;
                if (! is_file($entry)) {
                    continue;
                }
                unlink($entry);
            }
            closedir($dh);
            rmdir($this->tmpdir . $dir);
        }
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageHeader(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageBody(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertSame('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    public function hasFlag(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);

        static::assertFalse($mail->getMessage(5)->hasFlag(Storage::FLAG_SEEN));
        static::assertTrue($mail->getMessage(5)->hasFlag(Storage::FLAG_RECENT));
        static::assertTrue($mail->getMessage(2)->hasFlag(Storage::FLAG_FLAGGED));
        static::assertFalse($mail->getMessage(2)->hasFlag(Storage::FLAG_ANSWERED));
    }

    #[Test]
    public function getFlags(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);

        $flags = $mail->getMessage(1)->getFlags();
        static::assertTrue(isset($flags[Storage::FLAG_SEEN]));
        static::assertContains(Storage::FLAG_SEEN, $flags);
    }

    #[Test]
    public function fetchPart(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);
        static::assertSame($mail->getMessage(4)->getPart(2)->contentType, 'text/x-vertical');
    }

    #[Test]
    public function partSize(): void
    {
        $mail = new TestAsset\MaildirOldMessage(['dirname' => $this->maildir]);
        static::assertSame($mail->getMessage(4)->getPart(2)->getSize(), 80);
    }
}
