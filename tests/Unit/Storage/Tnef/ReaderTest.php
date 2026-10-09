<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Tnef;

use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Tnef\AttachmentRecord;
use Contenir\Mail\Storage\Tnef\ByteReader;
use Contenir\Mail\Storage\Tnef\CompressedRtf;
use Contenir\Mail\Storage\Tnef\Contents;
use Contenir\Mail\Storage\Tnef\Dictionary;
use Contenir\Mail\Storage\Tnef\MapiProperties;
use Contenir\Mail\Storage\Tnef\Parser;
use Contenir\Mail\Storage\Tnef\Properties;
use Contenir\Mail\Storage\Tnef\Reader;
use Contenir\Mail\Storage\Tnef\Text;
use Contenir\Mail\Storage\Tnef\TnefAttachment;
use Contenir\Mail\Tests\TestAsset\RtfBuilder;
use Contenir\Mail\Tests\TestAsset\TnefBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function pack;
use function str_repeat;
use function strlen;
use function substr;

#[CoversClass(Reader::class)]
#[CoversClass(Parser::class)]
#[CoversClass(AttachmentRecord::class)]
#[CoversClass(TnefAttachment::class)]
#[CoversClass(Contents::class)]
#[CoversClass(ByteReader::class)]
#[CoversClass(MapiProperties::class)]
#[CoversClass(Properties::class)]
#[CoversClass(Text::class)]
#[CoversClass(CompressedRtf::class)]
#[CoversClass(Dictionary::class)]
#[Group('unit')]
final class ReaderTest extends TestCase
{
    #[Test]
    public function readsTheAttachmentsOfAnOutlookMessage(): void
    {
        static::assertSame(
            [
                ['Quarterly report 2026.pdf', "%PDF-1.4\n%quarterly figures\n%%EOF\n", 'application/pdf'],
                ['notes.txt',                 "Remember the milk.\r\n",                'application/octet-stream'],
            ],
            self::describe((new Reader())->read(TnefBuilder::outlookMessage())),
        );
    }

    #[Test]
    public function readsThePlainTextBodyOfAnOutlookMessage(): void
    {
        static::assertSame(
            "Please see the attached files.\r\n",
            (new Reader())->read(TnefBuilder::outlookMessage())->text,
        );
    }

    #[Test]
    public function decompressesTheRtfBodyOfAnOutlookMessage(): void
    {
        static::assertSame(
            '{\rtf1\ansi Please see the attached files.}',
            (new Reader())->read(TnefBuilder::outlookMessage())->rtf,
        );
    }

    #[Test]
    public function readsAnEmptyContainerAsHoldingNothing(): void
    {
        $contents = (new Reader())->read(TnefBuilder::create()->build());

        static::assertSame([[], null, null], [$contents->attachments, $contents->text, $contents->rtf]);
    }

    #[Test]
    public function readsTheBodyFromPrBodyWhenThereIsNoAttBody(): void
    {
        $tnef = TnefBuilder::create()
            ->messageProperties(TnefBuilder::property(
                TnefBuilder::TYPE_UNICODE,
                TnefBuilder::PR_BODY,
                TnefBuilder::unicode('Grüße'),
            ))
            ->build();

        static::assertSame('Grüße', (new Reader())->read($tnef)->text);
    }

    #[Test]
    public function prefersAttBodyToPrBody(): void
    {
        $tnef = TnefBuilder::create()
            ->body('From attBody')
            ->messageProperties(TnefBuilder::property(
                TnefBuilder::TYPE_STRING8,
                TnefBuilder::PR_BODY,
                "From PR_BODY\0",
            ))
            ->build();

        static::assertSame('From attBody', (new Reader())->read($tnef)->text);
    }

    #[Test]
    public function endsTextAtItsFirstNul(): void
    {
        $tnef = TnefBuilder::create()->message(TnefBuilder::ATT_BODY, "Hello\0left over\0")->build();

        static::assertSame('Hello', (new Reader())->read($tnef)->text);
    }

