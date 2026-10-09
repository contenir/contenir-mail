<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Exception\ExceptionInterface;
use Contenir\Mail\Dkim\Exception\InvalidArgumentException;
use Contenir\Mail\Dkim\Exception\LogicException;
use Contenir\Mail\Dkim\Exception\RuntimeException;
use Contenir\Mail\Exception;
use Contenir\Mail\Exception\ExceptionInterface as MailExceptionInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(InvalidArgumentException::class)]
#[CoversClass(RuntimeException::class)]
#[CoversClass(LogicException::class)]
#[Group('unit')]
final class ExceptionTest extends TestCase
{
    #[Test]
    #[DataProvider('exceptionProvider')]
    public function isCaughtAsAMailException(Throwable $exception): void
    {
        static::assertInstanceOf(MailExceptionInterface::class, $exception);
    }

    #[Test]
    #[DataProvider('exceptionProvider')]
    public function isCaughtAsADkimException(Throwable $exception): void
    {
        static::assertInstanceOf(ExceptionInterface::class, $exception);
    }

    #[Test]
    public function invalidArgumentExtendsThePackageException(): void
    {
        static::assertInstanceOf(Exception\InvalidArgumentException::class, new InvalidArgumentException('invalid'));
    }

    #[Test]
    public function logicExtendsThePackageException(): void
    {
        static::assertInstanceOf(Exception\LogicException::class, new LogicException('logic'));
    }

    #[Test]
    public function runtimeExtendsThePackageException(): void
    {
        static::assertInstanceOf(Exception\RuntimeException::class, new RuntimeException('runtime'));
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function exceptionProvider(): array
    {
        return [
            'invalid argument' => [new InvalidArgumentException('invalid')],
            'runtime'          => [new RuntimeException('runtime')],
            'logic'            => [new LogicException('logic')],
        ];
    }
}
