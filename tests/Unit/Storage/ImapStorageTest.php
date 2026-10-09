<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Protocol;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\CommandRefusedException;
use Contenir\Mail\Protocol\Exception\RuntimeException as ProtocolRuntimeException;
use Contenir\Mail\Protocol\Imap\UidMapping;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\OutOfBoundsException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\Imap;
use Contenir\Mail\Storage\ImapConfig;
use Contenir\Mail\Storage\ImapFlags;
use Contenir\Mail\Storage\ImapFolderTree;
use Contenir\Mail\Storage\Message;
use Contenir\Mail\Storage\Part;
use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Contenir\Mail\Storage\Part\MultipartSplitter;
use Contenir\Mail\Storage\RawMessage;
use Contenir\Mail\Storage\RemoteConnection;
use Contenir\Mail\Storage\RemoteFolder;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_map;
use function fopen;
use function fwrite;
use function iterator_to_array;
use function print_r;
use function rewind;

use const INF;

#[CoversClass(Imap::class)]
#[CoversClass(ImapConfig::class)]
#[CoversClass(ImapFlags::class)]
#[CoversClass(ImapFolderTree::class)]
#[CoversClass(RemoteConnection::class)]
#[CoversClass(RemoteFolder::class)]
#[CoversClass(RawMessage::class)]
#[CoversClass(Part::class)]
#[CoversClass(Message::class)]
#[CoversClass(Content::class)]
#[CoversClass(MimeParser::class)]
#[CoversClass(MultipartSplitter::class)]
#[Group('unit')]
final class ImapStorageTest extends TestCase
{
    /**
     * Not a real password: a value to look for where none should be.
     *
     * @mago-expect lint:no-literal-password A made-up value the tests look for, not a credential.
     */
    private const string PASSWORD = 'hunter2-secret';

    /** The protocol method behind each folder operation */
    private const array PROTOCOL_METHODS = [
        'createFolder' => 'create',
        'removeFolder' => 'delete',
        'renameFolder' => 'rename',
    ];

    private const string HEADER = "Subject: Hello\r\nFrom: a@example.com\r\n\r\n";

    /**
     * A protocol that is logged in and selects any folder.
     */
    private function protocol(): Protocol\Imap&MockObject
    {
        $protocol = $this->createMock(Protocol\Imap::class);
        $protocol->method('select')->willReturn([]);

        return $protocol;
    }

    private function imap(?Protocol\Imap $protocol = null): Imap
    {
        return new Imap($protocol ?? $this->protocol());
    }

    #[Test]
    public function selectsInboxOfConnectedProtocol(): void
    {
        static::assertSame('INBOX', $this->imap()->getCurrentFolder());
    }

    #[Test]
    public function refusesProtocolThatCannotSelectInbox(): void
    {
        $protocol = $this->createStub(Protocol\Imap::class);
        $protocol->method('select')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot select INBOX; is the protocol logged in?');
        $this->expectExceptionCode(0);

        new Imap($protocol);
    }

    #[Test]
    public function signsInWithScramSha256InsteadOfLogin(): void
    {
        $auth     = new ScramSha256('jo@example.com', 'secret');
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('authenticate')->with($auth);
        $protocol->expects($this->never())->method('login');

        static::assertSame('INBOX', (new Imap(['auth' => $auth], $protocol))->getCurrentFolder());
    }

    #[Test]
    public function signsInWithAnAccessTokenInsteadOfAPassword(): void
    {
        $auth     = new Xoauth2('jo@example.com', 'token');
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('authenticate')->with($auth);
        $protocol->expects($this->never())->method('login');

        static::assertSame('INBOX', (new Imap(['auth' => $auth], $protocol))->getCurrentFolder());
    }

