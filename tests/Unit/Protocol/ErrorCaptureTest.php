<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\ErrorCapture;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function error_clear_last;
use function error_get_last;
use function fclose;
use function fwrite;
use function restore_error_handler;
use function set_error_handler;
use function stream_socket_pair;
use function trigger_error;

use const E_USER_WARNING;
use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;

#[CoversClass(ErrorCapture::class)]
#[Group('unit')]
final class ErrorCaptureTest extends TestCase
{
    #[Test]
    public function returnsTheResultAndNoMessageWhenNothingIsRaised(): void
    {
        static::assertSame([42, ''], ErrorCapture::run(static fn(): int => 42));
    }

    #[Test]
    public function collectsNotices(): void
    {
        [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fclose($peer);

        $result = ErrorCapture::run(static fn(): int|false => fwrite($client, data: 'x'));
        fclose($client);

        static::assertSame([false, 'fwrite(): Send of 1 bytes failed with errno=32 Broken pipe'], $result);
    }

    #[Test]
    public function joinsTheMessagesOfSeveralWarnings(): void
    {
        $result = ErrorCapture::run(static function (): string {
            $string = 'abc';

            return $string[5] . $string[6];
        });

        static::assertSame(
            ['', 'Uninitialized string offset 5; Uninitialized string offset 6'],
            $result,
        );
    }

    #[Test]
    public function restoresThePreviousHandlerWhenTheOperationThrows(): void
    {
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = $message;

            return true;
        });

        try {
            try {
                ErrorCapture::run(static fn(): never => throw new DomainException('failed'));
            } catch (DomainException) {
                trigger_error('after the operation', E_USER_WARNING);
            }
        } finally {
            restore_error_handler();
        }

        static::assertSame(['after the operation'], $seen);
    }

    #[Test]
    public function keepsCapturedWarningsFromPhp(): void
    {
        error_clear_last();
        ErrorCapture::run(static function (): string {
            $string = 'abc';

            return $string[5];
        });

        static::assertNull(error_get_last());
    }
}
