<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Protocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\RuntimeException as ProtocolException;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Storage\Pop3;
use Contenir\Mail\Storage\Pop3Config;
use Contenir\Mail\Storage\RemoteConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pop3::class)]
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[CoversClass(Pop3Config::class)]
#[CoversClass(RemoteConnection::class)]
#[Group('unit')]
final class Pop3StorageTest extends TestCase
{
    /**
     * Not a real password: a value to look for where none should be.
     *
     * @mago-expect lint:no-literal-password A made-up value the tests look for, not a credential.
     */
    private const string PASSWORD = 'hunter2-secret';

    private const string HEADER = "Subject: Hello\r\nFrom: a@example.com\r\n\r\n";

    private function protocol(): Protocol\Pop3&MockObject
    {
        return $this->createMock(Protocol\Pop3::class);
    }

    private function pop3(?Protocol\Pop3 $protocol = null): Pop3
    {
        return new Pop3($protocol ?? $this->protocol());
    }

    #[Test]
    public function connectsWithSettings(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('connect')->with('pop.example.com', 995, 'ssl');

        new Pop3(['host' => 'pop.example.com', 'port' => 995, 'ssl' => 'SSL', 'user' => 'u'], $protocol);
    }

    #[Test]
    public function connectsWithStartTlsByDefault(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('connect')->with('pop.example.com', null, 'tls');

        new Pop3(['host' => 'pop.example.com', 'user' => 'u'], $protocol);
    }

    #[Test]
    public function connectsWithConfigObject(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('login')->with('u', '');

        new Pop3(new Pop3Config(new ConnectionConfig(security: Security::Tls), 'u'), $protocol);
    }

    #[Test]
    public function turnsOffPeerVerificationWhenAsked(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('setNoValidateCert')->with(true);

        new Pop3(['user' => 'u', 'novalidatecert' => true], $protocol);
    }

    #[Test]
    public function logsIn(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('login')->with('u', self::PASSWORD);

        new Pop3(['user' => 'u', 'password' => self::PASSWORD], $protocol);
    }

    #[Test]
    public function logsOutOnce(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('logout');
        $pop3 = $this->pop3($protocol);
        $pop3->close();
        $pop3->close();
        unset($pop3);
    }

    #[Test]
    public function countsMessages(): void
    {
        $protocol = $this->protocol();
        $protocol->method('status')
            ->willReturnCallback(static function (&$messages, &$octets): void {
                $messages = '3';
                $octets   = '300';
            });

        static::assertSame(3, $this->pop3($protocol)->countMessages());
    }

    #[Test]
    public function countsNothingWhenTheServerSaysNothing(): void
    {
        static::assertSame(0, $this->pop3()->countMessages());
    }

    #[Test]
    public function logsOutWhenConnectedStorageIsDestroyed(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('logout');
        $pop3 = new Pop3(['user' => 'u'], $protocol);
        unset($pop3);
    }

    #[Test]
    public function hasDeleteButNotPartFetching(): void
    {
        $capabilities = $this->pop3()->getCapabilities();

        static::assertSame([true, false], [$capabilities['delete'], $capabilities['fetchPart']]);
    }

    #[Test]
    public function refusesToCountByFlag(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('POP3 keeps no flags to count by');

        $this->pop3()->countMessages(Flag::Seen);
    }

    #[Test]
    public function measuresMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->method('getList')->with(2)->willReturn('120');