    #[Test]
    public function connectsWithSettings(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('connect')
            ->with(new ConnectionConfig('imap.example.com', 993, Security::Tls, timeout: 5));
        $protocol->method('login')->willReturn(true);

        new Imap(
            ['host' => 'imap.example.com', 'port' => 993, 'security' => 'tls', 'timeout' => 5, 'user' => 'u'],
            $protocol,
        );
    }

    #[Test]
    public function connectsWithStartTlsByDefault(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('connect')
            ->with(new ConnectionConfig('imap.example.com', security: Security::StartTls));
        $protocol->method('login')->willReturn(true);

        new Imap(['host' => 'imap.example.com', 'user' => 'u'], $protocol);
    }

    #[Test]
    public function turnsOffPeerVerificationWhenAsked(): void
    {
        $config = new ConnectionConfig(
            security: Security::Tls,
            verifyPeer: false,
        );
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('connect')->with($config);
        $protocol->method('login')->willReturn(true);

        new Imap(new ImapConfig($config, 'u'), $protocol);
    }

    /**
     * Credentials must not cross the network in plain text when no security is configured.
     */
    #[Test]
    public function upgradesWithStartTlsBeforeLoggingIn(): void
    {
        $server = (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 STARTTLS LOGINDISABLED\r\nTAG1 OK\r\n")
            ->expect("TAG2 STARTTLS\r\n")
            ->reply("TAG2 OK begin TLS\r\n")
            ->startTls()
            ->expect("TAG3 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG3 OK\r\n")
            ->expect('TAG4 LOGIN "u" "' . self::PASSWORD . "\"\r\n")
            ->reply("TAG4 OK logged in\r\n")
            ->expect("TAG5 SELECT \"INBOX\"\r\n")
            ->reply("TAG5 OK selected\r\n")
            ->hangUp();

        new Imap(
            ['host' => 'imap.example.com', 'user' => 'u', 'password' => self::PASSWORD],
            new Protocol\Imap(connection: $server),
        );

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function logsIn(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('login')->with('u', self::PASSWORD)->willReturn(true);

        new Imap(['user' => 'u', 'password' => self::PASSWORD, 'security' => 'none'], $protocol);
    }

    #[Test]
    public function selectsConfiguredFolder(): void
    {
        $protocol = $this->protocol();
        $protocol->method('login')->willReturn(true);

        static::assertSame(
            'Archive',
            (new Imap(['user' => 'u', 'folder' => 'Archive'], $protocol))->getCurrentFolder(),
        );
    }

    #[Test]
    public function refusesWrongPassword(): void
    {
        $protocol = $this->protocol();
        $protocol->method('login')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot log in: the user or password is wrong');

        new Imap(['user' => 'u', 'password' => self::PASSWORD], $protocol);
    }

    /**
     * Credential leaks: the password is not in a failed login's exception or trace.
     */
    #[Test]
    public function keepsPasswordOutOfExceptions(): void
    {
        $protocol = $this->protocol();
        $protocol->method('login')->willReturn(false);
        try {
            new Imap(['user' => 'u', 'password' => self::PASSWORD], $protocol);
        } catch (RuntimeException $e) {
            static::assertStringNotContainsString(self::PASSWORD, $e->getMessage() . $e->getTraceAsString());

            return;
        }

        static::fail('A failed login must throw');
    }

    #[Test]
    public function keepsPasswordOutOfDumps(): void
    {
        static::assertStringNotContainsString(self::PASSWORD, print_r(ImapConfig::fromIterable([
            'user'     => 'u',
            'password' => self::PASSWORD,
        ]), return: true));
    }

    #[Test]
    public function logsOutWhenClosed(): void
    {
        $logouts  = 0;
        $protocol = $this->protocol();
        $protocol->method('logout')
            ->willReturnCallback(static function () use (&$logouts): bool {
                $logouts++;

                return true;
            });
        $imap = $this->imap($protocol);
        $imap->close();
        $imap->close();

        static::assertSame(1, $logouts);
    }

    #[Test]
    public function logsOutWhenConnectedStorageIsDestroyed(): void
    {
        $protocol = $this->protocol();
        $protocol->method('login')->willReturn(true);
        $protocol->expects($this->once())->method('logout');
        $imap = new Imap(['user' => 'u'], $protocol);
        unset($imap);
    }

    #[Test]
    public function logsOutWhenDestroyed(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('logout');
        $imap = $this->imap($protocol);
        unset($imap);
    }

    #[Test]
    public function hasNoFolderOnceClosed(): void
    {
        $imap = $this->imap();
        $imap->close();

        static::assertSame('', $imap->getCurrentFolder());
    }

    #[Test]
    public function countsMessages(): void
    {
        $protocol = $this->protocol();
        $protocol->method('searchCount')->with(['ALL'])->willReturn(3);

        static::assertSame(3, $this->imap($protocol)->countMessages());
    }

    #[Test]
    public function reportsRefusedSearch(): void
    {
        $protocol = $this->protocol();
        $protocol->method('searchCount')
            ->willThrowException(new ProtocolRuntimeException('The server refused the search'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server refused the search');

        $this->imap($protocol)->countMessages();
    }

    #[DataProvider('searchProvider')]
    #[Test]
    public function countsMessagesWithFlags(array $flags, array $criteria): void
    {
        $protocol = $this->protocol();
        $protocol->method('escapeString')->willReturnCallback(static fn(string $text): string => "\"{$text}\"");
        $protocol->expects($this->once())->method('searchCount')->with($criteria)->willReturn(1);

        $this->imap($protocol)->countMessages(...$flags);
    }

    /**
     * Command injection: a keyword that is not an IMAP atom is refused before it reaches the server.
     */
    #[DataProvider('injectedFlagProvider')]
    #[Test]
    public function refusesFlagThatIsNotAnAtom(string $flag): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid IMAP flag');

        $this->imap()->countMessages($flag);
    }

    #[Test]
    public function sendsEmptyKeywordWhenTheProtocolCannotQuoteIt(): void
    {
        $protocol = $this->protocol();
        $protocol->method('escapeString')->willReturn(['x']);
        $protocol->expects($this->once())->method('searchCount')->with(['KEYWORD', ''])->willReturn(0);

        $this->imap($protocol)->countMessages('$Junk');
    }

    #[Test]
    public function connectsWithoutSecurityWhenAsked(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('connect')->with(new ConnectionConfig(security: Security::None));
        $protocol->method('login')->willReturn(true);

        new Imap(['user' => 'u', 'security' => 'none'], $protocol);
    }

    #[Test]
    public function refusesToCountWithoutFolder(): void
    {
        $imap = $this->imap();
        $imap->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No folder is selected');

        $imap->countMessages();
    }

    #[Test]
    public function readsMessageHeadersAndFlags(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')
            ->with(['FLAGS', 'RFC822.HEADER'], 3)
            ->willReturn([
                'FLAGS'         => ['\Seen', '$Junk'],
                'RFC822.HEADER' => self::HEADER,
            ]);
        $message = $this->imap($protocol)->getMessage(3);

        static::assertSame(['Hello', [Flag::Seen, '$Junk']], [$message->getSubject(), $message->getFlags()]);
    }

    #[Test]
    public function fetchesBodyOnlyWhenRead(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnMap([
                [['FLAGS', 'RFC822.HEADER'], 3, null, false, ['FLAGS' => [], 'RFC822.HEADER' => self::HEADER]],
                ['RFC822.TEXT', 3, null, false, 'body'],
            ]);
        $message = $this->imap($protocol)->getMessage(3);
        $message->getContent();

        static::assertSame('body', $message->getContent());
    }

    #[Test]
    public function readsMalformedFetchAsEmpty(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->willReturn('not an array');

        static::assertSame([], $this->imap($protocol)->getMessage(1)->getFlags());
    }

    #[Test]
    public function readsUntypedFlagsSafely(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->willReturn(['FLAGS' => [['nested']], 'RFC822.HEADER' => ['x']]);

        static::assertSame([''], $this->imap($protocol)->getMessage(1)->getFlags());
    }

    #[DataProvider('invalidNumberProvider')]
    #[Test]
    public function refusesMessageNumberBelowOne(string $method): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('There is no message 0');

        $this->imap()->{$method}(0);
    }

    #[DataProvider('fetchTextProvider')]
    #[Test]
    public function fetchesOneItem(string $method, string $item, string|int $expected): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->with($item, 2)->willReturn('42');

        static::assertSame($expected, $this->imap($protocol)->{$method}(2));
    }

    #[Test]
    public function readsNonTextItemAsEmpty(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->willReturn([]);

        static::assertSame('', $this->imap($protocol)->getRawContent(1));
    }

    #[Test]
    public function measuresEveryMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->with('RFC822.SIZE', 1, INF)->willReturn([1 => '10', 2 => '20']);

        static::assertSame([1 => 10, 2 => 20], $this->imap($protocol)->getSizes());
    }

