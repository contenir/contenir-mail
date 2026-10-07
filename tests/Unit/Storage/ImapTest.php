<?php

namespace Contenir\Mail\Tests\Unit\Storage;

use ArrayObject;
use Contenir\Mail\Protocol;
use Contenir\Mail\Storage;
use Contenir\Mail\Storage\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_combine;
use function closedir;
use function copy;
use function explode;
use function file_exists;
use function getenv;
use function is_dir;
use function mkdir;
use function opendir;
use function range;
use function readdir;
use function rmdir;
use function strlen;
use function trim;
use function unlink;

use const DIRECTORY_SEPARATOR;
use const INF;

#[CoversClass(\Contenir\Mail\Storage\Imap::class)]
class ImapTest extends TestCase
{
    /** @var array */
    protected $params;

    public function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_ENABLED')) {
            $this->markTestSkipped('Contenir_Mail IMAP tests are not enabled');
        }
        $this->params = [
            'host'     => getenv('TESTS_CONTENIR_MAIL_IMAP_HOST'),
            'user'     => getenv('TESTS_CONTENIR_MAIL_IMAP_USER'),
            'password' => getenv('TESTS_CONTENIR_MAIL_IMAP_PASSWORD'),
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
        new Storage\Imap($this->params);
    }

    #[Test]
    public function connectConfig(): void
    {
        new Storage\Imap(new ArrayObject($this->params));
    }

    #[Test]
    public function connectFailure(): void
    {
        $this->params['host'] = 'example.example';
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($this->params);
    }

    #[Test]
    public function noParams(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap([]);
    }

    #[Test]
    public function connectSSL(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_SSL')) {
            return;
        }

        $this->params['ssl'] = 'SSL';
        new Storage\Imap($this->params);
    }

    #[Test]
    public function connectTLS(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_TLS')) {
            return;
        }

        $this->params['ssl'] = 'TLS';
        new Storage\Imap($this->params);
    }

    #[Test]
    public function connectSelfSignedSSL(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_IMAP_SSL')) {
            return;
        }

        $this->params['ssl']            = 'SSL';
        $this->params['novalidatecert'] = true;
        new Storage\Imap($this->params);
    }

    #[Test]
    public function invalidService(): void
    {
        $this->params['port'] = getenv('TESTS_CONTENIR_MAIL_IMAP_INVALID_PORT');
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($this->params);
    }

    #[Test]
    public function wrongService(): void
    {
        $this->params['port'] = getenv('TESTS_CONTENIR_MAIL_IMAP_WRONG_PORT');
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($this->params);
    }

    #[Test]
    public function wrongUsername(): void
    {
        // this also triggers ...{chars}<NL>token for coverage
        $this->params['user'] = "there is no\nnobody";
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($this->params);
    }

    #[Test]
    public function withInstanceConstruction(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        // if $protocol is invalid the constructor fails while selecting INBOX
        new Storage\Imap($protocol);
    }

    #[Test]
    public function withNotConnectedInstance(): void
    {
        $protocol = new Protocol\Imap();
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($protocol);
    }

    #[Test]
    public function withNotLoggedInstance(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($protocol);
    }

    #[Test]
    public function wrongFolder(): void
    {
        $this->params['folder'] = 'this folder does not exist on your server';

        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($this->params);
    }

    #[Test]
    public function close(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->close();
    }

    #[Test]
    public function hasCreate(): void
    {
        $mail = new Storage\Imap($this->params);

        static::assertFalse($mail->hasCreate);
    }

    #[Test]
    public function noop(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->noop();
    }

    #[Test]
    public function countsMessages(): void
    {
        $mail = new Storage\Imap($this->params);

        $count = $mail->countMessages();
        static::assertEquals(7, $count);
    }

    #[Test]
    public function reportsMessageSizes(): void
    {
        $mail        = new Storage\Imap($this->params);
        $shouldSizes = [1 => 397, 89, 694, 452, 497, 101, 139];

        $sizes = $mail->getSize();
        static::assertEquals($shouldSizes, $sizes);
    }

    #[Test]
    public function singleSize(): void
    {
        $mail = new Storage\Imap($this->params);

        $size = $mail->getSize(2);
        static::assertEquals(89, $size);
    }

    #[Test]
    public function fetchHeader(): void
    {
        $mail = new Storage\Imap($this->params);

        $subject = $mail->getMessage(1)->subject;
        static::assertEquals('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageHeader(): void
    {
        $mail = new Storage\Imap($this->params);

        $subject = $mail->getMessage(1)->subject;
        static::assertEquals('Simple Message', $subject);
    }

    #[Test]
    public function fetchMessageBody(): void
    {
        $mail = new Storage\Imap($this->params);

        $content = $mail->getMessage(3)->getContent();
        [$content] = explode("\n", $content, 2);
        static::assertEquals('Fair river! in thy bright, clear flow', trim($content));
    }

    #[Test]
    public function remove(): void
    {
        $mail = new Storage\Imap($this->params);

        $count = $mail->countMessages();
        $mail->removeMessage(1);
        static::assertEquals($mail->countMessages(), $count - 1);
    }

    #[Test]
    public function tooLateCount(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->close();
        // after closing we can't count messages

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->countMessages();
    }

    #[Test]
    public function loadUnkownFolder(): void
    {
        $this->params['folder'] = 'UnknownFolder';
        $this->expectException(Exception\InvalidArgumentException::class);
        new Storage\Imap($this->params);
    }

    #[Test]
    public function changeFolder(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->selectFolder('subfolder/test');

        static::assertEquals($mail->getCurrentFolder(), 'subfolder/test');
    }

    #[Test]
    public function unknownFolder(): void
    {
        $mail = new Storage\Imap($this->params);
        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->selectFolder('/Unknown/Folder/');
    }

    #[Test]
    public function globalName(): void
    {
        $mail = new Storage\Imap($this->params);
        static::assertEquals($mail->getFolders()->subfolder->__toString(), 'subfolder');
    }

    #[Test]
    public function localName(): void
    {
        $mail = new Storage\Imap($this->params);
        static::assertEquals($mail->getFolders()->subfolder->key(), 'test');
    }

    #[Test]
    public function keyLocalName(): void
    {
        $mail     = new Storage\Imap($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);
        // we search for this folder because we can't assume an order while iterating
        $searchFolders = [
            'subfolder'      => 'subfolder',
            'subfolder/test' => 'test',
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
    public function selectable(): void
    {
        $mail     = new Storage\Imap($this->params);
        $iterator = new RecursiveIteratorIterator($mail->getFolders(), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $localName => $folder) {
            static::assertEquals($localName, $folder->getLocalName());
        }
    }

    #[Test]
    public function countFolder(): void
    {
        $mail = new Storage\Imap($this->params);

        $mail->selectFolder('subfolder/test');
        $count = $mail->countMessages();
        static::assertEquals(1, $count);
    }

    #[Test]
    public function sizeFolder(): void
    {
        $mail = new Storage\Imap($this->params);

        $mail->selectFolder('subfolder/test');
        $sizes = $mail->getSize();
        static::assertEquals([1 => 410], $sizes);
    }

    #[Test]
    public function fetchHeaderFolder(): void
    {
        $mail = new Storage\Imap($this->params);

        $mail->selectFolder('subfolder/test');
        $subject = $mail->getMessage(1)->subject;
        static::assertEquals('Message in subfolder', $subject);
    }

    #[Test]
    public function hasFlag(): void
    {
        $mail = new Storage\Imap($this->params);

        static::assertTrue($mail->getMessage(1)->hasFlag(Storage::FLAG_RECENT));
    }

    #[Test]
    public function getFlags(): void
    {
        $mail = new Storage\Imap($this->params);

        $flags = $mail->getMessage(1)->getFlags();
        static::assertTrue(isset($flags[Storage::FLAG_RECENT]));
        static::assertContains(Storage::FLAG_RECENT, $flags);
    }

    #[Test]
    public function rawHeader(): void
    {
        $mail = new Storage\Imap($this->params);

        static::assertContains("\r\nSubject: Simple Message\r\n", $mail->getRawHeader(1));
    }

    #[Test]
    public function uniqueId(): void
    {
        $mail = new Storage\Imap($this->params);

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
        $mail = new Storage\Imap($this->params);
        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getNumberByUniqueId('this_is_an_invalid_id');
    }

    #[Test]
    public function createFolder(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->createFolder('subfolder/test1');
        $mail->createFolder('test2', 'subfolder');
        $mail->createFolder('test3', $mail->getFolders()->subfolder);

        $mail->getFolders()->subfolder->test1;
        $mail->getFolders()->subfolder->test2;
        $mail->getFolders()->subfolder->test3;
    }

    #[Test]
    public function createExistingFolder(): void
    {
        $mail = new Storage\Imap($this->params);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->createFolder('subfolder/test');
    }

    #[Test]
    public function removeFolderName(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->removeFolder('subfolder/test');

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getFolders()->subfolder->test;
    }

    #[Test]
    public function removeFolderInstance(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->removeFolder($mail->getFolders()->subfolder->test);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->getFolders()->subfolder->test;
    }

    #[Test]
    public function removeInvalidFolder(): void
    {
        $mail = new Storage\Imap($this->params);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->removeFolder('thisFolderDoestNotExist');
    }

    #[Test]
    public function renameFolder(): void
    {
        $mail = new Storage\Imap($this->params);

        $mail->renameFolder('subfolder/test', 'subfolder/test1');
        $mail->renameFolder($mail->getFolders()->subfolder->test1, 'subfolder/test');

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->renameFolder('subfolder/test', 'INBOX');
    }

    #[Test]
    public function append(): void
    {
        $mail  = new Storage\Imap($this->params);
        $count = $mail->countMessages();

        $message = '';
        $message .= "From: me@example.org\r\n";
        $message .= "To: you@example.org\r\n";
        $message .= "Subject: append test\r\n";
        $message .= "\r\n";
        $message .= "This is a test\r\n";
        $mail->appendMessage($message);

        static::assertEquals($count + 1, $mail->countMessages());
        static::assertEquals($mail->getMessage($count + 1)->subject, 'append test');

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->appendMessage('');
    }

    #[Test]
    public function copy(): void
    {
        $mail = new Storage\Imap($this->params);

        $mail->selectFolder('subfolder/test');
        $count = $mail->countMessages();
        $mail->selectFolder('INBOX');
        $message = $mail->getMessage(1);

        $mail->copyMessage(1, 'subfolder/test');
        $mail->selectFolder('subfolder/test');
        static::assertEquals($count + 1, $mail->countMessages());
        static::assertEquals($mail->getMessage($count + 1)->subject, $message->subject);
        static::assertEquals($mail->getMessage($count + 1)->from, $message->from);
        static::assertEquals($mail->getMessage($count + 1)->to, $message->to);

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->copyMessage(1, 'justARandomFolder');
    }

    #[Test]
    public function setFlags(): void
    {
        $mail = new Storage\Imap($this->params);

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

        $mail->setFlags(1, ['myflag']);
        $message = $mail->getMessage(1);
        static::assertFalse($message->hasFlag(Storage::FLAG_SEEN));
        static::assertFalse($message->hasFlag(Storage::FLAG_FLAGGED));
        static::assertTrue($message->hasFlag('myflag'));

        $this->expectException(Exception\InvalidArgumentException::class);
        $mail->setFlags(1, [Storage::FLAG_RECENT]);
    }

    /**
     */
    #[Test]
    #[Group('7353')]
    public function canMarkMessageUnseen(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->setFlags(1, [Storage::FLAG_UNSEEN]);
        $message = $mail->getMessage(1);
        static::assertTrue($message->hasFlag(Storage::FLAG_UNSEEN));
    }

    #[Test]
    public function capability(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $capa = $protocol->capability();
        static::assertInternalType('array', $capa);
        static::assertEquals($capa[0], 'CAPABILITY');
    }

    #[Test]
    public function select(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $status = $protocol->select('INBOX');
        static::assertInternalType('array', $status['flags']);
        static::assertEquals($status['exists'], 7);
    }

    #[Test]
    public function examine(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $status = $protocol->examine('INBOX');
        static::assertInternalType('array', $status['flags']);
        static::assertEquals($status['exists'], 7);
    }

    #[Test]
    public function closedSocketNewlineToken(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $protocol->logout();

        $this->expectException(Exception\InvalidArgumentException::class);
        $protocol->select("foo\nbar");
    }

    #[Test]
    public function escaping(): void
    {
        $protocol = new Protocol\Imap();
        static::assertEquals($protocol->escapeString('foo'), '"foo"');
        static::assertEquals($protocol->escapeString('f\\oo'), '"f\\\\oo"');
        static::assertEquals($protocol->escapeString('f"oo'), '"f\\"oo"');
        static::assertEquals($protocol->escapeString('foo', 'bar'), ['"foo"', '"bar"']);
        static::assertEquals($protocol->escapeString("f\noo"), ['{4}', "f\noo"]);
        static::assertEquals($protocol->escapeList(['foo']), '(foo)');
        static::assertEquals($protocol->escapeList([['foo']]), '((foo))');
        static::assertEquals($protocol->escapeList(['foo', 'bar']), '(foo bar)');
    }

    #[Test]
    public function fetch(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $protocol->select('INBOX');

        $range = array_combine(range(1, 7), range(1, 7));
        static::assertEquals($protocol->fetch('UID', 1, INF), $range);
        static::assertEquals($protocol->fetch('UID', 1, 7), $range);
        static::assertEquals($protocol->fetch('UID', range(1, 7)), $range);
        static::assertInternalType('numeric', $protocol->fetch('UID', 1));

        $result = $protocol->fetch(['UID', 'FLAGS'], 1, INF);
        foreach ($result as $k => $v) {
            static::assertEquals($k, $v['UID']);
            static::assertInternalType('array', $v['FLAGS']);
        }

        $this->expectException(Exception\InvalidArgumentException::class);
        $protocol->fetch('UID', 99);
    }

    #[Test]
    public function fetchByUid(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $protocol->select('INBOX');

        $result  = $protocol->fetch(['UID', 'FLAGS'], 1);
        $uid     = $result['UID'];
        $message = $protocol->fetch(['UID', 'FLAGS'], $uid, null, true);
        static::assertEquals($uid, $message['UID']);
    }

    #[Test]
    public function store(): void
    {
        $protocol = new Protocol\Imap($this->params['host']);
        $protocol->login($this->params['user'], $this->params['password']);
        $protocol->select('INBOX');

        static::assertTrue($protocol->store(['\Flagged'], 1));
        static::assertTrue($protocol->store(['\Flagged'], 1, null, '-'));
        static::assertTrue($protocol->store(['\Flagged'], 1, null, '+'));

        $result = $protocol->store(['\Flagged'], 1, null, '', false);
        static::assertContains('\Flagged', $result[1]);
        $result = $protocol->store(['\Flagged'], 1, null, '-', false);
        static::assertStringNotContainsString('\Flagged', $result[1]);
        $result = $protocol->store(['\Flagged'], 1, null, '+', false);
        static::assertContains('\Flagged', $result[1]);
    }

    #[Test]
    public function move(): void
    {
        $mail = new Storage\Imap($this->params);
        $mail->selectFolder('subfolder/test');
        $toCount = $mail->countMessages();
        $mail->selectFolder('INBOX');
        $fromCount = $mail->countMessages();
        $mail->moveMessage(1, 'subfolder/test');

        static::assertEquals($fromCount - 1, $mail->countMessages());
        $mail->selectFolder('subfolder/test');
        static::assertEquals($toCount + 1, $mail->countMessages());
    }

    #[Test]
    public function countFlags(): void
    {
        $mail = new Storage\Imap($this->params);
        foreach ($mail as $id => $message) {
            $mail->setFlags($id, []);
        }
        static::assertEquals($mail->countMessages(Storage::FLAG_SEEN), 0);
        static::assertEquals($mail->countMessages(Storage::FLAG_ANSWERED), 0);
        static::assertEquals($mail->countMessages(Storage::FLAG_FLAGGED), 0);

        $mail->setFlags(1, [Storage::FLAG_SEEN, Storage::FLAG_ANSWERED]);
        $mail->setFlags(2, [Storage::FLAG_SEEN]);
        static::assertEquals($mail->countMessages(Storage::FLAG_SEEN), 2);
        static::assertEquals($mail->countMessages(Storage::FLAG_ANSWERED), 1);
        static::assertEquals($mail->countMessages([Storage::FLAG_SEEN, Storage::FLAG_ANSWERED]), 1);
        static::assertEquals($mail->countMessages([Storage::FLAG_SEEN, Storage::FLAG_FLAGGED]), 0);
        static::assertEquals($mail->countMessages(Storage::FLAG_FLAGGED), 0);
    }

    #[Test]
    public function delimiter(): void
    {
        $mail      = new Storage\Imap($this->params);
        $delimiter = $mail->delimiter();
        static::assertEquals(strlen($delimiter), 1);
    }
}
