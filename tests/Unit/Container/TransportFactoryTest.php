<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Container;

use ArrayObject;
use Contenir\Mail\Container\TransportFactory;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Tests\Unit\TestAsset\ArrayContainer;
use Contenir\Mail\Transport\Failover;
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\InMemory;
use Contenir\Mail\Transport\Sendmail;
use Contenir\Mail\Transport\Smtp;
use Contenir\Mail\Transport\SmtpConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sys_get_temp_dir;

#[CoversClass(TransportFactory::class)]
#[Group('unit')]
final class TransportFactoryTest extends TestCase
{
    private const string AUTH_VALUE = 'not-a-real-credential';

    /**
     * @param class-string $expected
     */
    #[DataProvider('typeProvider')]
    #[Test]
    public function createsTransportOfType(string $type, string $expected): void
    {
        static::assertSame($expected, self::create(['type' => $type])::class);
    }

    /**
     * @param array<array-key, mixed> $services
     */
    #[DataProvider('missingTypeProvider')]
    #[Test]
    public function rejectsConfigurationWithoutType(array $services): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'config["mail"]["transport"]["type"] is required; set it to one of smtp, sendmail, file, inmemory, failover',
        );

        (new TransportFactory())(new ArrayContainer($services));
    }

    #[Test]
    public function passesSettingsToSmtpConfig(): void
    {
        $transport = self::create([
            'type' => 'smtp',
            'host' => 'smtp.example.com',
            'port' => '587',
            'auth' => ['type' => 'login', 'username' => 'orders', 'password' => self::AUTH_VALUE],
        ]);

        static::assertEquals(
            new SmtpConfig(
                host: 'smtp.example.com',
                port: 587,
                auth: new Login('orders', self::AUTH_VALUE),
            ),
            $transport instanceof Smtp ? $transport->getConfig() : null,
        );
    }

    #[Test]
    public function passesSettingsToSendmailConfig(): void
    {
        $transport = self::create(['type' => 'sendmail', 'parameters' => '-R hdrs']);

        static::assertSame(['-R', 'hdrs'], $transport instanceof Sendmail ? $transport->getConfig()->parameters : null);
    }

    #[Test]
    public function passesSettingsToFileConfig(): void
    {
        $transport = self::create(['type' => 'file', 'path' => sys_get_temp_dir()]);

        static::assertSame(sys_get_temp_dir(), $transport instanceof File ? $transport->getConfig()->path : null);
    }

    #[Test]
    public function readsTraversableConfiguration(): void
    {
        $config = new ArrayObject(['mail' => new ArrayObject(['transport' => new ArrayObject([
            'type' => 'in-memory',
        ])])]);
        $transport = (new TransportFactory())(new ArrayContainer(['config' => $config]));

        static::assertInstanceOf(InMemory::class, $transport);
    }

    #[Test]
    public function rejectsSettingsForInMemoryTransport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The in-memory transport takes no settings besides "type"');

        self::create(['type' => 'in-memory', 'path' => '/tmp']);
    }

    #[Test]
    public function rejectsUnknownSettingForTransport(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "options"');

        self::create(['type' => 'smtp', 'options' => ['host' => 'smtp.example.com']]);
    }

    #[Test]
    public function rejectsUnknownType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'config["mail"]["transport"]["type"] "pigeon" is unknown; expected one of smtp, sendmail, file, inmemory, failover',
        );

        self::create(['type' => 'pigeon']);
    }

    #[Test]
    public function rejectsTypeThatIsNotString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'config["mail"]["transport"]["type"] must be one of smtp, sendmail, file, inmemory, failover, got int',
        );

        self::create(['type' => 1]);
    }

    #[DataProvider('invalidConfigProvider')]
    #[Test]
    public function rejectsSectionThatIsNotArray(mixed $config, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new TransportFactory())(new ArrayContainer(['config' => $config]));
    }

    /**
     * @return array<string, array{string, class-string}>
     */
    public static function typeProvider(): array
    {
        return [
            'smtp'      => ['smtp', Smtp::class],
            'SMTP'      => ['SMTP', Smtp::class],
            'sendmail'  => ['sendmail', Sendmail::class],
            'file'      => ['file', File::class],
            'in-memory' => ['in-memory', InMemory::class],
            'in_memory' => ['in_memory', InMemory::class],
            'inmemory'  => ['inmemory', InMemory::class],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function missingTypeProvider(): array
    {
        return [
            'no config'            => [[]],
            'no mail section'      => [['config' => []]],
            'no transport section' => [['config' => ['mail' => []]]],
            'no type'              => [['config' => ['mail' => ['transport' => ['parameters' => '-oi']]]]],
            'null type'            => [['config' => ['mail' => ['transport' => ['type' => null]]]]],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'config'    => ['config', 'config must be an array, got string'],
            'mail'      => [['mail' => 'smtp'], 'config["mail"] must be an array, got string'],
            'transport' => [
                ['mail' => ['transport' => 'smtp']],
                'config["mail"]["transport"] must be an array, got string',
            ],
        ];
    }

    #[Test]
    public function createsFailoverOfConfiguredTransports(): void
    {
        $failover = self::create([
            'type'       => 'failover',
            'transports' => [['type' => 'in-memory'], ['type' => 'sendmail']],
        ]);

        static::assertSame(
            [InMemory::class, Sendmail::class],
            $failover instanceof Failover
                ? array_map(static fn(object $transport): string => $transport::class, $failover->getTransports())
                : [],
        );
    }

    #[Test]
    public function createsNestedFailover(): void
    {
        $failover = self::create([
            'type'       => 'failover',
            'transports' => [['type' => 'failover', 'transports' => [['type' => 'in-memory']]]],
        ]);

        static::assertInstanceOf(Failover::class, $failover instanceof Failover ? $failover->getTransports()[0] : null);
    }

    /**
     * @param array<string, mixed> $transport
     */
    #[Test]
    #[DataProvider('invalidFailoverProvider')]
    public function refusesInvalidFailover(array $transport, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::create($transport);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFailoverProvider(): array
    {
        return [
            'no transports'           => [
                ['type' => 'failover'],
                'config["mail"]["transport"]["transports"] needs at least one transport',
            ],
            'transports not a list'   => [
                ['type' => 'failover', 'transports' => 'smtp'],
                'config["mail"]["transport"]["transports"] must be an array, got string',
            ],
            'another setting'         => [
                ['type' => 'failover', 'transports' => [['type' => 'in-memory']], 'host' => 'x'],
                'config["mail"]["transport"] takes only "type" and "transports"',
            ],
            'transport without type'  => [
                ['type' => 'failover', 'transports' => [['type' => 'in-memory'], ['host' => 'x']]],
                'config["mail"]["transport"]["transports"][1]["type"] is required',
            ],
            'named transport invalid' => [
                ['type' => 'failover', 'transports' => ['backup' => ['type' => 7]]],
                'config["mail"]["transport"]["transports"][\'backup\']["type"] must be one of',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $transport
     */
    private static function create(array $transport): object
    {
        return (new TransportFactory())(new ArrayContainer(['config' => ['mail' => ['transport' => $transport]]]));
    }
}
