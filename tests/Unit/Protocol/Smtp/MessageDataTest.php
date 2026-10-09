<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Smtp;

use Contenir\Mail\Protocol\Smtp\MessageData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;
use function iterator_to_array;

#[CoversClass(MessageData::class)]
#[Group('unit')]
final class MessageDataTest extends TestCase
{
    /**
     * @param list<string> $chunks
     */
    #[DataProvider('encodingProvider')]
    #[Test]
    public function encodesMessageTextAsSmtpSendsIt(array $chunks, string $expected): void
    {
        static::assertSame($expected, implode('', iterator_to_array(
            MessageData::encode($chunks),
            preserve_keys: false,
        )));
    }

    /**
     * @param list<string> $chunks
     * @param list<string> $expected
     */
    #[DataProvider('normalizationProvider')]
    #[Test]
    public function writesEveryLineBreakAsCrlf(array $chunks, array $expected): void
    {
        static::assertSame($expected, iterator_to_array(MessageData::normalize($chunks), preserve_keys: false));
    }

    #[Test]
    public function holdsBackTheLineBreakAtTheEndOfAChunk(): void
    {
        static::assertSame(
            ['a', "\r\nb", "\r\n"],
            iterator_to_array(MessageData::encode(["a\n", "b\n"]), preserve_keys: false),
        );
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function encodingProvider(): array
    {
        return [
            'nothing'                             => [[], ''],
            'empty chunk'                         => [[''], ''],
            'single line feed'                    => [["\n"], ''],
            'single CRLF'                         => [["\r\n"], ''],
            'single CR'                           => [["\r"], ''],
            'CRLF split between chunks'           => [["\r", "\n"], ''],
            'two line breaks'                     => [["\n\n"], "\r\n\r\n"],
            'line without break'                  => [['a'], "a\r\n"],
            'line with break'                     => [["a\r\n"], "a\r\n"],
            'bare line feeds'                     => [["a\nb\n"], "a\r\nb\r\n"],
            'bare carriage returns'               => [["a\rb"], "a\r\nb\r\n"],
            'leading dot'                         => [['.a'], "..a\r\n"],
            'dot after a line break'              => [["a\n.b"], "a\r\n..b\r\n"],
            'dot after a bare CR'                 => [["a\r.b"], "a\r\n..b\r\n"],
            'dot inside a line'                   => [['a.b'], "a.b\r\n"],
            'lone dot'                            => [["a\n.\nb"], "a\r\n..\r\nb\r\n"],
            'dot starting the next chunk'         => [["a\n", '.b'], "a\r\n..b\r\n"],
            'dot starting a chunk mid-line'       => [['a', '.b'], "a.b\r\n"],
            'dot after CR at the end of a chunk'  => [["a\r", '.b'], "a\r\n..b\r\n"],
            'dot after a split CRLF'              => [["a\r", "\n.b"], "a\r\n..b\r\n"],
            'dot after a chunk of just LF'        => [["a\r", "\n", '.b'], "a\r\n..b\r\n"],
            'CRLF split around an empty chunk'    => [["a\r", '', "\nb"], "a\r\nb\r\n"],
            'CR then CR across chunks'            => [["a\r", "\rb"], "a\r\n\r\nb\r\n"],
            'CR then a letter across chunks'      => [["a\r", 'b'], "a\r\nb\r\n"],
            'line feed then line feed'            => [["a\n", "\nb"], "a\r\n\r\nb\r\n"],
            'blank line after the first chunk'    => [["a\n", "\n"], "a\r\n\r\n"],
            'line break that starts the message'  => [["\na"], "\r\na\r\n"],
            'line break then a chunk'             => [["\n", 'a'], "\r\na\r\n"],
            'dot after a held line break'         => [["\n", '.'], "\r\n..\r\n"],
            'line break at the end of each chunk' => [["a\n", "b\n", "c\n"], "a\r\nb\r\nc\r\n"],
        ];
    }

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function normalizationProvider(): array
    {
        return [
            'CRLF'                      => [["a\r\nb"], ["a\r\nb"]],
            'bare LF'                   => [["a\nb"], ["a\r\nb"]],
            'bare CR'                   => [["a\rb"], ["a\r\nb"]],
            'LF CR'                     => [["a\n\rb"], ["a\r\n\r\nb"]],
            'CRLF split between chunks' => [["a\r", "\nb"], ["a\r\n", 'b']],
            'LF alone after CR'         => [["a\r", "\n", 'b'], ["a\r\n", 'b']],
            'empty chunks'              => [['', 'a', ''], ['a']],
            'LF after a CR two back'    => [
                ["a\r",   'b', "\nc"],
                ["a\r\n", 'b', "\r\nc"],
            ],
        ];
    }
}
