<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use Contenir\Mail\Storage\Mbox;
use Contenir\Mail\Storage\Message\MessageInterface;
use LimitIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(Mbox::class)]
class MboxInterfaceTest extends TestCase
{
    /** @var string  */
    protected $mboxFile;

    public function setUp(): void
    {
        $this->mboxFile = __DIR__ . '/../_files/test.mbox/INBOX';
    }

    #[Test]
    public function countsMessages(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        $count = count($list);
        static::assertSame(7, $count);
    }

    #[Test]
    public function isset(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertTrue(isset($list[1]));
    }

    #[Test]
    public function notIsset(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        static::assertFalse(isset($list[10]));
    }

    #[Test]
    public function arrayGet(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        $subject = $list[1]->subject;
        static::assertSame('Simple Message', $subject);
    }

    #[Test]
    public function arraySetFail(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        $this->expectException(Exception\RuntimeException::class);
        $list[1] = 'test';
    }

    #[Test]
    public function iterationKey(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);
        $pos  = 1;

        foreach ($list as $key => $message) {
            static::assertSame($key, $pos, "wrong key in iteration {$pos}");
            ++$pos;
        }
    }

    #[Test]
    public function iterationIsMessage(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        foreach ($list as $message) {
            static::assertInstanceOf(
                MessageInterface::class,
                $message,
                'value in iteration is not a mail message',
            );
        }
    }

    #[Test]
    public function iterationRounds(): void
    {
        $list  = new Storage\Mbox(['filename' => $this->mboxFile]);
        $count = 0;

        foreach ($list as $message) {
            ++$count;
        }

        static::assertSame(7, $count);
    }

    #[Test]
    public function iterationWithSeek(): void
    {
        $list  = new Storage\Mbox(['filename' => $this->mboxFile]);
        $count = 0;

        foreach (new LimitIterator($list, 1, 3) as $message) {
            ++$count;
        }

        static::assertSame(3, $count);
    }

    #[Test]
    public function iterationWithSeekCapped(): void
    {
        $list  = new Storage\Mbox(['filename' => $this->mboxFile]);
        $count = 0;

        foreach (new LimitIterator($list, 3, 7) as $message) {
            ++$count;
        }

        static::assertSame(5, $count);
    }

    #[Test]
    public function fallback(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        $result = $list->noop();
        static::assertTrue($result);
    }

    #[Test]
    public function wrongVariable(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        $this->expectException(Exception\InvalidArgumentException::class);
        $list->thisdoesnotexist;
    }

    #[Test]
    public function getHeaders(): void
    {
        $list    = new Storage\Mbox(['filename' => $this->mboxFile]);
        $headers = $list[1]->getHeaders();
        static::assertNotEmpty($headers);
    }

    #[Test]
    public function wrongHeader(): void
    {
        $list = new Storage\Mbox(['filename' => $this->mboxFile]);

        $this->expectException(Exception\InvalidArgumentException::class);
        $list[1]->thisdoesnotexist;
    }
}
