<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Exception\ExceptionInterface as MailExceptionInterface;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Exception\RuntimeException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(InvalidArgumentException::class)]
#[CoversClass(RuntimeException::class)]
#[Group('unit')]
final class ExceptionTest extends TestCase
{
    #[Test]
    #[DataProvider('exceptionProvider')]
    public function isCaughtAsAMailException(Throwable $exception): void
    {
        static::assertInstanceOf(MailExceptionInterface::class, $exception);
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function exceptionProvider(): array
    {
        return [
            'invalid argument' => [new InvalidArgumentException('invalid')],
            'runtime'          => [new RuntimeException('runtime')],
        ];
    }
}