        static::assertSame(120, $this->pop3($protocol)->getSize(2));
    }

    #[Test]
    public function measuresMalformedListAsEmpty(): void
    {
        $protocol = $this->protocol();
        $protocol->method('getList')->willReturn([]);

        static::assertSame(0, $this->pop3($protocol)->getSize(2));
    }

    #[Test]
    public function measuresEveryMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->method('getList')->willReturn([1 => 10, '2' => '20', 3 => []]);

        static::assertSame([1 => 10, 2 => 20, 3 => 0], $this->pop3($protocol)->getSizes());
    }

    #[Test]
    public function measuresNothingFromMalformedList(): void
    {
        $protocol = $this->protocol();
        $protocol->method('getList')->willReturn(5);

        static::assertSame([], $this->pop3($protocol)->getSizes());
    }

    #[Test]
    public function readsHeadersWithTop(): void
    {
        $protocol = $this->protocol();
        $protocol->method('top')->with(1, 0, true)->willReturn(self::HEADER);

        static::assertSame('Hello', $this->pop3($protocol)->getMessage(1)->getSubject());
    }

    #[Test]
    public function retrievesBodyOnlyWhenRead(): void
    {
        $protocol = $this->protocol();
        $protocol->method('top')->willReturn(self::HEADER);
        $protocol->expects($this->once())
            ->method('retrieve')
            ->with(1)
            ->willReturn(self::HEADER . 'body');
        $message = $this->pop3($protocol)->getMessage(1);
        $message->getContent();

        static::assertSame('body', $message->getContent());
    }

    #[Test]
    public function usesWholeMessageFromServerWithoutTop(): void
    {
        $protocol = $this->protocol();
        $protocol->method('top')->willReturn(self::HEADER . 'body');
        $protocol->expects($this->never())->method('retrieve');

        static::assertSame('body', $this->pop3($protocol)->getMessage(1)->getContent());
    }

    #[Test]
    public function readsRawHeader(): void
    {
        $protocol = $this->protocol();
        $protocol->method('top')
            ->with(1, 0, true)
            ->willReturn(self::HEADER . 'body');

        static::assertSame(self::HEADER, $this->pop3($protocol)->getRawHeader(1));
    }

    #[Test]
    public function readsRawContent(): void
    {
        $protocol = $this->protocol();
        $protocol->method('retrieve')->willReturn(self::HEADER . 'body');

        static::assertSame('body', $this->pop3($protocol)->getRawContent(1));
    }

    #[DataProvider('invalidNumberProvider')]
    #[Test]
    public function refusesMessageNumberBelowOne(string $method): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->never())->method($this->logicalNot($this->identicalTo('logout')));

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no message 0');

        (new Pop3($protocol))->{$method}(0);
    }

    #[Test]
    public function removesMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('delete')->with(3);

        $this->pop3($protocol)->removeMessage(3);
    }

    #[Test]
    public function keepsTheConnectionAlive(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('noop');

        $this->pop3($protocol)->noop();
    }

    #[Test]
    public function readsUniqueIdWithUidl(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willReturnMap([[null, [1 => 'abc']], [1, 'abc']]);

        static::assertSame('abc', $this->pop3($protocol)->getUniqueId(1));
    }

    #[Test]
    public function readsMalformedUniqueIdAsEmpty(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willReturnMap([[null, []], [1, []]]);

        static::assertSame('', $this->pop3($protocol)->getUniqueId(1));
    }

    #[Test]
    public function asksForUidlOnce(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->exactly(3))
            ->method('uniqueid')
            ->willReturnMap([[null, [1 => 'abc']], [1, 'abc']]);
        $pop3 = $this->pop3($protocol);
        $pop3->getUniqueId(1);
        $pop3->getUniqueId(1);
    }

    #[Test]
    public function listsUniqueIds(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willReturn([1 => 'abc', '2' => 'def', 3 => []]);

        static::assertSame([1 => 'abc', 2 => 'def', 3 => ''], $this->pop3($protocol)->getUniqueIds());
    }

    #[Test]
    public function listsNothingFromMalformedUidl(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willReturn('x');

        static::assertSame([], $this->pop3($protocol)->getUniqueIds());
    }

    #[Test]
    public function findsNumberByUniqueId(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willReturn([1 => 'abc', 2 => 'def']);

        static::assertSame(2, $this->pop3($protocol)->getNumberByUniqueId('def'));
    }

    #[Test]
    public function refusesUnknownUniqueId(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willReturn([1 => 'abc']);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Unique ID not found');

        $this->pop3($protocol)->getNumberByUniqueId('def');
    }

    #[Test]
    public function usesNumbersWithoutUidl(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willThrowException(new ProtocolException('UIDL not supported'));

        static::assertSame('2', $this->pop3($protocol)->getUniqueId(2));
    }

    #[Test]
    public function listsNumbersWithoutUidl(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willThrowException(new ProtocolException('UIDL not supported'));
        $protocol->method('status')
            ->willReturnCallback(static function (&$messages, &$octets): void {
                $messages = 2;
            });

        static::assertSame([1 => '1', 2 => '2'], $this->pop3($protocol)->getUniqueIds());
    }

    #[Test]
    public function knowsAfterAskingWhetherThereAreUniqueIds(): void
    {
        $protocol = $this->protocol();
        $protocol->method('uniqueid')->willThrowException(new ProtocolException('UIDL not supported'));
        $pop3 = $this->pop3($protocol);
        $pop3->getUniqueId(1);

        static::assertFalse($pop3->getCapabilities()['uniqueid']);
    }

    #[Test]
    public function doesNotKnowAtFirstWhetherThereAreUniqueIds(): void
    {
        static::assertNull($this->pop3()->getCapabilities()['uniqueid']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNumberProvider(): array
    {
        return [
            'getMessage'    => ['getMessage'],
            'getSize'       => ['getSize'],
            'getRawHeader'  => ['getRawHeader'],
            'getRawContent' => ['getRawContent'],
            'getUniqueId'   => ['getUniqueId'],
            'removeMessage' => ['removeMessage'],
        ];
    }
}
