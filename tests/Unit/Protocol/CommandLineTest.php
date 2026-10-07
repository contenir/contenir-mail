<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\CommandLine;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandLine::class)]
#[Group('unit')]
final class CommandLineTest extends TestCase
{
    #[Test]
    public function terminatesTheLineWithCrLf(): void
    {
        static::assertSame("PASS se cret\t!\r\n", CommandLine::terminate("PASS se cret\t!"));
    }

    #[DataProvider('injectionProvider')]
    #[Test]
    public function refusesALineThatCouldInjectAnotherCommand(string $line): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Refusing to send a command containing CR, LF or NUL; it could inject another command',
        );

        CommandLine::terminate($line);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectionProvider(): array
    {
        return [
            'CRLF'        => ["PASS secret\r\nDELE 1"],
            'LF'          => ["PASS secret\nDELE 1"],
            'CR'          => ["PASS secret\rDELE 1"],
            'NUL'         => ["PASS secret\0"],
            'trailing LF' => ["NOOP\n"],
        ];
    }

    #[Test]
    public function neverRepeatsTheRefusedLine(): void
    {
        $message = '';
        try {
            CommandLine::terminate("PASS hunter2\r\n");
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
        }

        static::assertStringNotContainsString('hunter2', $message);
    }
}
