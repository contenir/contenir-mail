<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\EncodedWordDecoder;
use Contenir\Mail\Header\EncodedWords;
use Contenir\Mail\Header\HeaderWrap;
use Contenir\Mail\Tests\Unit\TestAsset\EncodedWordReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function base64_encode;
use function error_clear_last;
use function error_get_last;
use function explode;
use function iconv_mime_decode;
use function max;
use function range;
use function str_repeat;
use function strlen;
use function substr;

use const ICONV_MIME_DECODE_CONTINUE_ON_ERROR;

#[CoversClass(HeaderWrap::class)]
#[CoversClass(EncodedWords::class)]
#[CoversClass(EncodedWordDecoder::class)]
#[Group('unit')]
final class HeaderWrapTest extends TestCase
{
    /**
     * Printable ASCII is folded at 78 characters, counting the "Subject: " in front of it.
     */
    #[Test]
    public function foldsPrintableAsciiValueAtSeventyEightCharacters(): void
    {
        $value = str_repeat('foobarblahblahblah baz bat', times: 4);

        static::assertSame(
            "foobarblahblahblah baz batfoobarblahblahblah baz\r\n"
                . ' batfoobarblahblahblah baz batfoobarblahblahblah baz bat',
            HeaderWrap::fold('Subject', $value),
        );
    }

    #[Test]
    public function leavesShortAsciiValueUnchangedWhenFolding(): void
    {
        static::assertSame('Hello world', HeaderWrap::fold('Subject', 'Hello world'));
    }

    #[Test]
    public function leavesUnbreakableAsciiValueUnchangedWhenFolding(): void
    {
        $value = str_repeat('a', times: 100);

        static::assertSame($value, HeaderWrap::fold('Subject', $value));
    }

    #[Test]
    public function foldsEmptyValueToEmptyString(): void
    {
        static::assertSame('', HeaderWrap::fold('X-Empty', ''));
    }

