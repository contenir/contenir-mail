<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2 as Encoder;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function count;
use function preg_quote;

/**
 * IMAP4rev2 (RFC 9051) and UTF-8 mailbox names (RFC 6855), negotiated after
 * signing in, and the ESEARCH and MOVE responses they bring
 * (contenir/contenir-mail#14).
 */
#[CoversClass(Imap::class)]
#[Group('unit')]
final class Imap4rev2Test extends TestCase
{
    /**
     * A server that lists $capabilities and accepts LOGIN; commands are tagged from TAG3.
     */
    private static function signedIn(string $capabilities): InMemoryConnection
    {
        return ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 {$capabilities}\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"secret\"\r\n")
            ->reply("TAG2 OK\r\n");
    }

    private static function login(InMemoryConnection $server): Imap
    {
        $imap = ScriptedServer::imap($server);
        $imap->login('jo', 'secret');

        return $imap;
    }

    #[Test]
    #[DataProvider('extensionProvider')]
    public function enablesUtf8MailboxesAfterSigningIn(string $capabilities, string $extension): void
    {
        $server = self::signedIn($capabilities)
            ->expect("TAG3 ENABLE {$extension}\r\n")
            ->reply("* ENABLED {$extension}\r\nTAG3 OK\r\n")
            ->expect("TAG4 CREATE \"R&D\"\r\n")
            ->reply("TAG4 OK\r\n")
            ->hangUp();
        $imap = self::login($server);

        static::assertSame([true, true], [$imap->hasUtf8Mailboxes(), $imap->create('R&D')]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function extensionProvider(): array
    {
        return [
            'IMAP4rev2'                    => ['IMAP4rev2 UTF8=ACCEPT', 'IMAP4rev2'],
            'UTF8=ACCEPT on a rev1 server' => ['UTF8=ACCEPT', 'UTF8=ACCEPT'],
        ];
    }

    #[Test]
    public function writesNamesInModifiedUtf7WithoutUtf8Mailboxes(): void
    {
        $server = self::signedIn('LITERAL+')
            ->expect("TAG3 CREATE \"Entw&APw-rfe\"\r\n")
            ->reply("TAG3 OK\r\n")
            ->expect("TAG4 LIST \"\" \"*\"\r\n")
            ->reply("* LIST () \"/\" \"Entw&APw-rfe\"\r\n* LIST () \"/\" \"R&-D\"\r\nTAG4 OK\r\n")
            ->hangUp();
        $imap = self::login($server);
        $imap->create('Entwürfe');

        static::assertSame([false, ['Entwürfe', 'R&D']], [$imap->hasUtf8Mailboxes(), array_keys($imap->listMailbox())]);
    }

    #[Test]
    public function readsNamesAsUtf8WithUtf8Mailboxes(): void
    {
        $server = self::signedIn('IMAP4rev2')
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4rev2\r\nTAG3 OK\r\n")
            ->expect("TAG4 LIST \"\" \"*\"\r\n")
            ->reply("* LIST () \"/\" \"R&-D\"\r\nTAG4 OK\r\n")
            ->hangUp();

        static::assertSame(['R&-D'], array_keys(self::login($server)->listMailbox()));
    }

    #[Test]
    public function staysWithModifiedUtf7WhenTheServerEnablesNothing(): void
    {
        $server = self::signedIn('IMAP4rev2')
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED\r\nTAG3 OK\r\n")
            ->hangUp();

        static::assertFalse(self::login($server)->hasUtf8Mailboxes());
    }

    #[Test]
    public function signsInAsRev1WhenTheServerRefusesEnable(): void
    {
        $server = self::signedIn('IMAP4rev2')
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("TAG3 BAD Unknown command\r\n")
            ->hangUp();

        static::assertFalse(self::login($server)->hasUtf8Mailboxes());
    }

    #[Test]
    public function enablesNothingWhenToldNotTo(): void
    {
        $server = self::signedIn('IMAP4rev2')
            ->expect("TAG3 CREATE \"Entw&APw-rfe\"\r\n")
            ->reply("TAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server)->useImap4Rev2(false);
        $imap->login('jo', 'secret');

        static::assertSame([true, false], [$imap->create('Entwürfe'), $imap->hasUtf8Mailboxes()]);
    }

    #[Test]
    public function asksForCapabilitiesOnceInAnyCase(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 MOVE\r\nTAG1 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        static::assertSame([true, true, false], [
            $imap->hasCapability('move'),
            $imap->hasCapability('MOVE'),
            $imap->hasCapability('IDLE'),
        ]);
    }

    #[Test]
    public function enablesNothingAfterARefusedLogin(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IMAP4rev2\r\nTAG1 OK\r\n")
            ->expect("TAG2 LOGIN \"jo\" \"wrong\"\r\n")
            ->reply("TAG2 NO [AUTHENTICATIONFAILED] Invalid\r\n")
            ->hangUp();

        static::assertFalse(ScriptedServer::imap($server)->login('jo', 'wrong'));
    }

    #[Test]
    public function enablesImap4Rev2AfterXoauth2(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 IMAP4rev2 SASL-IR AUTH=XOAUTH2\r\nTAG1 OK\r\n")
            ->expect('TAG2 AUTHENTICATE XOAUTH2 ' . Encoder::encodeXoauth2Sasl('jo@example.com', 'token') . "\r\n")
            ->reply("TAG2 OK\r\n")
            ->expect("TAG3 ENABLE IMAP4rev2\r\n")
            ->reply("* ENABLED IMAP4REV2\r\nTAG3 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);
        $imap->authenticate(new XOAuth2('jo@example.com', 'token'));

        static::assertTrue($imap->hasUtf8Mailboxes());
    }

    #[Test]
    public function reportsWhatTheServerEnabled(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 ENABLE CONDSTORE UTF8=ACCEPT\r\n")
            ->reply("* OK still here\r\n* ENABLED condstore\r\nTAG1 OK\r\n")
            ->hangUp();

        static::assertSame(['CONDSTORE'], ScriptedServer::imap($server)->enable('CONDSTORE', 'UTF8=ACCEPT'));
    }

    #[Test]
    public function reportsARefusedEnable(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 ENABLE IMAP4rev2\r\n")
            ->reply("TAG1 NO Not now\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server refused ENABLE');

        $imap->enable('IMAP4rev2');
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('esearchProvider')]
    public function readsAnEsearchResult(string $response, array $expected): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SEARCH UNSEEN\r\n")
            ->reply("{$response}TAG1 OK\r\n")
            ->hangUp();

        static::assertSame($expected, ScriptedServer::imap($server)->search(['UNSEEN']));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function esearchProvider(): array
    {
        return [
            'ranges and numbers' => ["* ESEARCH (TAG \"TAG1\") ALL 1:3,5\r\n", ['1', '2', '3', '5']],
            'by UID'             => ["* ESEARCH (TAG \"TAG1\") UID ALL 7\r\n", ['7']],
            'a reversed range'   => ["* ESEARCH (TAG \"TAG1\") ALL 4:2\r\n", ['2', '3', '4']],
            'in lower case'      => ["* esearch (TAG \"TAG1\") all 2\r\n", ['2']],
            'with a count first' => ["* ESEARCH (TAG \"TAG1\") COUNT 2 ALL 8,9\r\n", ['8', '9']],
            'no matches'         => ["* ESEARCH (TAG \"TAG1\") UID\r\n", []],
            'a SEARCH result'    => ["* SEARCH 4 6\r\n", ['4', '6']],
            'a set as a literal' => ["* ESEARCH (TAG \"TAG1\") ALL {3}\r\n2:3\r\n", ['2', '3']],
        ];
    }

    #[Test]
    #[DataProvider('badEsearchProvider')]
    public function refusesABadEsearchResult(string $set, string $message): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SEARCH ALL\r\n")
            ->reply("* ESEARCH (TAG \"TAG1\") ALL {$set}\r\nTAG1 OK\r\n")
            ->hangUp();
        $imap = ScriptedServer::imap($server);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/^' . preg_quote($message, delimiter: '/') . '$/D');

        $imap->search(['ALL']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function badEsearchProvider(): array
    {
        $tooMany = 'The server sent more than 1000000 search results';

        return [
            'a star'                  => ['1:*', 'The server sent a malformed search result'],
            'a line break at the end' => ["{2}\r\n1\n", 'The server sent a malformed search result'],
            'zero'                    => ['0', 'The server sent a malformed search result'],
            'a trailing comma'        => ['1,', 'The server sent a malformed search result'],
            'too long a number'       => ['12345678901', 'The server sent a malformed search result'],
            'one range too long'      => ['1:1000001', $tooMany],
            'ranges too long'         => ['1:600000,700001:1100001', $tooMany],
        ];
    }

    #[Test]
    public function readsAsManyResultsAsTheLimitAllows(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 SEARCH ALL\r\n")
            ->reply("* ESEARCH (TAG \"TAG1\") ALL 1:999999,1000000\r\nTAG1 OK\r\n")
            ->hangUp();

        $ids = ScriptedServer::imap($server)->search(['ALL']);

        static::assertSame([Imap::MAX_SEARCH_RESULTS, '1000000'], [
            count(false === $ids ? [] : $ids),
            $ids[Imap::MAX_SEARCH_RESULTS - 1] ?? null,
        ]);
    }

    #[Test]
    public function movesMessagesWhenTheServerOffersMove(): void
    {
        $server = ScriptedServer::imapGreeting()
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 MOVE\r\nTAG1 OK\r\n")
            ->expect("TAG2 MOVE 2:4 \"Archive\"\r\n")
            ->reply("* 2 EXPUNGE\r\nTAG2 OK\r\n")
            ->hangUp();

        static::assertTrue(ScriptedServer::imap($server)->move('Archive', 2, 4));
    }

    #[Test]
    public function refusesToMoveWithoutMove(): void
    {
        $imap = ScriptedServer::imap(
            ScriptedServer::imapGreeting()
                ->expect("TAG1 CAPABILITY\r\n")
                ->reply("* CAPABILITY IMAP4rev1\r\nTAG1 OK\r\n")
                ->hangUp(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The server does not offer MOVE');

        $imap->move('Archive', 2);
    }
}
