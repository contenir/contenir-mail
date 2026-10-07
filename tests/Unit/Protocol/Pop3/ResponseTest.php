<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Pop3;

use Contenir\Mail\Protocol\Pop3\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(\Contenir\Mail\Protocol\Pop3\Response::class)]
class ResponseTest extends TestCase
{
    /** @psalm-suppress InternalClass */
    #[Test]
    public function integration(): void
    {
        /** @psalm-suppress InternalMethod */
        $response = new Response('+OK', 'Auth');

        /** @psalm-suppress InternalMethod */
        static::assertSame('+OK', $response->status());

        /** @psalm-suppress InternalMethod */
        static::assertSame('Auth', $response->message());
    }
}
