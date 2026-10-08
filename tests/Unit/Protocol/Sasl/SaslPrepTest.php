<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Sasl\SaslPrep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaslPrep::class)]
#[Group('unit')]
final class SaslPrepTest extends TestCase
{
    #[Test]
    #[DataProvider('asciiProvider')]
    public function leavesPrintableAsciiAlone(string $value): void
    {
        static::assertSame($value, (new SaslPrep(normalize: false))->prepare($value, 'password'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function asciiProvider(): array
    {
        return [
            'letters'         => ['pencil'],
            'a space and a ~' => [' a ~'],
            'punctuation'     => ['=,!"#$%&\'()*+-./:;<>?@[\\]^_`{|}'],
        ];
    }

    #[Test]
    #[DataProvider('outsideAsciiProvider')]
    public function refusesTextOutsidePrintableAsciiWithoutIntl(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The SCRAM password must be printable ASCII unless the intl extension is installed',
        );

        (new SaslPrep(normalize: false))->prepare($value, 'password');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function outsideAsciiProvider(): array
    {
        return [
            'an accent'       => ["caf\u{00E9}"],
            'a control first' => ["\x01pencil"],
            'a delete last'   => ["pencil\x7F"],
            'a line break'    => ["pen\ncil"],
        ];
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    #[DataProvider('normalisationProvider')]
    public function normalisesOtherTextToNfkc(string $value, string $expected): void
    {
        static::assertSame($expected, (new SaslPrep(normalize: true))->prepare($value, 'username'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalisationProvider(): array
    {
        return [
            'a roman numeral'         => ["\u{2168}", 'IX'],
            'a no-break space'        => ["a\u{00A0}b", 'a b'],
            'a decomposed accent'     => ["cafe\u{0301}", "caf\u{00E9}"],
            'a letter with a C1 byte' => ["\u{0100}", "\u{0100}"],
        ];
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function normalisesWhenIntlIsInstalled(): void
    {
        static::assertSame('IX', (new SaslPrep())->prepare("\u{2168}", 'username'));
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    #[DataProvider('unpreparedProvider')]
    public function refusesTextThatCannotBePrepared(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The SCRAM username must be UTF-8 text without control characters');

        (new SaslPrep(normalize: true))->prepare($value, 'username');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unpreparedProvider(): array
    {
        return [
            'invalid UTF-8'    => ["caf\xE9"],
            'an ASCII control' => ["\u{00E9}\x01"],
            'a C1 control'     => ["a\u{0085}b"],
        ];
    }
}
