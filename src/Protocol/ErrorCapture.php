<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use function implode;
use function restore_error_handler;
use function set_error_handler;

use const E_NOTICE;
use const E_WARNING;

/**
 * Runs a stream function and collects the warnings it raises, so that the
 * caller can put them in an exception instead of PHP reporting them.
 *
 * The previous error handler is always restored, also when the operation throws.
 *
 * @internal
 */
final class ErrorCapture
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return array{T, string} The operation's result, and its warnings and notices joined by "; ".
     *
     * @mago-expect analysis:unused-parameter PHP passes the error level first; only the message is kept.
     */
    public static function run(callable $operation): array
    {
        $messages = [];
        set_error_handler(
            static function (int $level, string $message) use (&$messages): bool {
                $messages[] = $message;

                return true;
            },
            E_WARNING | E_NOTICE,
        );

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, implode('; ', $messages)];
    }
}