    #[Test]
    public function listsUniqueIds(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')
            ->with('UID', 1, INF)
            ->willReturn([1 => 7, '2' => '9', 3 => ['x']]);

        static::assertSame([1 => '7', 2 => '9', 3 => ''], $this->imap($protocol)->getUniqueIds());
    }

    #[Test]
    public function listsNothingFromMalformedFetch(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->willReturn('x');

        static::assertSame([], $this->imap($protocol)->getUniqueIds());
    }

    #[Test]
    public function findsNumberByUniqueId(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->willReturn([1 => '7', 2 => '9']);

        static::assertSame(2, $this->imap($protocol)->getNumberByUniqueId('9'));
    }

    #[Test]
    public function refusesUnknownUniqueId(): void
    {
        $protocol = $this->protocol();
        $protocol->method('fetch')->willReturn([1 => '7']);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Unique ID not found');

        $this->imap($protocol)->getNumberByUniqueId('9');
    }

    #[Test]
    public function removesMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('store')->with(['\Deleted'], 4, null, '+')->willReturn(true);
        $protocol->expects($this->once())->method('expunge')->willReturn(true);

        $this->imap($protocol)->removeMessage(4);
    }

    #[Test]
    public function reportsFlagThatCannotBeStoredOnRemoval(): void
    {
        $protocol = $this->protocol();
        $protocol->method('store')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot set the Deleted flag');

        $this->imap($protocol)->removeMessage(4);
    }

    #[Test]
    public function reportsFailedExpunge(): void
    {
        $protocol = $this->protocol();
        $protocol->method('store')->willReturn(true);
        $protocol->method('expunge')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The message is flagged deleted, but could not be expunged');

        $this->imap($protocol)->removeMessage(4);
    }

    #[Test]
    public function keepsTheConnectionAlive(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('noop')->willReturn([]);

        $this->imap($protocol)->noop();
    }

    #[Test]
    public function reportsFailedNoop(): void
    {
        $protocol = $this->protocol();
        $protocol->method('noop')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server did not answer NOOP');

        $this->imap($protocol)->noop();
    }

    #[Test]
    public function buildsFolderTree(): void
    {
        $protocol = $this->protocol();
        $protocol->method('listMailbox')
            ->willReturn([
                'INBOX'          => ['delim' => '/', 'flags' => []],
                'Archive/2024'   => ['delim' => '/', 'flags' => []],
                'Archive'        => ['delim' => '/', 'flags' => ['\Noselect']],
                'Lists/announce' => ['delim' => '/', 'flags' => []],
            ]);
        $tree = new RecursiveIteratorIterator(
            $this->imap($protocol)->getFolders(),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        static::assertSame(
            ['Archive:0', 'Archive/2024:1', 'INBOX:1', 'Lists:0', 'Lists/announce:1'],
            array_map(
                static fn(Folder $folder): string => $folder->getGlobalName() . ':' . (int) $folder->isSelectable(),
                iterator_to_array($tree, preserve_keys: false),
            ),
        );
    }

    /**
     * IMAP4rev2 lists a parent that holds no messages as \\NonExistent (RFC 9051).
     */
    #[Test]
    public function cannotSelectAFolderListedAsNonExistent(): void
    {
        $protocol = $this->protocol();
        $protocol->method('listMailbox')
            ->willReturn([
                'Archive'      => ['delim' => '/', 'flags' => ['\\NonExistent', '\\HasChildren']],
                'Archive/2024' => ['delim' => '/', 'flags' => ['\\HasNoChildren']],
            ]);
        $archive = $this->imap($protocol)->getFolders()->getFolder('Archive');

        static::assertSame([false, true], [$archive->isSelectable(), $archive->getFolder('2024')->isSelectable()]);
    }

    #[Test]
    public function hasRootThatCannotBeSelected(): void
    {
        $protocol = $this->protocol();
        $protocol->method('listMailbox')->willReturn(['INBOX' => ['delim' => '/', 'flags' => []]]);

        static::assertFalse($this->imap($protocol)->getFolders()->isSelectable());
    }

    #[Test]
    public function readsFolderWithoutDelimiter(): void
    {
        $protocol = $this->protocol();
        $protocol->method('listMailbox')->willReturn(['a/b' => ['delim' => '', 'flags' => 'x'], 'c' => 'malformed']);

        static::assertTrue($this->imap($protocol)->getFolders()->getFolder('a/b')->isSelectable());
    }

    #[Test]
    public function refusesFolderTheServerDoesNotList(): void
    {
        $protocol = $this->protocol();
        $protocol->method('listMailbox')->willReturn([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Folder not found');

        $this->imap($protocol)->getFolders('Nothing');
    }

    #[Test]
    public function asksForDelimiterOnce(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('listMailbox')
            ->willReturn(['INBOX' => ['delim' => '.', 'flags' => []]]);
        $imap = $this->imap($protocol);
        $imap->delimiter();

        static::assertSame('.', $imap->delimiter());
    }

    #[Test]
    public function reportsFolderThatCannotBeSelected(): void
    {
        $protocol = $this->createStub(Protocol\Imap::class);
        $protocol->method('select')->willReturnMap([['INBOX', ['exists' => '1']], ['Missing', false]]);
        $imap = new Imap($protocol);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot select the folder; it may not exist');

        $imap->selectFolder('Missing');
    }

    /**
     * Command injection: a folder name with a line break never reaches the server.
     */
    #[DataProvider('injectedFolderProvider')]
    #[Test]
    public function refusesFolderNameThatCouldEndTheCommand(string $name): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->never())->method('create');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A folder name may not be empty or hold a line break or NUL');

        $this->imap($protocol)->createFolder($name);
    }

    #[Test]
    public function createsFolderInParentWithTheServersDelimiter(): void
    {
        $protocol = $this->protocol();
        $protocol->method('listMailbox')->willReturn(['INBOX' => ['delim' => '.', 'flags' => []]]);
        $protocol->expects($this->once())->method('create')->with('Archive.2024')->willReturn(true);

        $this->imap($protocol)->createFolder('2024', 'Archive');
    }

    #[DataProvider('folderOperationProvider')]
    #[Test]
    public function reportsRefusedFolderOperation(string $method, array $arguments, string $message): void
    {
        $protocol = $this->protocol();
        $protocol->method(self::PROTOCOL_METHODS[$method] ?? '')
            ->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->imap($protocol)->{$method}(...$arguments);
    }

    #[DataProvider('folderOperationProvider')]
    #[Test]
    public function performsFolderOperation(string $method, array $arguments): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method(self::PROTOCOL_METHODS[$method] ?? '')
            ->willReturn(true);

        $this->imap($protocol)->{$method}(...$arguments);
    }

    #[Test]
    public function appendsMessageAsSeenToCurrentFolder(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('appendReturningUids')
            ->with('INBOX', "Subject: x\r\n\r\nx", ['\Seen'])
            ->willReturn(null);

        static::assertNull($this->imap($protocol)->appendMessage("Subject: x\r\n\r\nx"));
    }

    #[Test]
    public function returnsTheUidTheServerGaveTheAppendedMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->method('appendReturningUids')->willReturn(new UidMapping(38_505, [], [3955]));

        static::assertSame(3955, $this->imap($protocol)->appendMessage('x'));
    }

    #[Test]
    public function appendsStoredMessageWithFlags(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('appendReturningUids')
            ->with('Sent', "Subject: x\r\n\r\nx", ['\Draft', '$Forwarded', '$Junk'])
            ->willReturn(null);

        static::assertNull($this->imap($protocol)->appendMessage(
            Message::fromString("Subject: x\r\n\r\nx"),
            'Sent',
            [Flag::Draft, Flag::Passed, '$Junk'],
        ));
    }

    #[Test]
    public function appendsStream(): void
    {
        $stream = fopen('php://memory', mode: 'w+b');
        fwrite($stream, data: 'raw');
        rewind($stream);
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('appendReturningUids')
            ->with('INBOX', 'raw', ['\Seen'])
            ->willReturn(null);

        static::assertNull($this->imap($protocol)->appendMessage($stream));
    }

    #[Test]
    public function refusesToAppendWithRecent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The Recent flag may not be set');

        $this->imap()->appendMessage('x', flags: ['\Recent']);
    }

    /**
     * @param 'appendReturningUids'|'copyReturningUids'|'moveReturningUids' $protocolMethod
     * @param callable(Imap): mixed $storageCall
     */
    #[DataProvider('refusedProvider')]
    #[Test]
    public function reportsARefusalAsAStorageError(
        string $protocolMethod,
        callable $storageCall,
        string $message,
    ): void {
        $refusal  = new CommandRefusedException('The server refused it');
        $protocol = $this->protocol();
        $protocol->method('hasCapability')->willReturn(true);
        $protocol->method($protocolMethod)->willThrowException($refusal);

        try {
            $storageCall($this->imap($protocol));
        } catch (RuntimeException $exception) {
            static::assertSame([$message, $refusal], [$exception->getMessage(), $exception->getPrevious()]);

            return;
        }

        static::fail('The refusal was not reported');
    }

    /**
     * @return array<string, array{string, callable(Imap): mixed, string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'append' => [
                'appendReturningUids',
                static fn(Imap $imap): ?int => $imap->appendMessage('x'),
                'Cannot store the message; check that the folder exists and the flags',
            ],
            'copy'   => [
                'copyReturningUids',
                static fn(Imap $imap): ?int => $imap->copyMessage(2, 'Archive'),
                'Cannot copy the message; does the folder exist?',
            ],
            'move'   => [
                'moveReturningUids',
                static fn(Imap $imap): ?int => $imap->moveMessage(2, 'Archive'),
                'Cannot move the message; does the folder exist?',
            ],
        ];
    }

    #[Test]
    public function leavesAFailedConnectionAsAProtocolError(): void
    {
        $protocol = $this->protocol();
        $protocol->method('appendReturningUids')->willThrowException(new ProtocolRuntimeException('Connection lost'));

        $this->expectException(ProtocolRuntimeException::class);
        $this->expectExceptionMessage('Connection lost');

        $this->imap($protocol)->appendMessage('x');
    }

    #[Test]
    public function copiesMessage(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('copyReturningUids')->with('Archive', 2)->willReturn(null);

        static::assertNull($this->imap($protocol)->copyMessage(2, 'Archive'));
    }

    #[DataProvider('copyUidProvider')]
    #[Test]
    public function returnsTheUidTheServerGaveTheCopy(UidMapping $uids, ?int $uid): void
    {
        $protocol = $this->protocol();
        $protocol->method('copyReturningUids')->willReturn($uids);

        static::assertSame($uid, $this->imap($protocol)->copyMessage(2, 'Archive'));
    }

    /**
     * @return array<string, array{UidMapping, int|null}>
     */
    public static function copyUidProvider(): array
    {
        return [
            'one copy'                => [new UidMapping(38_505, [304], [3956]), 3956],
            'several copies reported' => [new UidMapping(38_505, [304, 305], [3956, 3957]), null],
        ];
    }

    #[Test]
    public function movesMessageByCopyingAndRemoving(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())
            ->method('copyReturningUids')
            ->with('Archive', 2)
            ->willReturn(new UidMapping(38_505, [304], [3956]));
        $protocol->method('store')->willReturn(true);
        $protocol->expects($this->once())->method('expunge')->willReturn(true);

        static::assertSame(3956, $this->imap($protocol)->moveMessage(2, 'Archive'));
    }

    #[Test]
    public function movesMessageWithMoveWhenTheServerOffersIt(): void
    {
        $protocol = $this->protocol();
        $protocol->method('hasCapability')->with('MOVE')->willReturn(true);
        $protocol->expects($this->once())
            ->method('moveReturningUids')
            ->with('Archive', 2)
            ->willReturn(new UidMapping(38_505, [304], [3956]));
        $protocol->expects($this->never())->method('copyReturningUids');

        static::assertSame(3956, $this->imap($protocol)->moveMessage(2, 'Archive'));
    }

    #[Test]
    public function movesMessageWithoutAUidWhenTheServerSendsNone(): void
    {
        $protocol = $this->protocol();
        $protocol->method('hasCapability')->willReturn(true);
        $protocol->method('moveReturningUids')->willReturn(null);

        static::assertNull($this->imap($protocol)->moveMessage(2, 'Archive'));
    }

    #[Test]
    public function setsFlags(): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->once())->method('store')->with(['\Seen', '\Answered'], 2)->willReturn(true);

        $this->imap($protocol)->setFlags(2, [Flag::Seen, '\answered']);
    }

    #[Test]
    public function reportsRefusedFlags(): void
    {
        $protocol = $this->protocol();
        $protocol->method('store')->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot set the flags');

        $this->imap($protocol)->setFlags(2, [Flag::Seen]);
    }

    /**
     * A storage whose server accepts SELECT, then expects $request and answers $response.
     */
    private static function scriptedImap(string $request, string $response): Imap
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("TAG1 OK selected\r\n")
            ->expect($request)
            ->reply($response)
            ->hangUp();

        return new Imap(ScriptedServer::imap($server));
    }

    /**
     * @return array<string, array{string, Flag|string, string}>
     */
    public static function flagChangeProvider(): array
    {
        return [
            'add an enum'     => ['addFlags', Flag::Seen, "TAG2 STORE 1 +FLAGS.SILENT (\\Seen)\r\n"],
            'add a string'    => ['addFlags', '\answered', "TAG2 STORE 1 +FLAGS.SILENT (\\Answered)\r\n"],
            'remove an enum'  => ['removeFlags', Flag::Seen, "TAG2 STORE 1 -FLAGS.SILENT (\\Seen)\r\n"],
            'remove a string' => ['removeFlags', '\flagged', "TAG2 STORE 1 -FLAGS.SILENT (\\Flagged)\r\n"],
        ];
    }

    #[Test]
    #[DataProvider('flagChangeProvider')]
    public function changesSingleFlags(string $method, Flag|string $flag, string $command): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SELECT \"INBOX\"\r\n")
            ->reply("TAG1 OK selected\r\n")
            ->expect($command)
            ->reply("TAG2 OK done\r\n")
            ->hangUp();
        $imap = new Imap(ScriptedServer::imap($server));

        $imap->$method(1, [$flag]);

        static::assertTrue($server->isScriptComplete());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedFlagChangeProvider(): array
    {
        return [
            'add'    => ['addFlags', '+', 'Cannot add the flags'],
            'remove' => ['removeFlags', '-', 'Cannot remove the flags'],
        ];
    }

    #[Test]
    #[DataProvider('refusedFlagChangeProvider')]
    public function reportsRefusedFlagChange(string $method, string $mode, string $message): void
    {
        $imap = self::scriptedImap("TAG2 STORE 1 {$mode}FLAGS.SILENT (\\Seen)\r\n", "TAG2 NO refused\r\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $imap->$method(1, [Flag::Seen]);
    }

    #[Test]
    #[DataProvider('flagMethodProvider')]
    public function refusesInvalidFlagChange(string $method): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->never())->method('store');
        $imap = $this->imap($protocol);

        $this->expectException(InvalidArgumentException::class);

        $imap->$method(1, [Flag::Recent]);
    }

    #[Test]
    #[DataProvider('flagMethodProvider')]
    public function refusesFlagChangeBelowOne(string $method): void
    {
        $protocol = $this->protocol();
        $protocol->expects($this->never())->method('store');
        $imap = $this->imap($protocol);

        $this->expectException(OutOfBoundsException::class);

        $imap->$method(0, [Flag::Seen]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function flagMethodProvider(): array
    {
        return ['add' => ['addFlags'], 'remove' => ['removeFlags']];
    }

    #[Test]
    public function hasFlagsAndFolders(): void
    {
        $capabilities = $this->imap()->getCapabilities();

        static::assertSame([true, true, true], [
            $capabilities['flags'],
            $capabilities['create'],
            $capabilities['delete'],
        ]);
    }

    /**
     * @return array<string, array{list<Flag|string>, list<string>}>
     */
    public static function searchProvider(): array
    {
        return [
            'seen'      => [[Flag::Seen], ['SEEN']],
            'every key' => [
                [Flag::Answered, Flag::Flagged, Flag::Deleted, Flag::Draft, Flag::Recent],
                ['ANSWERED',     'FLAGGED',     'DELETED',     'DRAFT',     'RECENT'],
            ],
            'passed'    => [[Flag::Passed], ['KEYWORD', '"$Forwarded"']],
            'keyword'   => [['$Junk'], ['KEYWORD', '"$Junk"']],
            'by name'   => [['\seen', '$Junk'], ['SEEN', 'KEYWORD', '"$Junk"']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedFlagProvider(): array
    {
        return [
            'line break'  => ["\$Junk\r\nA1 LOGOUT"],
            'space'       => ['$Junk ALL'],
            'parenthesis' => ['$Junk)'],
            'quote'       => ['$Ju"nk'],
            'wildcard'    => ['$Junk*'],
            'empty'       => [''],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedFolderProvider(): array
    {
        return [
            'CRLF'  => ["Archive\r\nA1 DELETE INBOX"],
            'LF'    => ["Archive\nx"],
            'NUL'   => ["Archive\0"],
            'empty' => [''],
        ];
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

    /**
     * @return array<string, array{string, string, string|int}>
     */
    public static function fetchTextProvider(): array
    {
        return [
            'size'        => ['getSize', 'RFC822.SIZE', 42],
            'raw header'  => ['getRawHeader', 'RFC822.HEADER', '42'],
            'raw content' => ['getRawContent', 'RFC822.TEXT', '42'],
            'unique id'   => ['getUniqueId', 'UID', '42'],
        ];
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function folderOperationProvider(): array
    {
        return [
            'create' => ['createFolder', ['Archive'], 'Cannot create the folder'],
            'remove' => ['removeFolder', ['Archive'], 'Cannot delete the folder'],
            'rename' => ['renameFolder', ['Archive', 'Old'], 'Cannot rename the folder'],
        ];
    }
}
