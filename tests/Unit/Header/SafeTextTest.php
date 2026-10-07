<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Header\Subject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

#[CoversClass(SafeText::class)]
#[Group('unit')]
final class SafeTextTest extends TestCase
{
    /**
     * Path traversal and spoofing: attachment names are reduced to a harmless base name.
     */
    #[DataProvider('filenameProvider')]
    #[Test]
    public function reducesFilenameToSafeBaseName(string $filename, string $expected): void
    {
        static::assertSame($expected, SafeText::filename($filename));
    }

    #[Test]
    public function shortensLongFilenameToTheByteLimitKeepingItsExtension(): void
    {
        static::assertSame(
            str_repeat('a', times: 251) . '.pdf',
            SafeText::filename(str_repeat('a', times: 300) . '.pdf'),
        );
    }

    #[Test]
    public function keepsTheStartOfLongFilename(): void
    {
        static::assertSame(
            'b' . str_repeat('a', times: 250) . '.pdf',
            SafeText::filename('b' . str_repeat('a', times: 299) . '.pdf'),
        );
    }

    #[Test]
    public function keepsExtensionOfTheLengthLimit(): void
    {
        $extension = '.' . str_repeat('x', times: SafeText::MAX_EXTENSION_BYTES - 1);

        static::assertStringEndsWith($extension, SafeText::filename(str_repeat('a', times: 300) . $extension));
    }

    #[Test]
    public function keepsFilenameOfExactlyTheLimit(): void
    {
        $name = str_repeat('a', times: SafeText::MAX_FILENAME_BYTES);

        static::assertSame($name, SafeText::filename($name));
    }

    #[Test]
    public function shortensLongMultibyteFilenameWithoutSplittingACharacter(): void
    {
        $safe = SafeText::filename(str_repeat('é', times: 200));

        static::assertSame(str_repeat('é', times: 127), $safe);
    }

    #[Test]
    public function dropsOverlongExtensionWhenShortening(): void
    {
        static::assertSame(
            SafeText::MAX_FILENAME_BYTES,
            strlen(SafeText::filename('a.' . str_repeat('b', times: 300))),
        );
    }

    /**
     * Spoofing: bidirectional overrides and controls cannot reorder or hide text in a display name.
     */
    #[DataProvider('displayProvider')]
    #[Test]
    public function makesTextSafeToDisplay(string $text, string $expected): void
    {
        static::assertSame($expected, SafeText::display($text));
    }

    /**
     * Spoofing: an encoded word in a received header can carry a bidirectional override.
     */
    #[Test]
    public function makesDecodedHeaderTextSafeToDisplay(): void
    {
        $subject = Subject::fromString('Subject: =?UTF-8?Q?Bank=E2=80=AEmoc.evil?=');

        static::assertSame('Bank moc.evil', SafeText::display($subject->getFieldValue()));
    }

    /**
     * Address keeps the right-to-left mark, which right-to-left names can need, so display removes it.
     */
    #[Test]
    public function makesDisplayNamesOfAddressesSafe(): void
    {
        $addresses = new AddressList(new Address('a@example.com', "Bank\u{200F}moc.evil"));

        static::assertSame('Bank moc.evil', SafeText::addressList($addresses)->first()?->getName());
    }

    /**
     * Address allows tabs and letter marks in a comment; display turns them into single spaces.
     */
    #[Test]
    public function makesCommentsOfAddressesSafe(): void
    {
        $addresses = new AddressList(new Address('a@example.com', null, "x\t\u{061C}y"));

        static::assertSame('x y', SafeText::addressList($addresses)->first()?->getComment());
    }

    #[Test]
    public function keepsAddressWithoutNameUnnamed(): void
    {
        $addresses = new AddressList(new Address('a@example.com'));

        static::assertNull(SafeText::addressList($addresses)->first()?->getName());
    }

    #[Test]
    public function keepsEmailOfAddresses(): void
    {
        $addresses = new AddressList(new Address('a@example.com', 'A'));

        static::assertSame('a@example.com', SafeText::addressList($addresses)->first()?->getEmail());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function filenameProvider(): array
    {
        return [
            'plain'                  => ['report.pdf', 'report.pdf'],
            'unix traversal'         => ['../../etc/passwd', 'passwd'],
            'absolute path'          => ['/etc/passwd', 'passwd'],
            'windows path'           => ['C:\\Windows\\system32\\cmd.exe', 'cmd.exe'],
            'mixed separators'       => ['a/b\\c.txt', 'c.txt'],
            'dot dot only'           => ['..', 'attachment'],
            'empty'                  => ['', 'attachment'],
            'trailing separator'     => ['dir/', 'attachment'],
            'hidden file'            => ['.htaccess', 'htaccess'],
            'trailing dots'          => ['virus.exe. . ', 'virus.exe'],
            'NUL byte'               => ["a.txt\0.exe", 'a.txt .exe'],
            'line break'             => ["a\r\nb.txt", 'a b.txt'],
            'right-to-left override' => ["invoice\u{202E}fdp.exe", 'invoice fdp.exe'],
            'isolate'                => ["a\u{2067}b.txt", 'a b.txt'],
            'C1 control'             => ["a\u{0085}b.txt", 'a b.txt'],
            'windows reserved'       => ['a<b>c:d"e|f?g*h.txt', 'a_b_c_d_e_f_g_h.txt'],
            'invalid UTF-8'          => ["caf\xE9.txt", "caf\u{FFFD}.txt"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function displayProvider(): array
    {
        return [
            'plain'                => ['Alice Example', 'Alice Example'],
            'override'             => ["Alice\u{202E}gnp.exe", 'Alice gnp.exe'],
            'left-to-right mark'   => ["a\u{200E}b", 'a b'],
            'arabic letter mark'   => ["a\u{061C}b", 'a b'],
            'whitespace collapsed' => ["  a \t\n b  ", 'a b'],
            'escape sequence'      => ["\e[31mred", '[31mred'],
            'non-ASCII kept'       => ['Jösé Müller', 'Jösé Müller'],
        ];
    }
}
