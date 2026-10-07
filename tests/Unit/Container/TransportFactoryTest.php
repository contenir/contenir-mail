<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Container;

use ArrayObject;
use Contenir\Mail\Container\TransportFactory;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\Login;
use Contenir\Mail\Tests\Unit\TestAsset\ArrayContainer;
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

    #[Test]
    public function createsSendmailTransportWithoutType(): void
    {
        static::assertInstanceOf(Sendmail::class, self::create([]));
    }

    #[Test]
    public function createsSendmailTransportWithoutConfig(): void
    {
        static::assertInstanceOf(Sendmail::class, (new TransportFactory())(new ArrayContainer()));
    }

    #[Test]
    public function createsSendmailTransportWithoutMailSection(): void
    {
        static::assertInstanceOf(Sendmail::class, (new TransportFactory())(new ArrayContainer(['config' => []])));
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
            'config["mail"]["transport"]["type"] "pigeon" is unknown; expected one of smtp, sendmail, file, inmemory',
        );

        self::create(['type' => 'pigeon']);
    }

    #[Test]
    public function rejectsTypeThatIsNotString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'config["mail"]["transport"]["type"] must be one of smtp, sendmail, file, inmemory, got int',
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

    /**
     * @param array<string, mixed> $transport
     */
    private static function create(array $transport): object
    {
        return (new TransportFactory())(new ArrayContainer(['config' => ['mail' => ['transport' => $transport]]]));
    }
}