    #[Test]
    public function readsTextWithoutANulTerminator(): void
    {
        $tnef = TnefBuilder::create()->message(TnefBuilder::ATT_BODY, 'Hello')->build();

        static::assertSame('Hello', (new Reader())->read($tnef)->text);
    }

    #[Test]
    public function readsAnEmptyTextWhenItStartsWithNul(): void
    {
        $tnef = TnefBuilder::create()->message(TnefBuilder::ATT_BODY, "\0Hidden")->build();

        static::assertSame('', (new Reader())->read($tnef)->text);
    }

    #[Test]
    public function replacesInvalidUtf8InText(): void
    {
        $tnef = TnefBuilder::create()->codepage(65_001)->body("caf\xC3")->build();

        static::assertSame("caf\u{FFFD}", (new Reader())->read($tnef)->text);
    }

    #[DataProvider('codepageProvider')]
    #[Test]
    public function readsTextInTheMessageCodepage(?int $codepage, string $bytes, string $expected): void
    {
        $builder = TnefBuilder::create();
        if (null !== $codepage) {
            $builder->codepage($codepage);
        }

        static::assertSame($expected, (new Reader())->read($builder->body($bytes)->build())->text);
    }

    /**
     * @return array<string, array{int|null, string, string}>
     */
    public static function codepageProvider(): array
    {
        return [
            'no codepage, Windows-1252'      => [null, "caf\xE9 \x80", 'café €'],
            'unknown codepage, Windows-1252' => [437, "caf\xE9", 'café'],
            '874, Thai'                      => [874, "\xA1", 'ก'],
            '932, Shift JIS'                 => [932, "\x82\xA0", 'あ'],
            '936, GBK'                       => [936, "\xC4\xE3", '你'],
            '949, Korean'                    => [949, "\xB0\xA1", '가'],
            '950, Big5'                      => [950, "\xA4\xA4", '中'],
            '1250, Central European'         => [1250, "\xE8", 'č'],
            '1251, Cyrillic'                 => [1251, "\xCF\xF0\xE8", 'При'],
            '1252, Western'                  => [1252, "\xE8", 'è'],
            '1253, Greek'                    => [1253, "\xE1", 'α'],
            '1254, Turkish'                  => [1254, "\xFD", 'ı'],
            '1255, Hebrew'                   => [1255, "\xE0", 'א'],
            '1256, Arabic'                   => [1256, "\xC7", 'ا'],
            '1257, Baltic'                   => [1257, "\xE0", 'ą'],
            '1258, Vietnamese'               => [1258, "\xD2", "\u{0309}"],
            '20866, KOI8-R'                  => [20_866, "\xC1", 'а'],
            '28591, Latin-1'                 => [28_591, "\x80", "\u{0080}"],
            '65001, UTF-8'                   => [65_001, 'Grüße', 'Grüße'],
        ];
    }

    #[Test]
    public function readsTheTitleInTheMessageCodepage(): void
    {
        $tnef = TnefBuilder::create()->codepage(1251)->attachment("\xCF\xF0\xE8.txt", 'x')->build();

        static::assertSame('При.txt', (new Reader())->read($tnef)->attachments[0]->filename);
    }

    #[Test]
    public function readsAnEightBitLongFilenameInTheMessageCodepage(): void
    {
        $tnef = TnefBuilder::create()
            ->codepage(1252)
            ->attachment('SHORT~1.TXT', 'x', TnefBuilder::property(
                TnefBuilder::TYPE_STRING8,
                TnefBuilder::PR_ATTACH_LONG_FILENAME,
                "R\xE9sum\xE9 for review.txt\0",
            ))
            ->build();

        static::assertSame('Résumé for review.txt', (new Reader())->read($tnef)->attachments[0]->filename);
    }

    #[Test]
    public function usesTheTitleWhenTheLongFilenameIsNotText(): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('TITLE.TXT', 'x', TnefBuilder::property(
                TnefBuilder::TYPE_BINARY,
                TnefBuilder::PR_ATTACH_LONG_FILENAME,
                'binary.txt',
            ))
            ->build();

