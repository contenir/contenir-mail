<?php

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayObject;
use Contenir\Mail\Protocol;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function closedir;
use function copy;
use function count;
use function explode;
use function file_exists;
use function getenv;
use function is_dir;
use function mkdir;
use function opendir;
use function readdir;
use function rmdir;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;

#[CoversClass(\Contenir\Mail\Storage\Pop3::class)]
class Pop3Test extends TestCase
{
    /** @var array */
    protected $params;

    public function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POP3_ENABLED')) {
            $this->markTestSkipped('Contenir_Mail POP3 tests are not enabled');
        }

        $this->params = [
            'host'     => getenv('TESTS_CONTENIR_MAIL_POP3_HOST'),
            'user'     => getenv('TESTS_CONTENIR_MAIL_POP3_USER'),
            'password' => getenv('TESTS_CONTENIR_MAIL_POP3_PASSWORD'),
        ];

        if (getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR') && getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR')) {
            if (
                ! file_exists(getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR') . DIRECTORY_SEPARATOR . 'inbox')
                && ! file_exists(getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR') . DIRECTORY_SEPARATOR . 'INBOX')
            ) {
                $this->markTestSkipped(
                    'There is no file name "inbox" or "INBOX" in '
                        . getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR')
                        . '. I won\'t use it for testing. '
                        . 'This is you safety net. If you think it is the right directory just '
                        . 'create an empty file named INBOX or remove/deactived this message.',
                );
            }

            $this->cleanDir(getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR'));
            $this->copyDir(
                __DIR__ . '/../_files/test.' . getenv('TESTS_CONTENIR_MAIL_SERVER_FORMAT'),
                getenv('TESTS_CONTENIR_MAIL_SERVER_TESTDIR'),
            );
        }
    }

    protected function cleanDir(string $dir): void
    {
        $dh = opendir($dir);
        while (($entry = readdir($dh)) !== false) {
            if ($entry == '.' || $entry == '..') {
                continue;
            }
            $fullname = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullname)) {
                $this->cleanDir($fullname);
                rmdir($fullname);
            } else {
                unlink($fullname);
            }
        }
        closedir($dh);
    }

    protected function copyDir(string $dir, string $dest): void
    {
        $dh = opendir($dir);
        while (($entry = readdir($dh)) !== false) {
            if ($entry == '.' || $entry == '..' || $entry == '.svn') {
                continue;
            }
            $fullname = $dir . DIRECTORY_SEPARATOR . $entry;
            $destname = $dest . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($fullname)) {
                mkdir($destname);
                $this->copyDir($fullname, $destname);
            } else {
                copy($fullname, $destname);
            }
        }
        closedir($dh);
    }

    #[Test]
    public function connectOk(): void
    {
        new Storage\Pop3($this->params);
    }

    #[Test]
    public function connectConfig(): void
    {
        new Storage\Pop3(new ArrayObject($this->params));
    }

    #[Test]
    public function connectFailure(): void
    {
        $this->params['host'] = 'example.example';

        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Pop3($this->params);
    }

    #[Test]
    public function noParams(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Pop3([]);
    }

    #[Test]
    public function connectSSL(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POP3_SSL')) {
            return;
        }

        $this->params['ssl'] = 'SSL';

        new Storage\Pop3($this->params);
    }

    #[Test]
    public function connectTLS(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POP3_TLS')) {
            return;
        }

        $this->params['ssl'] = 'TLS';

        new Storage\Pop3($this->params);
    }

    #[Test]
    public function connectSelfSignedSSL(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_POP3_SSL')) {
            return;
        }

        $this->params['ssl']            = 'SSL';
        $this->params['novalidatecert'] = true;

        new Storage\Pop3($this->params);
    }

    #[Test]
    public function invalidService(): void
    {
        $this->params['port'] = getenv('TESTS_CONTENIR_MAIL_POP3_INVALID_PORT');

        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Pop3($this->params);
    }

    #[Test]
    public function wrongService(): void
    {
        $this->params['port'] = getenv('TESTS_CONTENIR_MAIL_POP3_WRONG_PORT');

        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Pop3($this->params);
    }

    #[Test]
    public function close(): void
    {
        $mail = new Storage\Pop3($this->params);

        $mail->close();
    }

    #[Test]
    public function hasTop(): void
    {
        $mail = new Storage\Pop3($this->params);

        static::assertTrue($mail->hasTop);
    }

    #[Test]
    public function hasCreate(): void
    {
        $mail = new Storage\Pop3($this->params);

        static::assertFalse($mail->hasCreate);
    }

    #[Test]
    public function noop(): void
    {
        $mail = new Storage\Pop3($this->params);

        $mail->noop();
    }

    #[Test]
    public function countsMessages(): void
    {
        $mail = new Storage\Pop3($this->params);

        $count = $mail->countMessages();
        static::assertEquals(7, $count);
    }

    #[Test]
    public function reportsMessageSizes(): void
    {
        $mail        = new Storage\Pop3($this->params);
        $shouldSizes = [1 => 397, 89, 694, 452, 497, 101, 139];

        $sizes = $mail->getSize();
        static::assertEquals($shouldSizes, $sizes);
    }

    #[Test]
    public function singleSize(): void
    {
        $mail = new Storage\Pop3($this->params);

        $size = $mail->getSize(2);
        static::assertEquals(89, $size);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new Storage\Pop3($this->params);

        $subject = $mail->getMessage(1)->subject;
        static::assertEquals('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageHeader(): void
    {
        $mail = new Storage\Pop3($this->params);

        $subject = $mail->getMessage(1)->subject;
        static::assertEquals('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageBody(): void
    {
        $mail = new Storage\Pop3($this->params);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertEquals('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    public function withInstanceConstruction(): void
    {
        $protocol = new Protocol\Pop3($this->params['host']);
        $mail     = new Storage\Pop3($protocol);

        $this->expectException(Exception\InvalidArgumentException::class);
        // because we did no login this has to throw an exception
        $mail->getMessage(1);
    }

    #[Test]
    public function requestAfterClose(): void
    {
        $mail = new Storage\Pop3($this->params);
        $mail->close();

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getMessage(1);
    }

    #[Test]
    public function serverCapa(): void
    {
        $mail = new Protocol\Pop3($this->params['host']);
        static::assertInternalType('array', $mail->capa());
    }

    #[Test]
    public function serverUidl(): void
    {
        $mail = new Protocol\Pop3($this->params['host']);
        $mail->login($this->params['user'], $this->params['password']);

        $uids = $mail->uniqueid();
        static::assertEquals(count($uids), 7);

        static::assertEquals($uids[1], $mail->uniqueid(1));
    }

    #[Test]
    public function rawHeader(): void
    {
        $mail = new Storage\Pop3($this->params);

        static::assertContains("\r\nSubject: Simple Message\r\n", $mail->getRawHeader(1));
    }

    #[Test]
    public function uniqueId(): void
    {
        $mail = new Storage\Pop3($this->params);

        static::assertTrue($mail->hasUniqueId);
        static::assertEquals(1, $mail->getNumberByUniqueId($mail->getUniqueId(1)));

        $ids = $mail->getUniqueId();
        foreach ($ids as $num => $id) {
            foreach ($ids as $innerNum => $innerId) {
                if ($num == $innerNum) {
                    continue;
                }
                if ($id == $innerId) {
                    static::fail('not all ids are unique');
                }
            }

            if ($mail->getNumberByUniqueId($id) != $num) {
                static::fail('reverse lookup failed');
            }
        }
    }

    #[Test]
    public function wrongUniqueId(): void
    {
        $mail = new Storage\Pop3($this->params);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getNumberByUniqueId('this_is_an_invalid_id');
    }

    #[Test]
    public function readAfterClose(): void
    {
        $protocol = new Protocol\Pop3($this->params['host']);
        $protocol->logout();

        $this->expectException(Exception\InvalidArgumentException::class);
        $protocol->readResponse();
    }

    #[Test]
    public function remove(): void
    {
        $mail  = new Storage\Pop3($this->params);
        $count = $mail->countMessages();

        $mail->removeMessage(1);
        static::assertEquals($mail->countMessages(), --$count);

        unset($mail[2]);
        static::assertEquals($mail->countMessages(), --$count);
    }

    #[Test]
    public function dotMessage(): void
    {
        $mail    = new Storage\Pop3($this->params);
        $content = '';
        $content .= "Before the dot\r\n";
        $content .= ".\r\n";
        $content .= "is after the dot\r\n";
        static::assertEquals($mail->getMessage(7)->getContent(), $content);
    }
}