    #[Test]
    public function encodesNonAsciiValueWhenFolding(): void
    {
        $value = str_repeat('foobarblahblahblah baz bat', times: 3) . 'ä';

        static::assertSame(
            "=?UTF-8?Q?foobarblahblahblah=20baz=20batfoobarblahblahblah=20baz=20?=\r\n"
                . ' =?UTF-8?Q?batfoobarblahblahblah=20baz=20bat=C3=A4?=',
            HeaderWrap::fold('Subject', $value),
        );
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-258
     */
    #[Test]
    public function mimeEncodesLongValueAcrossLines(): void
    {
        $value = str_repeat('foobarblahblahblah baz bat', times: 3);

        static::assertSame(
            "=?UTF-8?Q?foobarblahblahblah=20baz=20batfoobarblahblahblah=20baz=20?=\r\n"
                . ' =?UTF-8?Q?batfoobarblahblahblah=20baz=20bat?=',
            HeaderWrap::mimeEncodeValue($value, firstLineGapSize: 0),
        );
    }

    #[Test]
    public function mimeEncodedLongValueDecodesWithIconv(): void
    {
        $value   = str_repeat('foobarblahblahblah baz bat', times: 3);
        $encoded = HeaderWrap::mimeEncodeValue($value, firstLineGapSize: 0);

        static::assertSame(
            $value,
            iconv_mime_decode($encoded, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, encoding: 'UTF-8'),
        );
    }

    /**
     * @see https://zendframework.com/issues/browse/ZF2-359
     */
    #[Test]
    public function mimeEncodesUmlautAsUtf8(): void
    {
        static::assertSame('=?UTF-8?Q?Umlauts:=20=C3=A4?=', HeaderWrap::mimeEncodeValue(
            'Umlauts: ä',
            firstLineGapSize: 0,
        ));
    }

    #[Test]
    public function mimeEncodedUmlautDecodesWithIconv(): void
    {
        $encoded = HeaderWrap::mimeEncodeValue('Umlauts: ä', firstLineGapSize: 0);

        static::assertSame(
            'Umlauts: ä',
            iconv_mime_decode($encoded, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, encoding: 'UTF-8'),
        );
    }

    #[DataProvider('decodedValueProvider')]
    #[Test]
    public function mimeDecodesValue(string $encoded, string $expected): void
    {
        static::assertSame($expected, HeaderWrap::mimeDecodeValue($encoded));
    }

    /**
     * A tab continuation unfolds to a space, as Headers::fromString() unfolds it, without losing any characters.
     *
     * @see https://github.com/zendframework/zend-mail/pull/187
     */
    #[Test]
    public function mimeDecodeUnfoldsTabContinuationWithoutLosingCharacters(): void
    {
        static::assertSame(
            'v=1; a=rsa-sha25; c=relaxed/simple; d=example.org; h= content-language:content-type:in-reply-to',
            HeaderWrap::mimeDecodeValue(
                "v=1; a=rsa-sha25; c=relaxed/simple; d=example.org; h=\r\n\tcontent-language:content-type:in-reply-to",
            ),
        );
    }

    /**
     * iconv_mime_encode() used to fail with "Unknown error (7)" on this value.
     */
    #[Test]
    public function canEncodeLongValueWithUtf8Character(): void
    {
        $value = '[#77675] New Issue:xxxxxxxxx xxxxxxx xxxxxxxx xxxxxxxxxxxxx xxxxxxxxxx xxxxxxxx, tähtaeg xx.xx, xxxx';

        static::assertTrue(HeaderWrap::canBeEncoded($value));
    }

    #[DataProvider('encodableValueProvider')]
    #[Test]
    public function canEncodeValue(string $value): void
    {
        static::assertTrue(HeaderWrap::canBeEncoded($value));
    }

    #[DataProvider('encodedWordProvider')]
    #[Test]
    public function decoderJoinsAdjacentEncodedWords(string $encoded, string $expected): void
    {
        static::assertSame($expected, EncodedWordDecoder::decode($encoded));
    }

    #[Test]
    public function cannotEncodeInvalidUtf8(): void
    {
        static::assertFalse(HeaderWrap::canBeEncoded("\xFF\xFE"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function decodedValueProvider(): array
    {
        $split = 'аф';

        return [
            'plain text'                                     => ['plain text', 'plain text'],
            'folded encoded words'                           => [
                "=?UTF-8?Q?foobarblahblahblah=20baz=20batfoobarblahblahblah=20baz=20?=\r\n"
                    . ' =?UTF-8?Q?batfoobarblahblahblah=20baz=20bat?=',
                str_repeat('foobarblahblahblah baz bat', times: 3),
            ],
            'adjacent ISO-8859-2 words'                      => [
                '=?ISO-8859-2?Q?PD=3A_My=3A_Go=B3?= =?ISO-8859-2?Q?blahblah?=',
                'PD: My: Gołblahblah',
            ],
            'multibyte character split across words'         => [
                '=?utf-8?B?'
                    . base64_encode(substr($split, offset: 0, length: 3))
                    . '?==?utf-8?B?'
                    . base64_encode(substr($split, offset: 3))
                    . '?=',
                $split,
            ],
            'split multibyte character between plain text'   => [
                'Re: =?utf-8?B?'
                    . base64_encode(substr($split, offset: 0, length: 3))
                    . '?= =?utf-8?B?'
                    . base64_encode(substr($split, offset: 3))
                    . '?= end',
                "Re: {$split} end",
            ],
            'split multibyte character across Q and B words' => [
                '=?utf-8?B?' . base64_encode(substr($split, offset: 0, length: 3)) . '?==?utf-8?Q?=84?=',
                $split,
            ],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function encodedWordProvider(): array
    {
        $first  = base64_encode(substr('аф', offset: 0, length: 3));
        $second = base64_encode(substr('аф', offset: 3));

        return [
            'no encoded words'                    => ['no words', 'no words'],
            'split character between plain text'  => [
                "Re: =?utf-8?B?{$first}?= =?utf-8?B?{$second}?= end",
                'Re: аф end',
            ],
            'charset changes between words'       => [
                "=?utf-8?B?{$first}?= =?utf-8?B?{$second}?= =?ISO-8859-1?Q?caf=E9?=",
                'афcafé',
            ],
            'text between words is kept'          => [
                '=?ISO-8859-1?Q?caf=E9_au_lait?= x =?ISO-8859-1?Q?=E9?=',
                'café au lait x é',
            ],
            'RFC 2231 language suffix is ignored' => ['=?utf-8*en?Q?abc?=', 'abc'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function encodableValueProvider(): array
    {
        return [
            'empty'           => [''],
            'ascii'           => ['Hello world'],
            'utf-8'           => ['Accents òàùèéì'],
            'folded value'    => ["foo\r\n bar"],
            'bare line feeds' => ["xxx yyy\n"],
        ];
    }

    #[DataProvider('phraseProvider')]
    #[Test]
    public function encodesPhraseWithSpecialsEscaped(string $phrase, string $expected): void
    {
        static::assertSame($expected, HeaderWrap::encodePhrase($phrase));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function phraseProvider(): array
    {
        return [
            'plain non-ASCII' => ['Jösé', '=?UTF-8?Q?J=C3=B6s=C3=A9?='],
            'quote and comma' => ['Jösé "Jr", Esq.', '=?UTF-8?Q?J=C3=B6s=C3=A9=20=22Jr=22=2C=20Esq=2E?='],
            'angle brackets'  => ['õlu <bar>', '=?UTF-8?Q?=C3=B5lu=20=3Cbar=3E?='],
            'every special'   => ['é()<>@;:\\[]', '=?UTF-8?Q?=C3=A9=28=29=3C=3E=40=3B=3A=5C=5B=5D?='],
        ];
    }

    #[Test]
    public function keepsAsciiValueThatEndsExactlyAtColumnSeventyEight(): void
    {
        $value = str_repeat('a', times: 60) . ' ' . str_repeat('b', times: 8);

        static::assertSame($value, HeaderWrap::fold('Subject', $value));
    }

    #[Test]
    public function foldsAsciiValueOnePastColumnSeventyEight(): void
    {
        static::assertSame(
            str_repeat('a', times: 60) . "\r\n " . str_repeat('b', times: 9),
            HeaderWrap::fold('Subject', str_repeat('a', times: 60) . ' ' . str_repeat('b', times: 9)),
        );
    }

    #[Test]
    public function foldsEncodedValueAtColumnSeventyEight(): void
    {
        static::assertSame(
            "=?UTF-8?Q?=C3=A9=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20?=\r\n"
                . " =?UTF-8?Q?ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20ab=20?=\r\n"
                . ' =?UTF-8?Q?ab=20ab?=',
            HeaderWrap::fold('Subject', 'é' . str_repeat(' ab', times: 23)),
        );
    }

    /**
     * RFC 2047, section 2: an encoded word is at most 75 characters, so long
     * values, with or without spaces, are split over several words.
     */
    #[DataProvider('longValueProvider')]
    #[Test]
    public function keepsEveryEncodedWordWithinSeventyFiveCharacters(string $value): void
    {
        $lengths = array_map(strlen(...), explode("\r\n ", HeaderWrap::mimeEncodeValue($value, firstLineGapSize: 0)));

        static::assertLessThanOrEqual(75, max($lengths));
    }

    #[DataProvider('longValueProvider')]
    #[Test]
    public function splitsLongValueOnlyBetweenCharacters(string $value): void
    {
        static::assertSame(
            $value,
            HeaderWrap::mimeDecodeValue(HeaderWrap::mimeEncodeValue($value, firstLineGapSize: 0)),
        );
    }

    #[Test]
    public function keepsEveryEncodedPhraseWordWithinSeventyFiveCharacters(): void
    {
        $lengths = array_map(strlen(...), explode("\r\n ", HeaderWrap::encodePhrase(str_repeat('é<>', times: 40))));

        static::assertLessThanOrEqual(75, max($lengths));
    }

    #[Test]
    public function fillsFirstLineToExactlySeventyEightCharacters(): void
    {
        $first = explode("\r\n", HeaderWrap::fold('Subject', 'é' . str_repeat('a', times: 100)))[0];

        static::assertSame(78, strlen("Subject: {$first}"));
    }

    #[Test]
    public function fillsLaterWordsToExactlySeventyFiveCharacters(): void
    {
        $second = explode("\r\n ", HeaderWrap::fold('Subject', 'é' . str_repeat('a', times: 200)))[1];

        static::assertSame(75, strlen($second));
    }

    #[Test]
    public function fillsPhraseWordsToExactlySeventyFiveCharacters(): void
    {
        $first = explode("\r\n ", HeaderWrap::encodePhrase('é' . str_repeat('a', times: 200)))[0];

        static::assertSame(75, strlen($first));
    }

    #[Test]
    public function fitsFirstEncodedWordAfterTheHeaderName(): void
    {
        $first = explode("\r\n", HeaderWrap::fold('X-A-Rather-Long-Header-Name', str_repeat('é', times: 40)))[0];

        static::assertLessThanOrEqual(78, strlen("X-A-Rather-Long-Header-Name: {$first}"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function longValueProvider(): array
    {
        return [
            'spaced'                => ['éy' . str_repeat(' ab', times: 196)],
            'no spaces'             => [str_repeat('é', times: 200)],
            'long run after spaces' => ['ab cd ' . str_repeat('x', times: 150) . 'é'],
            'four-byte characters'  => [str_repeat('😀', times: 60)],
        ];
    }

    /**
     * Multi-byte runs after 0 to 11 ASCII characters, so that a word fills
     * up at every byte of every character: an escaped character is 6, 9 or
     * 12 characters long.
     *
     * @return array<string, array{string}>
     */
    public static function multiByteRunProvider(): array
    {
        $runs = [
            'emoji'                    => str_repeat("\u{1F600}", times: 30),
            'mixed 2-, 3- and 4-byte'  => str_repeat("\u{E9}\u{20AC}\u{1F600}", times: 12),
            '4-, 3- and 2-byte spaced' => str_repeat("\u{1F600}\u{20AC}\u{E9} ", times: 10),
        ];
        $cases = [];
        foreach ($runs as $name => $run) {
            foreach (range(
                start: 0,
                end: 11,
            ) as $offset) {
                $cases["{$name} after {$offset} ASCII"] = [str_repeat('a', $offset) . $run];
            }
        }

        return $cases;
    }

    /**
     * RFC 2047, section 5: each encoded word must hold whole characters.
     */
    #[DataProvider('multiByteRunProvider')]
    #[Test]
    public function keepsEveryCharacterWithinOneEncodedWord(string $value): void
    {
        static::assertSame(
            [],
            EncodedWordReader::wordsWithPartialCharacters(HeaderWrap::mimeEncodeValue($value, firstLineGapSize: 0)),
        );
    }

    #[DataProvider('multiByteRunProvider')]
    #[Test]
    public function keepsEveryCharacterWithinOneEncodedWordAfterAHeaderName(string $value): void
    {
        static::assertSame([], EncodedWordReader::wordsWithPartialCharacters(HeaderWrap::fold('Subject', $value)));
    }

    #[DataProvider('multiByteRunProvider')]
    #[Test]
    public function keepsEveryCharacterWithinOneEncodedPhraseWord(string $value): void
    {
        static::assertSame([], EncodedWordReader::wordsWithPartialCharacters(HeaderWrap::encodePhrase($value)));
    }

    #[DataProvider('multiByteRunProvider')]
    #[Test]
    public function decodesMultiByteRunBackToTheValue(string $value): void
    {
        static::assertSame($value, HeaderWrap::mimeDecodeValue(HeaderWrap::fold('Subject', $value)));
    }

    #[DataProvider('multiByteRunProvider')]
    #[Test]
    public function keepsMultiByteRunWordsWithinSeventyFiveCharacters(string $value): void
    {
        $lengths = array_map(strlen(...), EncodedWordReader::words(HeaderWrap::fold('Subject', $value)));

        static::assertLessThanOrEqual(75, max($lengths));
    }

    #[Test]
    public function startsANewWordBeforeACharacterThatDoesNotFit(): void
    {
        static::assertSame(
            '=?UTF-8?Q?' . str_repeat('a', times: 60) . "?=\r\n =?UTF-8?Q?=F0=9F=98=80?=",
            HeaderWrap::mimeEncodeValue(str_repeat('a', times: 60) . "\u{1F600}", firstLineGapSize: 0),
        );
    }

    /**
     * "Name: " and the first character, as an encoded word, fill the first line to 78 characters.
     */
    #[Test]
    public function keepsFirstCharacterThatFitsAfterTheName(): void
    {
        static::assertSame(
            "=?UTF-8?Q?=C3=A9?=\r\n =?UTF-8?Q?=C3=A9?=",
            HeaderWrap::fold(str_repeat('X', times: 58), "\u{E9}\u{E9}"),
        );
    }

    #[Test]
    public function startsValueOnNextLineWhenTheFirstCharacterDoesNotFit(): void
    {
        static::assertSame(
            "\r\n =?UTF-8?Q?=C3=A9=C3=A9?=",
            HeaderWrap::fold(str_repeat('X', times: 59), "\u{E9}\u{E9}"),
        );
    }

    #[Test]
    public function foldsEmptyValueAfterLongestNameToEmptyString(): void
    {
        static::assertSame('', HeaderWrap::fold(str_repeat('X', times: 997), ''));
    }

    #[Test]
    public function encodesEmptyValueAsNothing(): void
    {
        static::assertSame('', HeaderWrap::mimeEncodeValue('', firstLineGapSize: 0));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function lineProvider(): array
    {
        $longest = str_repeat('X', times: 997);

        return [
            'value on the first line'          => ['Subject', 'Hello', 'Subject: Hello'],
            'value on the next line'           => ['X-Test', "\r\n =?UTF-8?Q?a?=", "X-Test:\r\n =?UTF-8?Q?a?="],
            'empty value'                      => ['X-Test', '', 'X-Test: '],
            'empty value after 996 characters' => [str_repeat('X', times: 996), '', str_repeat('X', times: 996) . ': '],
            'empty value after 997 characters' => [$longest, '', "{$longest}:"],
            'value after 997 characters'       => [$longest, "\r\n v", "{$longest}:\r\n v"],
            'line break without white space'   => ['X-Test', "\r\nv", "X-Test: \r\nv"],
        ];
    }

    #[DataProvider('lineProvider')]
    #[Test]
    public function joinsNameAndFoldedValueIntoALine(string $name, string $folded, string $expected): void
    {
        static::assertSame($expected, HeaderWrap::line($name, $folded));
    }

    #[Test]
    public function decodesUnknownCharsetWithoutRaisingAWarning(): void
    {
        error_clear_last();
        EncodedWordDecoder::decode('=?X-UNKNOWN?Q?abc?=');

        static::assertNull(error_get_last());
    }

    #[DataProvider('decoderEdgeCaseProvider')]
    #[Test]
    public function decodesEncodedWordEdgeCases(string $encoded, string $expected): void
    {
        static::assertSame($expected, EncodedWordDecoder::decode($encoded));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function decoderEdgeCaseProvider(): array
    {
        return [
            'text around several words'                          => ['x =?UTF-8?Q?a?= y =?UTF-8?Q?b?=', 'x a y b'],
            'lower-case B scheme'                                => ['=?UTF-8?b?w6k=?=', 'é'],
            'stray character in base64'                          => ['=?UTF-8?B?w6k*?=', 'é'],
            'charset case differs between words'                 => ['=?utf-8?B?0LDR?= =?UTF-8?Q?=84?=', 'аф'],
            'unknown charset left as it is'                      => ['=?X-UNKNOWN?Q?abc?=', 'abc'],
            'multibyte charset split across words in mixed case' => ['=?shift_jis?Q?=82?= =?SHIFT_JIS?Q?=A0?=', 'あ'],
        ];
    }

    /**
     * "Subject: " and a 989-character word make a line of exactly 998.
     */
    #[Test]
    public function keepsWordThatFitsLineLimit(): void
    {
        static::assertSame(str_repeat('a', times: 989), HeaderWrap::fold('Subject', str_repeat('a', times: 989)));
    }

    #[Test]
    public function keepsWordOnFoldedLineThatFitsLineLimit(): void
    {
        static::assertSame(
            "short\r\n " . str_repeat('a', times: 997),
            HeaderWrap::fold('Subject', 'short ' . str_repeat('a', times: 997)),
        );
    }

    /**
     * @param non-empty-string $value
     */
    #[DataProvider('overlongWordProvider')]
    #[Test]
    public function encodesValueWithWordTooLongForLineLimit(string $value): void
    {
        $folded = HeaderWrap::fold('Subject', $value);

        static::assertSame(
            [$value, true],
            [
                HeaderWrap::mimeDecodeValue($folded),
                max(array_map(strlen(...), explode("\r\n", "Subject: {$folded}"))) <= 78,
            ],
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function overlongWordProvider(): array
    {
        return [
            'first line one over'  => [str_repeat('a', times: 990)],
            'later line one over'  => ['short ' . str_repeat('a', times: 998)],
            'long URL in sentence' => ['See https://example.com/' . str_repeat('path/', times: 400) . ' for details'],
        ];
    }
}
