<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Exception\InvalidArgumentException as MailInvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\LogicException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Imap;
use Contenir\Mail\Protocol\ResponseLimits;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function serialize;
use function sprintf;
use function str_repeat;
use function strlen;
use function unserialize;

#[CoversClass(Imap::class)]
#[Group('unit')]
final class ConnectTest extends TestCase
{
    /**
     * @return array{string, int, Security}
     */
    private static function opened(InMemoryConnection $server): array
    {
        $config = $server->openedWith();
        static::assertNotNull($config);

        return [$config->host, (int) $server->openedPort(), $config->security];
    }

    /**
     * @param array{string, int, Security} $expected
     */
    #[DataProvider('legacyArgumentProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function readsTheLaminasPositionalArguments(?int $port, string|bool|Security $ssl, array $expected): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();

        new Imap('imap.example.com', $port, $ssl, false, $server);

        static::assertSame($expected, self::opened($server));
    }

    /**
     * @return array<string, array{int|null, string|bool|Security, array{string, int, Security}}>
     */
    public static function legacyArgumentProvider(): array
    {
        return [
            'plain on the default port' => [null, false, ['imap.example.com', 143, Security::None]],
            'port zero as the default'  => [0, false, ['imap.example.com', 143, Security::None]],
            'plain on a given port'     => [1143, false, ['imap.example.com', 1143, Security::None]],
            'ssl on the default port'   => [null, 'ssl', ['imap.example.com', 993, Security::Tls]],
            'SSL in capitals'           => [null, 'SSL', ['imap.example.com', 993, Security::Tls]],
            'ssl on a given port'       => [1993, 'ssl', ['imap.example.com', 1993, Security::Tls]],
            'none as a string'          => [null, 'none', ['imap.example.com', 143, Security::None]],
            'empty string as plain'     => [null, '', ['imap.example.com', 143, Security::None]],
            'a Security case'           => [null, Security::Tls, ['imap.example.com', 993, Security::Tls]],
        ];
    }

    #[IgnoreDeprecations]
    #[Test]
    public function usesStartTlsWhenNoSecurityIsGiven(): void
    {
        $server = $this->startTlsServer()->hangUp();

        new Imap('imap.example.com', connection: $server);

        static::assertTrue($server->isTlsEnabled());
    }

    #[DataProvider('startTlsSettingProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function usesStartTlsForItsLegacyNames(string $ssl): void
    {
        $server = $this->startTlsServer()->hangUp();

        new Imap('imap.example.com', null, $ssl, false, $server);

        static::assertTrue($server->isTlsEnabled());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function startTlsSettingProvider(): array
    {
        return [
            'laminas tls'     => ['tls'],
            'starttls'        => ['starttls'],
            'TLS in capitals' => ['TLS'],
        ];
    }

    #[DataProvider('unknownSecurityProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function refusesSecuritySettingsThatWouldSilentlyMeanPlainText(string|bool $ssl): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown connection security');

        new Imap('imap.example.com', null, $ssl, false, new InMemoryConnection());
    }

    /**
     * @return array<string, array{string|bool}>
     */
    public static function unknownSecurityProvider(): array
    {
        return [
            'true'          => [true],
            'misspelt'      => ['tsl'],
            'other setting' => ['yes'],
        ];
    }

    #[IgnoreDeprecations]
    #[Test]
    public function verifiesTheCertificateByDefault(): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();

        new Imap('imap.example.com', null, false, false, $server);

        static::assertTrue($server->openedWith()?->verifyPeer);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function verifiesTheCertificateWhenNotToldOtherwise(): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();

        new Imap('imap.example.com', null, false, connection: $server);

        static::assertTrue($server->openedWith()?->verifyPeer);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function skipsCertificateVerificationOnlyWhenAsked(): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();

        new Imap('imap.example.com', null, false, true, $server);

        static::assertFalse($server->openedWith()?->verifyPeer);
    }

    #[Test]
    public function connectsWithAConnectionConfigGivenToTheConstructor(): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();
        $config = new ConnectionConfig(
            host: 'imap.example.com',
            port: 2143,
            security: Security::None,
            timeout: 5,
        );

        new Imap($config, connection: $server);

        static::assertSame($config, $server->openedWith());
    }

    #[Test]
    public function takesCertificateVerificationFromTheConnectionConfig(): void
    {
        $imap = new Imap(connection: ScriptedServer::imapGreeting()->hangUp());

        $imap->connect(new ConnectionConfig(
            host: 'imap.example.com',
            security: Security::None,
            verifyPeer: false,
        ));

        static::assertFalse($imap->validateCert());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function usesTheProtocolTimeoutForLegacyArguments(): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();

        new Imap('imap.example.com', null, false, false, $server);

        static::assertSame(Imap::TIMEOUT_CONNECTION, $server->openedWith()?->timeout);
    }

    #[Test]
    public function doesNotConnectWithoutAHost(): void
    {
        $server = new InMemoryConnection();

        new Imap(connection: $server);

        static::assertNull($server->openedWith());
    }

    #[Test]
    public function reportsStartTlsAsTheSecurityBeforeConnecting(): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        static::assertSame(Security::StartTls, $imap->getConnectionConfig()->security);
    }

    #[Test]
    public function reportsTheSettingsItConnectedWith(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->hangUp());

        static::assertEquals(ScriptedServer::plain(), $imap->getConnectionConfig());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesAPortOutOfRange(): void
    {
        $this->expectException(MailInvalidArgumentException::class);
        $this->expectExceptionMessage('Port 70000 is out of range');

        new Imap('imap.example.com', 70_000, false, false, new InMemoryConnection());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function failsWhenTheServerRefusesTheConnection(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to imap.example.com:143: connection refused');

        new Imap('imap.example.com', null, false, false, (new InMemoryConnection())->refuse());
    }

    #[DataProvider('unwelcomeGreetingProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function failsWhenTheServerDoesNotGreetWithOk(string $greeting): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('host doesn\'t allow connection');

        new Imap(
            'imap.example.com',
            null,
            false,
            false,
            (new InMemoryConnection())->reply($greeting)
                ->hangUp(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unwelcomeGreetingProvider(): array
    {
        return [
            'BYE'                       => ["* BYE go away\r\n"],
            'PREAUTH, which skips TLS'  => ["* PREAUTH welcome\r\n"],
            'OK without the untagged *' => ["OK hello\r\n"],
        ];
    }

    #[IgnoreDeprecations]
    #[Test]
    public function upgradesWithStartTlsBeforeAnyOtherCommand(): void
    {
        $server = $this->startTlsServer()
            ->expect("TAG3 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1\r\nTAG3 OK\r\n")
            ->expect("TAG4 LOGIN \"user\" \"secret\"\r\n")
            ->reply("TAG4 OK logged in\r\n")
            ->hangUp();
        $imap = new Imap('imap.example.com', connection: $server);

        static::assertTrue($imap->login('user', 'secret'));
    }

    #[IgnoreDeprecations]
    #[Test]
    public function acceptsStartTlsAdvertisedInLowerCase(): void
    {
        $server = (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY imap4rev1 starttls\r\nTAG1 OK\r\n")
            ->expect("TAG2 STARTTLS\r\n")
            ->reply("TAG2 OK begin\r\n")
            ->startTls()
            ->hangUp();

        new Imap('imap.example.com', connection: $server);

        static::assertTrue($server->isTlsEnabled());
    }

    #[DataProvider('startTlsNotOfferedProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function refusesToContinueInPlainTextWhenStartTlsIsNotOffered(string $capabilityResponse): void
    {
        $server = (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply($capabilityResponse)
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the server does not offer STARTTLS; refusing to continue in plain text');

        new Imap('imap.example.com', connection: $server);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function startTlsNotOfferedProvider(): array
    {
        return [
            'not advertised'         => ["* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\nTAG1 OK\r\n"],
            'advertised in a list'   => ["* CAPABILITY IMAP4rev1 (STARTTLS)\r\nTAG1 OK\r\n"],
            'CAPABILITY refused'     => ["TAG1 NO not now\r\n"],
            'CAPABILITY rejected'    => ["TAG1 BAD unknown\r\n"],
            'no untagged CAPABILITY' => ["TAG1 OK\r\n"],
        ];
    }

    #[DataProvider('startTlsRefusedProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function refusesToContinueInPlainTextWhenTheServerRefusesStartTls(string $reply): void
    {
        $server = (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 STARTTLS\r\nTAG1 OK\r\n")
            ->expect("TAG2 STARTTLS\r\n")
            ->reply($reply)
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot enable TLS: the server refused STARTTLS');

        new Imap('imap.example.com', connection: $server);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function startTlsRefusedProvider(): array
    {
        return [
            'NO'                  => ["TAG2 NO not now\r\n"],
            'BAD'                 => ["TAG2 BAD unknown command\r\n"],
            'OK with extra lines' => ["* OK maybe\r\nTAG2 OK begin\r\n"],
        ];
    }

    #[IgnoreDeprecations]
    #[Test]
    public function failsWhenTheTlsHandshakeFails(): void
    {
        $server = (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 STARTTLS\r\nTAG1 OK\r\n")
            ->expect("TAG2 STARTTLS\r\n")
            ->reply("TAG2 OK begin\r\n")
            ->failTls('certificate verify failed')
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot enable TLS: certificate verify failed');

        new Imap('imap.example.com', connection: $server);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesResponsesInjectedBeforeTheTlsHandshake(): void
    {
        $server = (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 STARTTLS\r\nTAG1 OK\r\n")
            ->expect("TAG2 STARTTLS\r\n")
            ->reply("TAG2 OK begin\r\nTAG3 OK LOGIN completed\r\n")
            ->startTls()
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sent data before TLS was negotiated');

        new Imap('imap.example.com', connection: $server);
    }

    #[Test]
    public function countsUnsolicitedLinesAfterTheGreetingTowardsItsLimit(): void
    {
        $greeting = '* OK ' . str_repeat('a', times: 1000) . "\r\n";
        $server   = (new InMemoryConnection())->reply($greeting . '* ' . str_repeat('b', times: 14) . "\r\n")
            ->hangUp();
        $imap = new Imap(connection: $server);
        $imap->setResponseLimits(new ResponseLimits(1024, 1024));
        $imap->connect(ScriptedServer::plain());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("The server's response exceeds the limit of 1024 bytes");

        $imap->readLine();
    }

    #[Test]
    public function startsCountingAgainWhenReconnecting(): void
    {
        $greeting = '* OK ' . str_repeat('a', times: 1000) . "\r\n";
        $server   = (new InMemoryConnection())->reply($greeting . $greeting)
            ->hangUp();
        $imap = new Imap(connection: $server);
        $imap->setResponseLimits(new ResponseLimits(1024, 1024));
        $imap->connect(ScriptedServer::plain());
        $imap->connect(ScriptedServer::plain());

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function logsOutWhenDestroyed(): void
    {
        $server = ScriptedServer::imapGreeting()->expect("TAG1 LOGOUT\r\n")->reply("* BYE\r\nTAG1 OK\r\n");
        $imap   = ScriptedServer::imap($server);

        unset($imap);

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function sendsNothingWhenDestroyedWithoutHavingConnected(): void
    {
        $server = new InMemoryConnection();
        $imap   = new Imap(connection: $server);

        unset($imap);

        static::assertSame('', $server->written());
    }

    #[Test]
    public function logoutClosesTheConnection(): void
    {
        $server = ScriptedServer::imapGreeting()->expect("TAG1 LOGOUT\r\n")->reply("TAG1 OK\r\n");
        $imap   = ScriptedServer::imap($server);

        $imap->logout();

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function logoutReportsSuccess(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->expect("TAG1 LOGOUT\r\n")->reply("TAG1 OK\r\n"));

        static::assertTrue($imap->logout());
    }

    #[Test]
    public function logoutReportsARefusal(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->expect("TAG1 LOGOUT\r\n")->reply("TAG1 NO\r\n"));

        static::assertFalse($imap->logout());
    }

    #[Test]
    public function logoutClosesTheConnectionWhenTheServerHasGone(): void
    {
        $server = ScriptedServer::imapGreeting()->hangUp();
        $imap   = ScriptedServer::imap($server);

        $imap->logout();

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function logoutReportsFailureWhenTheServerHasGone(): void
    {
        $imap = ScriptedServer::imap(ScriptedServer::imapGreeting()->hangUp());

        static::assertFalse($imap->logout());
    }

    #[Test]
    public function logoutReportsFailureWhenNotConnected(): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        static::assertFalse($imap->logout());
    }

    #[Test]
    public function cannotBeSerialized(): void
    {
        $imap = new Imap(connection: new InMemoryConnection());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Contenir\Mail\Protocol\Imap cannot be serialized');

        serialize($imap);
    }

    #[Test]
    public function refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Contenir\Mail\Protocol\Imap cannot be unserialized');

        unserialize(sprintf('O:%d:"%s":0:{}', strlen(Imap::class), Imap::class));
    }

    private function startTlsServer(): InMemoryConnection
    {
        return (new InMemoryConnection())->reply("* OK ready\r\n")
            ->expect("TAG1 CAPABILITY\r\n")
            ->reply("* CAPABILITY IMAP4rev1 STARTTLS LOGINDISABLED\r\nTAG1 OK\r\n")
            ->expect("TAG2 STARTTLS\r\n")
            ->reply("TAG2 OK begin TLS\r\n")
            ->startTls();
    }
}
