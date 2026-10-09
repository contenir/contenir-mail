<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Testing\InMemoryConnection;
use Contenir\Mail\Tests\Unit\Protocol\TestAsset\ScriptedServer;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function md5;
use function serialize;
use function sprintf;
use function strlen;
use function unserialize;

#[CoversClass(Pop3::class)]
#[Group('unit')]
final class ConnectTest extends TestCase
{
    /**
     * @param array{int, Security} $expected
     */
    #[DataProvider('legacyArgumentProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function readsTheLaminasPositionalArguments(?int $port, string|bool $ssl, array $expected): void
    {
        $server = ScriptedServer::pop3Greeting()->hangUp();

        new Pop3('pop.example.com', $port, $ssl, false, $server);

        static::assertSame($expected, [$server->openedPort(), $server->openedWith()?->security]);
    }

    /**
     * @return array<string, array{int|null, string|bool, array{int, Security}}>
     */
    public static function legacyArgumentProvider(): array
    {
        return [
            'plain on the default port' => [null, false, [110, Security::None]],
            'plain on a given port'     => [1110, false, [1110, Security::None]],
            'ssl on the default port'   => [null, 'ssl', [995, Security::Tls]],
            'ssl on a given port'       => [1995, 'SSL', [1995, Security::Tls]],
        ];
    }

    #[Test]
    public function returnsTheGreeting(): void
    {
        $pop3 = new Pop3(connection: ScriptedServer::pop3Greeting()->hangUp());

        static::assertSame('POP3 ready', $pop3->connect(ScriptedServer::plain()));
    }

    #[Test]
    public function connectsWithAConnectionConfigGivenToTheConstructor(): void
    {
        $server = ScriptedServer::pop3Greeting()->hangUp();
        $config = new ConnectionConfig(
            host: 'pop.example.com',
            security: Security::None,
            verifyPeer: false,
        );

        new Pop3($config, connection: $server);

        static::assertSame($config, $server->openedWith());
    }