        static::assertSame('TITLE.TXT', (new Reader())->read($tnef)->attachments[0]->filename);
    }

    #[Test]
    public function namesAnAttachmentWithoutTitleOrLongFilenameAttachment(): void
    {
        $tnef = TnefBuilder::create()->attachment(null, 'x')->build();

        static::assertSame('attachment', (new Reader())->read($tnef)->attachments[0]->filename);
    }

    #[DataProvider('unsafeFilenameProvider')]
    #[Test]
    public function makesFilenamesSafe(?string $title, ?string $longFilename, string $expected): void
    {
        $properties = null === $longFilename
            ? []
            : [TnefBuilder::property(
                TnefBuilder::TYPE_UNICODE,
                TnefBuilder::PR_ATTACH_LONG_FILENAME,
                TnefBuilder::unicode($longFilename),
            )];
        $tnef = TnefBuilder::create()->codepage(65_001)->attachment($title, 'x', ...$properties)->build();

        static::assertSame($expected, (new Reader())->read($tnef)->attachments[0]->filename);
    }

    /**
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function unsafeFilenameProvider(): array
    {
        return [
            'path in the long filename' => [null, '../../etc/passwd', 'passwd'],
            'Windows path in the title' => ['..\\..\\boot.ini', null, 'boot.ini'],
            'bidirectional override'    => [null, "invoice\u{202E}fdp.exe", 'invoice fdp.exe'],
            'control character'         => ["a\x07b.txt", null, 'a b.txt'],
            'reserved characters'       => [null, 'a<b>:c?.txt', 'a_b__c_.txt'],
            'only dots'                 => ['...', null, 'attachment'],
        ];
    }

    #[DataProvider('mediaTypeProvider')]
    #[Test]
    public function keepsOnlyAPlainMediaType(string $given, string $expected): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('a.bin', 'x', TnefBuilder::property(
                TnefBuilder::TYPE_STRING8,
                TnefBuilder::PR_ATTACH_MIME_TAG,
                "{$given}\0",
            ))
            ->build();

        static::assertSame($expected, (new Reader())->read($tnef)->attachments[0]->type);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function mediaTypeProvider(): array
    {
        return [
            'plain type'             => ['application/pdf', 'application/pdf'],
            'upper case'             => ['Image/PNG', 'image/png'],
            'with symbols'           => [
                'application/vnd.ms-excel.sheet.macroEnabled.12',
                'application/vnd.ms-excel.sheet.macroenabled.12',
            ],
            'with parameter'         => ['text/html; charset=utf-8', 'application/octet-stream'],
            'trailing line break'    => ["image/png\n", 'application/octet-stream'],
            'header injection'       => ["image/png\r\nX-Evil: 1", 'application/octet-stream'],
            'no subtype'             => ['image/', 'application/octet-stream'],
            'no type'                => ['/png', 'application/octet-stream'],
            'leading symbol'         => ['+image/png', 'application/octet-stream'],
            'subtype leading symbol' => ['image/+png', 'application/octet-stream'],
            'leading space'          => [' image/png', 'application/octet-stream'],
            'empty'                  => ['', 'application/octet-stream'],
        ];
    }

    #[Test]
    public function ignoresAMediaTypeThatIsNotText(): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('a.bin', 'x', TnefBuilder::property(
                TnefBuilder::TYPE_BINARY,
                TnefBuilder::PR_ATTACH_MIME_TAG,
                'image/png',
            ))
            ->build();

        static::assertSame('application/octet-stream', (new Reader())->read($tnef)->attachments[0]->type);
    }

    #[Test]
    public function readsTheDataFromPrAttachDataBinWhenThereIsNoAttAttachData(): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('a.bin', null, TnefBuilder::property(
                TnefBuilder::TYPE_BINARY,
                TnefBuilder::PR_ATTACH_DATA_BIN,
                'from the property',
            ))
            ->build();

        static::assertSame('from the property', (new Reader())->read($tnef)->attachments[0]->content);
    }

    #[Test]
    public function prefersAttAttachDataToPrAttachDataBin(): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('a.bin', 'from the attribute', TnefBuilder::property(
                TnefBuilder::TYPE_BINARY,
                TnefBuilder::PR_ATTACH_DATA_BIN,
                'from the property',
            ))
            ->build();

        static::assertSame('from the attribute', (new Reader())->read($tnef)->attachments[0]->content);
    }

    #[Test]
    public function readsAnAttachmentWithoutDataAsEmpty(): void
    {
        $tnef = TnefBuilder::create()->attachment('a.bin', null)->build();

        static::assertSame('', (new Reader())->read($tnef)->attachments[0]->content);
    }

    #[Test]
    public function ignoresPrAttachDataBinThatIsNotBinary(): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('a.bin', null, TnefBuilder::property(
                TnefBuilder::TYPE_STRING8,
                TnefBuilder::PR_ATTACH_DATA_BIN,
                "text\0",
            ))
            ->build();

        static::assertSame('', (new Reader())->read($tnef)->attachments[0]->content);
    }

    #[Test]
    public function ignoresPrRtfCompressedThatIsNotBinary(): void
    {
        $tnef = TnefBuilder::create()
            ->messageProperties(TnefBuilder::property(
                TnefBuilder::TYPE_STRING8,
                TnefBuilder::PR_RTF_COMPRESSED,
                "{\\rtf1}\0",
            ))
            ->build();

        static::assertNull((new Reader())->read($tnef)->rtf);
    }

    #[Test]
    public function ignoresPrBodyThatIsNotText(): void
    {
        $tnef = TnefBuilder::create()
            ->messageProperties(TnefBuilder::property(TnefBuilder::TYPE_BINARY, TnefBuilder::PR_BODY, 'bytes'))
            ->build();

        static::assertNull((new Reader())->read($tnef)->text);
    }

    #[Test]
    public function ignoresAttributesItDoesNotUse(): void
    {
        $tnef = TnefBuilder::create()
            ->message(0x0003_8005, 'dates and the like')
            ->message(TnefBuilder::ATT_ATTACH_DATA, 'an attachment attribute at the message level')
            ->startAttachment()
            ->attribute(TnefBuilder::LEVEL_ATTACHMENT, 0x0006_8011, 'a metafile')
            ->attribute(TnefBuilder::LEVEL_ATTACHMENT, TnefBuilder::ATT_BODY, 'a message attribute on an attachment')
            ->build();

        static::assertSame(
            [['attachment', '', 'application/octet-stream']],
            self::describe((new Reader())->read($tnef)),
        );
    }

    #[Test]
    public function givesEachAttachmentTheAttributesThatFollowItsStart(): void
    {
        $tnef = TnefBuilder::create()
            ->attachment('one.txt', '1')
            ->attachment('two.txt', '2')
            ->attachment('three.txt', '3')
            ->build();

        static::assertSame(
            [
                ['one.txt',   '1', 'application/octet-stream'],
                ['two.txt',   '2', 'application/octet-stream'],
                ['three.txt', '3', 'application/octet-stream'],
            ],
            self::describe((new Reader())->read($tnef)),
        );
    }

    #[Test]
    public function acceptsChecksumsThatWrapPast65535(): void
    {
        $data = str_repeat("\xFF", times: 300);
        $tnef = TnefBuilder::create()->attachment('big.bin', $data)->build();

        static::assertSame($data, (new Reader())->read($tnef)->attachments[0]->content);
    }

    #[DataProvider('malformedProvider')]
    #[Test]
    public function refusesMalformedData(string $bytes, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new Reader())->read($bytes);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedProvider(): array
    {
        $notTnef = 'The data is not TNEF: it does not start with the TNEF signature';
        $empty   = TnefBuilder::create()->build();
        $record  = TnefBuilder::create()->body('Hello')->build();

        return [
            'empty'                                      => ['', $notTnef],
            'signature only'                             => [pack('V', TnefBuilder::SIGNATURE), $notTnef],
            'signature and half the key'                 => [pack('V', TnefBuilder::SIGNATURE) . "\x34", $notTnef],
            'wrong signature'                            => [TnefBuilder::create()->build(0x223E_9F79), $notTnef],
            'byte-swapped signature'                     => [pack('N', TnefBuilder::SIGNATURE) . "\0\0", $notTnef],
            'truncated record header'                    => [
                "{$empty}\x01\x0C\x80",
                'The TNEF data ends early: 4 bytes are needed at offset 7, but 2 remain',
            ],
            'length past the end'                        => [
                $empty . pack('CVV', 1, TnefBuilder::ATT_BODY, 100) . 'short',
                'The TNEF data ends early: 100 bytes are needed at offset 15, but 5 remain',
            ],
            'length of 4 GiB'                            => [
                $empty . pack('CVV', 1, TnefBuilder::ATT_BODY, 0xFFFF_FFFF),
                'The TNEF data ends early: 4294967295 bytes are needed at offset 15, but 0 remain',
            ],
            'missing checksum'                           => [
                substr($record, offset: 0, length: strlen($record) - 2),
                'The TNEF data ends early: 2 bytes are needed at offset 21, but 0 remain',
            ],
            'half a checksum'                            => [
                substr($record, offset: 0, length: strlen($record) - 1),
                'The TNEF data ends early: 2 bytes are needed at offset 21, but 1 remain',
            ],
            'wrong checksum'                             => [
                TnefBuilder::create()->attribute(1, TnefBuilder::ATT_BODY, 'ab', 0x00C4)->build(),
                'The TNEF attribute 0x0002800C fails its checksum: 0x00C4 was given, 0x00C3 is right',
            ],
            'unknown level'                              => [
                TnefBuilder::create()->attribute(3, TnefBuilder::ATT_BODY, 'x')->build(),
                'A TNEF attribute has the unknown level 3',
            ],
            'level zero'                                 => [
                TnefBuilder::create()->attribute(0, TnefBuilder::ATT_BODY, 'x')->build(),
                'A TNEF attribute has the unknown level 0',
            ],
            'attachment attribute before any attachment' => [
                TnefBuilder::create()->attribute(2, TnefBuilder::ATT_ATTACH_TITLE, "a.txt\0")->build(),
                'The TNEF attachment attribute 0x00018010 comes before any attachment starts',
            ],
            'short codepage'                             => [
                TnefBuilder::create()->message(TnefBuilder::ATT_OEM_CODEPAGE, "\xE4\x04")->build(),
                'The TNEF data ends early: 4 bytes are needed at offset 0, but 2 remain',
            ],
            'malformed message properties'               => [
                TnefBuilder::create()->message(TnefBuilder::ATT_MSG_PROPS, pack('V', 5))->build(),
                'A MAPI count of 5 is more than the 0 bytes left can hold',
            ],
            'malformed attachment properties'            => [
                TnefBuilder::create()
                    ->startAttachment()
                    ->attribute(2, TnefBuilder::ATT_ATTACHMENT, pack('V', 1) . pack('vv', 0x0999, 0x3707))
                    ->build(),
                'A MAPI property has the unknown type 0x0999',
            ],
            'malformed compressed RTF'                   => [
                TnefBuilder::create()
                    ->messageProperties(TnefBuilder::property(
                        TnefBuilder::TYPE_BINARY,
                        TnefBuilder::PR_RTF_COMPRESSED,
                        RtfBuilder::compressed(['{}'], crc: 1),
                    ))
                    ->build(),
                'The compressed RTF fails its CRC check',
            ],
        ];
    }

    #[Test]
    public function readsAsManyAttachmentsAsTheLimit(): void
    {
        $tnef = TnefBuilder::create()->attachment('a', 'a')->attachment('b', 'b')->build();

        static::assertCount(2, (new Reader(maxAttachments: 2))->read($tnef)->attachments);
    }

    #[Test]
    public function refusesMoreAttachmentsThanTheLimit(): void
    {
        $tnef = TnefBuilder::create()->attachment('a', 'a')->attachment('b', 'b')->build();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The TNEF data holds more than 1 attachments');

        (new Reader(maxAttachments: 1))->read($tnef);
    }

    #[Test]
    public function refusesMoreAttachmentsThanTheDefaultLimit(): void
    {
        $builder = TnefBuilder::create();
        for ($index = 0; $index <= Reader::MAX_ATTACHMENTS; $index++) {
            $builder->startAttachment();
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The TNEF data holds more than 100 attachments');

        (new Reader())->read($builder->build());
    }

    #[Test]
    public function readsOutputOfExactlyTheByteLimit(): void
    {
        $contents = (new Reader(maxBytes: 51))->read(self::sizedContainer());

        static::assertSame(
            [10, 10, 10, 21],
            [
                strlen($contents->attachments[0]->content),
                strlen($contents->attachments[1]->content),
                strlen($contents->text ?? ''),
                strlen($contents->rtf ?? ''),
            ],
        );
    }

    #[DataProvider('byteLimitProvider')]
    #[Test]
    public function refusesOutputPastTheByteLimit(int $maxBytes, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        (new Reader(maxBytes: $maxBytes))->read(self::sizedContainer());
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function byteLimitProvider(): array
    {
        return [
            'in the first attachment'  => [9, 'The TNEF data holds more than the 9 bytes allowed'],
            'in the second attachment' => [19, 'The TNEF data holds more than the 19 bytes allowed'],
            'in the text'              => [29, 'The TNEF data holds more than the 29 bytes allowed'],
            'in the RTF'               => [50, 'The compressed RTF holds 21 bytes, more than the 20 allowed'],
        ];
    }

    #[Test]
    public function readsWithTheSmallestLimits(): void
    {
        $tnef = TnefBuilder::create()->attachment('a', 'x')->build();

        static::assertSame(
            [['a', 'x', 'application/octet-stream']],
            self::describe((new Reader(
                maxAttachments: 1,
                maxBytes: 1,
            ))->read($tnef)),
        );
    }

    #[DataProvider('invalidLimitProvider')]
    #[Test]
    public function refusesLimitsBelowOne(int $maxAttachments, int $maxBytes, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Reader($maxAttachments, $maxBytes);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function invalidLimitProvider(): array
    {
        return [
            'no attachments' => [0, 1, 'TNEF limits must be at least 1; 0 attachments and 1 bytes were given'],
            'no bytes'       => [1, 0, 'TNEF limits must be at least 1; 1 attachments and 0 bytes were given'],
        ];
    }

    #[Test]
    public function allowsSixtyFourMebibytesByDefault(): void
    {
        $rtf  = RtfBuilder::header('', Reader::MAX_BYTES + 1, RtfBuilder::MELA, 0);
        $tnef = TnefBuilder::create()
            ->messageProperties(TnefBuilder::property(TnefBuilder::TYPE_BINARY, TnefBuilder::PR_RTF_COMPRESSED, $rtf))
            ->build();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The compressed RTF holds 67108865 bytes, more than the 67108864 allowed');

        (new Reader())->read($tnef);
    }

    /**
     * Two 10-byte attachments, a 10-byte body and 21 bytes of RTF: 51 bytes of output.
     */
    private static function sizedContainer(): string
    {
        return TnefBuilder::create()
            ->body('0123456789')
            ->messageProperties(TnefBuilder::property(
                TnefBuilder::TYPE_BINARY,
                TnefBuilder::PR_RTF_COMPRESSED,
                RtfBuilder::stored('{\rtf1 ten byte more}'),
            ))
            ->attachment('a', 'aaaaaaaaaa')
            ->attachment('b', 'bbbbbbbbbb')
            ->build();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private static function describe(Contents $contents): array
    {
        return array_map(
            static fn(TnefAttachment $attachment): array => [
                $attachment->filename,
                $attachment->content,
                $attachment->type,
            ],
            $contents->attachments,
        );
    }
}
