<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Tests\TestAsset\InjectingHeader;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\HeaderGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HeaderGuard::class)]
#[Group('unit')]
final class HeaderGuardTest extends TestCase
{
    #[DataProvider('safeLineProvider')]
    #[Test]
    public function passesSingleOrFoldedLine(string $line): void
    {
        $headers = new Headers(new InjectingHeader($line));

        static::assertSame($headers, HeaderGuard::check($headers));
    }

    #[Test]
    public function passesBuiltInHeaders(): void
    {
        $headers = new Headers(new GenericHeader('Subject', 'Hello'), new GenericHeader('X-Mailer', 'Contenir'));

        static::assertSame($headers, HeaderGuard::check($headers));
    }

    /**
     * Header injection: a line break that is not folding would start a header of its own.
     */
    #[DataProvider('injectedLineProvider')]
    #[Test]
    public function refusesLineBreakThatIsNotFolding(string $line): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Header "X-Custom" contains a line break that is not folding; not sending it');

        HeaderGuard::check(new Headers(new GenericHeader('Subject', 'Hello'), new InjectingHeader($line)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeLineProvider(): array
    {
        return [
            'one line'          => ['X-Custom: value'],
            'folded with space' => ["X-Custom: a\r\n b"],
            'folded with tab'   => ["X-Custom: a\r\n\tb"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedLineProvider(): array
    {
        return [
            'CRLF and a header'  => ["X-Custom: a\r\nBcc: x@example.com"],
            'bare LF'            => ["X-Custom: a\nBcc: x@example.com"],
            'bare CR'            => ["X-Custom: a\rBcc: x@example.com"],
            'trailing CRLF'      => ["X-Custom: a\r\n"],
            'bare LF then space' => ["X-Custom: a\n b"],
        ];
    }
}
