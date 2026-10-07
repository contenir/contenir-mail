<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Tests\Trait\UsesProcessTempDirTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function closedir;
use function copy;
use function explode;
use function fclose;
use function file_exists;
use function fopen;
use function fwrite;
use function getenv;
use function mkdir;
use function opendir;
use function readdir;
use function trim;
use function unlink;

class MboxMessageOldTest extends TestCase
{
    use UsesProcessTempDirTrait;

    /** @var string */
    protected $mboxOriginalFile;
    /** @var string */
    protected $mboxFile;
    /** @var string */
    protected $tmpdir;

    public function setUp(): void
    {
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

        $this->mboxOriginalFile = __DIR__ . '/../_files/test.mbox/INBOX';
        $this->mboxFile         = "{$this->tmpdir}INBOX";

        copy($this->mboxOriginalFile, $this->mboxFile);
    }

    public function tearDown(): void
    {
        unlink($this->mboxFile);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new TestAsset\MboxOldMessage(['filename' => $this->mboxFile]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageHeader(): void
    {
        $mail = new TestAsset\MboxOldMessage(['filename' => $this->mboxFile]);

        $subject = $mail->getMessage(1)->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageBody(): void
    {
        $mail = new TestAsset\MboxOldMessage(['filename' => $this->mboxFile]);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertSame('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    public function shortMbox(): void
    {
        $fh = fopen($this->mboxFile, 'w');
        fwrite($fh, "From \r\nSubject: test\r\nFrom \r\nSubject: test2\r\n");
        fclose($fh);
        $mail = new TestAsset\MboxOldMessage(['filename' => $this->mboxFile]);
        static::assertSame($mail->countMessages(), 2);
        static::assertSame($mail->getMessage(1)->subject, 'test');
        static::assertSame($mail->getMessage(1)->getContent(), '');
        static::assertSame($mail->getMessage(2)->subject, 'test2');
        static::assertSame($mail->getMessage(2)->getContent(), '');
    }
}
