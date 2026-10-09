<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\LineLengthCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function str_repeat;

#[CoversClass(LineLengthCheck::class)]
#[Group('unit')]
final class LineLengthCheckTest extends TestCase
{
    private const int LIMIT = 5;

    /**
     * @param list<string> $chunks
     */
    #[DataProvider('acceptedProvider')]
    #[Test]
    public function acceptsLinesUpToTheLimit(array $chunks): void
    {
        LineLengthCheck::check($chunks, self::LIMIT);

        $this->addToAssertionCount(1);
    }

    /**
     * @param list<string> $chunks
     */
    #[DataProvider('refusedProvider')]
    #[Test]
    public function refusesLongerLineNamingItsNumberAndLength(array $chunks, int $line, int $length): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'Line %d of the message is %d bytes; SMTP allows at most 5. Encode the content '
                . '(quoted-printable or base64) instead of sending it as is.',
            $line,
            $length,
        ));

        LineLengthCheck::check($chunks, self::LIMIT);
    }

    #[Test]
    public function measuresLinesOfSmtpLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line 3 of the message is 999 bytes; SMTP allows at most 998.');

        LineLengthCheck::check(["a\nb\n" . str_repeat('c', times: 999) . "\nd"], 998);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'nothing'                       => [[]],
            'line of the limit'             => [['aaaaa']],
            'lines of the limit'            => [["aaaaa\r\nbbbbb\nccccc\rddddd"]],
            'line split at the limit'       => [['aaa', "aa\nbbbbb"]],
            'CRLF split between chunks'     => [["aaaaa\r", "\nbbbbb"]],
            'short line after a split line' => [["aaa\n", 'bb', 'b', "\nccccc"]],
        ];
    }

    /**
     * @return array<string, array{list<string>, int, int}>
     */
    public static function refusedProvider(): array
    {
        return [
            'first line'                       => [['aaaaaa'], 1, 6],
            'line after shorter ones'          => [["a\r\nbb\ncccccc"], 3, 6],
            'line after bare CRs'              => [["a\rb\rcccccc\rd"], 3, 6],
            'line that ends its chunk'         => [["a\nbbbbbbb", "\nc"], 2, 7],
            'line split between chunks'        => [["a\nbbb", "bbb\nc"], 2, 6],
            'line that runs on into the next'  => [['aaaaaa', "aaa\nb"], 1, 9],
            'line through three chunks'        => [['aaaaaa', 'aa', "aa\nb"], 1, 10],
            'line through to the end'          => [['aaaaaa', 'aa'], 1, 8],
            'line in a later chunk'            => [["a\n", "b\n", "c\ndddddd"], 4, 6],
            'line after a split CRLF'          => [["a\r", "\nbbbbbb"], 2, 6],
            'line after a line from the chunk' => [["aaa\nbb", "bbbb\n"], 2, 6],
        ];
    }
}