    #[Test]
    public function takesCertificateVerificationFromTheConnectionConfig(): void
    {
        $pop3 = new Pop3(connection: ScriptedServer::pop3Greeting()->hangUp());

        $pop3->connect(new ConnectionConfig(
            host: 'pop.example.com',
            security: Security::None,
            verifyPeer: false,
        ));

        static::assertFalse($pop3->validateCert());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function verifiesTheCertificateWhenNotToldOtherwise(): void
    {
        $server = ScriptedServer::pop3Greeting()->hangUp();

        new Pop3('pop.example.com', null, false, connection: $server);

        static::assertTrue($server->openedWith()?->verifyPeer);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function skipsCertificateVerificationOnlyWhenAsked(): void
    {
        $server = ScriptedServer::pop3Greeting()->hangUp();

        new Pop3('pop.example.com', null, false, true, $server);

        static::assertFalse($server->openedWith()?->verifyPeer);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function usesTheProtocolTimeoutForLegacyArguments(): void
    {
        $server = ScriptedServer::pop3Greeting()->hangUp();

        new Pop3('pop.example.com', null, false, false, $server);

        static::assertSame(Pop3::TIMEOUT_CONNECTION, $server->openedWith()?->timeout);
    }

    #[Test]
    public function doesNotConnectWithoutAHost(): void
    {
        $server = new InMemoryConnection();

        new Pop3(connection: $server);

        static::assertNull($server->openedWith());
    }

    #[Test]
    public function reportsStartTlsAsTheSecurityBeforeConnecting(): void
    {
        $pop3 = new Pop3(connection: new InMemoryConnection());

        static::assertSame(Security::StartTls, $pop3->getConnectionConfig()->security);
    }

    #[Test]
    public function reportsTheSettingsItConnectedWith(): void
    {
        $pop3 = ScriptedServer::pop3(ScriptedServer::pop3Greeting()->hangUp());

        static::assertEquals(ScriptedServer::plain(), $pop3->getConnectionConfig());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesAnUnknownSecuritySetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown connection security');

        new Pop3('pop.example.com', null, true, false, new InMemoryConnection());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function failsWhenTheGreetingIsAnError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('last request failed');

        new Pop3(
            'pop.example.com',
            null,
            false,
            false,
            (new InMemoryConnection())->reply("-ERR busy\r\n")
                ->hangUp(),
        );
    }

    #[IgnoreDeprecations]
    #[Test]
    public function usesStlsWhenNoSecurityIsGiven(): void
    {
        $server = $this->stlsServer("+OK\r\nTOP\r\nSTLS\r\n.\r\n")->hangUp();

        new Pop3('pop.example.com', connection: $server);

        static::assertTrue($server->isTlsEnabled());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function usesStlsForTheLaminasTlsSetting(): void
    {
        $server = $this->stlsServer("+OK\r\nstls\r\n.\r\n")->hangUp();

        new Pop3('pop.example.com', null, 'tls', false, $server);

        static::assertTrue($server->isTlsEnabled());
    }

    #[IgnoreDeprecations]
    #[Test]
    public function upgradesBeforeLoggingIn(): void
    {
        $server = $this->stlsServer("+OK\r\nSTLS\r\n.\r\n")
            ->expect("USER user\r\n")
            ->reply("+OK\r\n")
            ->expect("PASS secret\r\n")
            ->reply("+OK\r\n")
            ->hangUp();
        $pop3 = new Pop3('pop.example.com', null, null, false, $server);
        $pop3->login('user', 'secret', false);

        static::assertTrue($server->isScriptComplete());
    }

    #[DataProvider('stlsNotOfferedProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function refusesToContinueInPlainTextWhenStlsIsNotOffered(string $capa): void
    {
        $server = ScriptedServer::pop3Greeting()->expect("CAPA\r\n")->reply($capa)->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the server does not offer STLS; refusing to continue in plain text');

        new Pop3('pop.example.com', connection: $server);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function stlsNotOfferedProvider(): array
    {
        return [
            'not listed'          => ["+OK\r\nTOP\r\nUIDL\r\n.\r\n"],
            'only as an argument' => ["+OK\r\nSASL STLS\r\n.\r\n"],
            'empty list'          => ["+OK\r\n.\r\n"],
        ];
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesToContinueInPlainTextWhenCapaIsNotSupported(): void
    {
        $server = ScriptedServer::pop3Greeting()->expect("CAPA\r\n")->reply("-ERR unknown command\r\n")->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the server does not list its capabilities; refusing to continue in plain text');

        new Pop3('pop.example.com', connection: $server);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesToContinueInPlainTextWhenTheServerRefusesStls(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("CAPA\r\n")
            ->reply("+OK\r\nSTLS\r\n.\r\n")
            ->expect("STLS\r\n")
            ->reply("-ERR not now\r\n")
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot enable TLS: the server refused STLS');

        new Pop3('pop.example.com', connection: $server);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function failsWhenTheTlsHandshakeFails(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("CAPA\r\n")
            ->reply("+OK\r\nSTLS\r\n.\r\n")
            ->expect("STLS\r\n")
            ->reply("+OK begin\r\n")
            ->failTls('certificate verify failed')
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot enable TLS: certificate verify failed');

        new Pop3('pop.example.com', connection: $server);
    }

    #[IgnoreDeprecations]
    #[Test]
    public function refusesResponsesInjectedBeforeTheTlsHandshake(): void
    {
        $server = ScriptedServer::pop3Greeting()
            ->expect("CAPA\r\n")
            ->reply("+OK\r\nSTLS\r\n.\r\n")
            ->expect("STLS\r\n")
            ->reply("+OK begin\r\n+OK logged in\r\n")
            ->startTls()
            ->hangUp();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sent data before TLS was negotiated');

        new Pop3('pop.example.com', connection: $server);
    }

    #[DataProvider('timestampProvider')]
    #[Test]
    public function usesApopWithTheGreetingTimestamp(string $greeting, string $timestamp): void
    {
        $server = (new InMemoryConnection())->reply("+OK {$greeting}\r\n")
            ->expect(self::apop($timestamp))
            ->reply("+OK\r\n")
            ->hangUp();
        ScriptedServer::pop3($server)->login('user', 'secret');

        static::assertTrue($server->isScriptComplete());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function timestampProvider(): array
    {
        return [
            'timestamp'                => [
                'ready <1896.697170952@dbc.mtview.ca.us>',
                '<1896.697170952@dbc.mtview.ca.us>',
            ],
            'timestamp after brackets' => ['<x> ready <1@host>', '<1@host>'],
        ];
    }

    #[DataProvider('noTimestampProvider')]
    #[Test]
    public function usesUserAndPassWithoutAValidGreetingTimestamp(string $greeting): void
    {
        $server = (new InMemoryConnection())->reply("+OK {$greeting}\r\n")
            ->expect("USER user\r\n")
            ->reply("+OK\r\n")
            ->expect("PASS secret\r\n")
            ->reply("+OK\r\n")
            ->hangUp();
        ScriptedServer::pop3($server)->login('user', 'secret');

        static::assertTrue($server->isScriptComplete());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function noTimestampProvider(): array
    {
        return [
            'no timestamp'           => ['ready'],
            'brackets without an at' => ['ready <1896.697170952>'],
            'at at the start'        => ['ready <@host>'],
            'unclosed bracket'       => ['ready <1@host'],
        ];
    }

    #[Test]
    public function logsOutWhenDestroyed(): void
    {
        $server = ScriptedServer::pop3Greeting()->expect("QUIT\r\n")->reply("+OK bye\r\n");
        $pop3   = ScriptedServer::pop3($server);

        unset($pop3);

        static::assertTrue($server->isScriptComplete());
    }

    #[Test]
    public function logoutClosesTheConnection(): void
    {
        $server = ScriptedServer::pop3Greeting()->expect("QUIT\r\n")->reply("+OK bye\r\n");

        ScriptedServer::pop3($server)->logout();

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function logoutClosesTheConnectionWhenTheServerHasGone(): void
    {
        $server = ScriptedServer::pop3Greeting()->hangUp();

        ScriptedServer::pop3($server)->logout();

        static::assertFalse($server->isConnected());
    }

    #[Test]
    public function sendsNothingWhenDestroyedWithoutHavingConnected(): void
    {
        $server = new InMemoryConnection();
        $pop3   = new Pop3(connection: $server);

        unset($pop3);

        static::assertSame('', $server->written());
    }

    #[Test]
    public function cannotBeSerialized(): void
    {
        $pop3 = new Pop3(connection: new InMemoryConnection());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Contenir\Mail\Protocol\Pop3 cannot be serialized');

        serialize($pop3);
    }

    #[Test]
    public function refusesToUnserializeSoACraftedPayloadNeverReachesTheDestructor(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Contenir\Mail\Protocol\Pop3 cannot be unserialized');

        unserialize(sprintf('O:%d:"%s":0:{}', strlen(Pop3::class), Pop3::class));
    }

    private function stlsServer(string $capa): InMemoryConnection
    {
        return ScriptedServer::pop3Greeting()
            ->expect("CAPA\r\n")
            ->reply($capa)
            ->expect("STLS\r\n")
            ->reply("+OK begin TLS\r\n")
            ->startTls();
    }

    private static function apop(string $timestamp): string
    {
        $digest = md5("{$timestamp}secret");

        return "APOP user {$digest}\r\n";
    }
}
