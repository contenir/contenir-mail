<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Canonicalization;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Canonicalization::class)]
#[Group('unit')]
final class CanonicalizationTest extends TestCase
{
    #[Test]
    #[DataProvider('headers')]
    public function canonicalisesHeaderField(Canonicalization $canonicalization, string $field, string $expected): void
    {
        static::assertSame($expected, $canonicalization->header($field));
    }

    /**
     * The first cases are the example of RFC 6376, section 3.4.6.
     *
     * @return array<string, array{Canonicalization, string, string}>
     */
    public static function headers(): array
    {
        return [
            'relaxed, RFC example A'            => [Canonicalization::Relaxed, 'A: X', "a:X\r\n"],
            'relaxed, RFC example B'            => [Canonicalization::Relaxed, "B : Y\t\r\n\tZ  ", "b:Y Z\r\n"],
            'simple, RFC example B'             => [
                Canonicalization::Simple,
                "B : Y\t\r\n\tZ  ",
                "B : Y\t\r\n\tZ  \r\n",
            ],
            'relaxed, tab before colon'         => [Canonicalization::Relaxed, "Subject\t: Hi", "subject:Hi\r\n"],
            'relaxed, runs of white space'      => [
                Canonicalization::Relaxed,
                "Subject:  a  \t b\r\n  c",
                "subject:a b c\r\n",
            ],
            'relaxed, colon in value'           => [Canonicalization::Relaxed, 'X-Note: a: b', "x-note:a: b\r\n"],
            'relaxed, value folded at once'     => [
                Canonicalization::Relaxed,
                "To:\r\n joe@example.com",
                "to:joe@example.com\r\n",
            ],
            'relaxed, empty value'              => [Canonicalization::Relaxed, 'X-Empty:', "x-empty:\r\n"],
            'relaxed, name kept apart from tag' => [
                Canonicalization::Relaxed,
                'DKIM-Signature: v=1; b=',
                "dkim-signature:v=1; b=\r\n",
            ],
        ];
    }

    #[Test]
    #[DataProvider('bodies')]
    public function canonicalisesBody(Canonicalization $canonicalization, string $body, string $expected): void
    {
        static::assertSame($expected, $canonicalization->body($body));
    }

    /**
     * The first cases are the example of RFC 6376, section 3.4.6.
     *
     * @return array<string, array{Canonicalization, string, string}>
     */
    public static function bodies(): array
    {
        $example = " C \r\nD \t E\r\n\r\n\r\n";

        return [
            'relaxed, RFC example'                     => [Canonicalization::Relaxed, $example, " C\r\nD E\r\n"],
            'simple, RFC example'                      => [Canonicalization::Simple, $example, " C \r\nD \t E\r\n"],
            'simple, empty'                            => [Canonicalization::Simple, '', "\r\n"],
            'relaxed, empty'                           => [Canonicalization::Relaxed, '', ''],
            'simple, only empty lines'                 => [Canonicalization::Simple, "\r\n\r\n", "\r\n"],
            'relaxed, only empty lines'                => [Canonicalization::Relaxed, "\r\n\r\n", ''],
            'relaxed, only white space lines'          => [Canonicalization::Relaxed, " \r\n\t\r\n", ''],
            'simple, white space lines kept'           => [Canonicalization::Simple, " \r\n\t\r\n", " \r\n\t\r\n"],
            'simple, no final line break'              => [Canonicalization::Simple, 'Hi.', "Hi.\r\n"],
            'relaxed, no final line break'             => [Canonicalization::Relaxed, 'Hi. ', "Hi.\r\n"],
            'relaxed, trailing space on an inner line' => [Canonicalization::Relaxed, "a  \r\nb", "a\r\nb\r\n"],
            'relaxed, white space inside a line kept'  => [Canonicalization::Relaxed, "a\t\tb", "a b\r\n"],
            'simple, inner empty lines kept'           => [
                Canonicalization::Simple,
                "a\r\n\r\nb\r\n\r\n",
                "a\r\n\r\nb\r\n",
            ],
        ];
    }
}
