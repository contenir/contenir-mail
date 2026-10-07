<?php

namespace Contenir\Mail\Tests\Unit\Transport;

use Composer\InstalledVersions;
use Contenir\Mail\Transport\Exception;
use Contenir\Mail\Transport\Factory;
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\InMemory;
use Contenir\Mail\Transport\Sendmail;
use Contenir\Mail\Transport\Smtp;
use Laminas\Stdlib\ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function class_exists;
use function restore_error_handler;
use function set_error_handler;
use function version_compare;

use const E_USER_DEPRECATED;

#[CoversClass(Factory::class)]
class FactoryTest extends TestCase
{
    #[Test]
    #[DataProvider('invalidSpecTypeProvider')]
    public function invalidSpecThrowsInvalidArgumentException(mixed $spec): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        Factory::create($spec);
    }

    public static function invalidSpecTypeProvider(): array
    {
        return [
            ['spec'],
            [new stdClass()],
        ];
    }

    #[Test]
    public function defaultTypeIsSendmail(): void
    {
        $transport = Factory::create();

        static::assertInstanceOf(Sendmail::class, $transport);
    }

    /**
     * @param class-string $type
     */
    #[Test]
    #[DataProvider('typeProvider')]
    public function canCreateClassUsingTypeKey(string $type): void
    {
        set_error_handler(
            static function ($code, $message): void {
                // skip deprecation notices
            },
            E_USER_DEPRECATED,
        );
        $transport = Factory::create([
            'type' => $type,
        ]);
        restore_error_handler();

        static::assertInstanceOf($type, $transport);
    }

    public static function typeProvider(): array
    {
        return [
            [File::class],
            [InMemory::class],
            [Sendmail::class],
            [Smtp::class],
        ];
    }

    /**
     * @param class-string $expectedClass
     */
    #[Test]
    #[DataProvider('typeAliasProvider')]
    public function canCreateClassFromTypeAlias(string $type, string $expectedClass): void
    {
        $transport = Factory::create([
            'type' => $type,
        ]);

        static::assertInstanceOf($expectedClass, $transport);
    }

    public static function typeAliasProvider(): array
    {
        return [
            ['file',     File::class],
            ['memory',   InMemory::class],
            ['inmemory', InMemory::class],
            ['InMemory', InMemory::class],
            ['sendmail', Sendmail::class],
            ['smtp',     Smtp::class],
            ['File',     File::class],
            ['null',     InMemory::class],
            ['Null',     InMemory::class],
            ['NULL',     InMemory::class],
            ['Sendmail', Sendmail::class],
            ['SendMail', Sendmail::class],
            ['Smtp',     Smtp::class],
            ['SMTP',     Smtp::class],
        ];
    }

    #[Test]
    public function canUseTraversableAsSpec(): void
    {
        if (
            class_exists(InstalledVersions::class)
            && version_compare((string) InstalledVersions::getVersion('laminas/laminas-stdlib'), '3.3.0') < 0
        ) {
            static::markTestSkipped(
                'continue statement inside of switch causes errors when testing against stdlib < 3.3.0 versions',
            );
        }

        $spec = new ArrayObject([
            'type' => 'inMemory',
        ]);

        $transport = Factory::create($spec);

        static::assertInstanceOf(InMemory::class, $transport);
    }

    #[Test]
    #[DataProvider('invalidClassProvider')]
    public function invalidClassThrowsDomainException(string $class): void
    {
        $this->expectException(Exception\DomainException::class);
        Factory::create([
            'type' => $class,
        ]);
    }

    public static function invalidClassProvider(): array
    {
        return [
            ['stdClass'],
            ['non-existent-class'],
        ];
    }

    #[Test]
    public function canCreateSmtpTransportWithOptions(): void
    {
        $transport = Factory::create([
            'type'    => 'smtp',
            'options' => [
                'host' => 'somehost',
            ],
        ]);

        static::assertSame($transport->getOptions()->getHost(), 'somehost');
    }

    #[Test]
    public function canCreateFileTransportWithOptions(): void
    {
        $transport = Factory::create([
            'type'    => 'file',
            'options' => [
                'path' => __DIR__,
            ],
        ]);

        static::assertSame($transport->getOptions()->getPath(), __DIR__);
    }
}
