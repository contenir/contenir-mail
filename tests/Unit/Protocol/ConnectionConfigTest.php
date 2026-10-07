<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionConfig::class)]
#[Group('unit')]
final class ConnectionConfigTest extends TestCase
{
    #[Test]
    public function readsSameDefaultsAsConstructor(): void
    {
        static::assertEquals(new ConnectionConfig(), ConnectionConfig::fromIterable([]));
    }

    #[Test]
    public function readsEverySetting(): void
    {
        static::assertEquals(
            new ConnectionConfig(
                host: 'mail.example.com',
                port: 587,
                security: Security::StartTls,
                verifyPeer: false,
                timeout: 10,
            ),
            ConnectionConfig::fromIterable([
                'host'        => 'mail.example.com',
                'port'        => '587',
                'security'    => 'starttls',
                'verify_peer' => 'false',
                'timeout'     => '10',
            ]),
        );
    }

    #[Test]
    public function readsFromReaderHoldingOtherKeys(): void
    {
        $reader = ConfigReader::read(
            'Example',
            ['host' => 'mail.example.com', 'username' => 'jo'],
            [...ConnectionConfig::KEYS, 'username'],
        );

        static::assertEquals(new ConnectionConfig(host: 'mail.example.com'), ConnectionConfig::fromReader($reader));
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            ConnectionConfig::class
                . ': unknown option "ssl"; expected one of host, port, security, verify_peer, timeout',
        );

        ConnectionConfig::fromIterable(['ssl' => 'tls']);
    }

    #[Test]
    #[DataProvider('validPortProvider')]
    public function acceptsPortInRange(?int $port): void
    {
        static::assertSame($port, (new ConnectionConfig(port: $port))->port);
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function validPortProvider(): array
    {
        return [
            'none'   => [null],
            'lowest' => [1],
            'SMTP'   => [587],
            'top'    => [65_535],
        ];
    }

    #[Test]
    #[DataProvider('invalidPortProvider')]
    public function rejectsPortOutOfRange(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Port {$port} is out of range");

        new ConnectionConfig(port: $port);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidPortProvider(): array
    {
        return [
            'zero'         => [0],
            'negative'     => [-1],
            'one too high' => [65_536],
        ];
    }

    #[Test]
    public function acceptsOneSecondTimeout(): void
    {
        static::assertSame(1, (new ConnectionConfig(timeout: 1))->timeout);
    }

    #[Test]
    public function rejectsTimeoutUnderOneSecond(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout 0 must be at least one second');

        new ConnectionConfig(timeout: 0);
    }

    #[Test]
    #[DataProvider('portProvider')]
    public function choosesStandardPortForSecurity(?int $port, Security $security, int $expected): void
    {
        static::assertSame(
            $expected,
            (new ConnectionConfig(
                port: $port,
                security: $security,
            ))->portOr(
                plain: 143,
                tls: 993,
            ),
        );
    }

    /**
     * @return array<string, array{int|null, Security, int}>
     */
    public static function portProvider(): array
    {
        return [
            'plain'          => [null, Security::None, 143],
            'STARTTLS'       => [null, Security::StartTls, 143],
            'TLS'            => [null, Security::Tls, 993],
            'configured'     => [1143, Security::None, 1143],
            'configured TLS' => [1993, Security::Tls, 1993],
        ];
    }
}
