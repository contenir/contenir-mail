<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Tnef;

use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Tnef\ByteReader;
use Contenir\Mail\Storage\Tnef\CompressedRtf;
use Contenir\Mail\Storage\Tnef\Dictionary;
use Contenir\Mail\Tests\Unit\TestAsset\RtfBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function hex2bin;
use function pack;
use function range;
use function str_repeat;
use function strlen;
use function substr;

#[CoversClass(CompressedRtf::class)]
#[CoversClass(Dictionary::class)]
#[CoversClass(ByteReader::class)]
#[Group('unit')]
final class CompressedRtfTest extends TestCase
{
    /**
     * The example of MS-OXRTFCP section 4.1, as Outlook writes it.
     */
    #[Test]
    public function decompressesTheSpecificationExample(): void
    {
        $compressed = (string) hex2bin(
            '2D0000002B0000004C5A4675F1C5C7A703000A00726370673132354232'
                . '0AF32068656C090020627705B06C647D0A800FA0',
        );

        static::assertSame(
            "{\\rtf1\\ansi\\ansicpg1252\\pard hello world}\r\n",
            CompressedRtf::decompress($compressed, 1000),
        );
    }

    #[Test]
    public function copiesAReferenceFromThePrebuffer(): void
    {
        static::assertSame('{\rtf1\ansi}', CompressedRtf::decompress(RtfBuilder::compressed([[0, 11], '}']), 100));
    }

    #[Test]
    public function copiesAReferenceThatOverlapsTheBytesItWrites(): void
    {
        $offset = RtfBuilder::PREBUFFER_LENGTH;

        static::assertSame('abababab', CompressedRtf::decompress(RtfBuilder::compressed([
            'ab',
            [$offset, 6],
        ]), 100));
    }

    #[Test]
    public function copiesTheLongestReference(): void
    {
        static::assertSame(
            '{\rtf1\ansi\mac\d',
            CompressedRtf::decompress(RtfBuilder::compressed([[0, 17]]), 100),
        );
    }

    #[Test]
    public function readsReferencesAfterTheDictionaryWraps(): void
    {
        $literals = '';
        foreach (range(
            start: 0,
            end: 3999,
        ) as $index) {
            $literals .= pack('C', ($index * 7) % 251);
        }

        static::assertSame(
            $literals . substr($literals, offset: 4000 - RtfBuilder::PREBUFFER_LENGTH, length: 3),
            CompressedRtf::decompress(RtfBuilder::compressed([$literals, [4000, 3]]), 5000),
        );
    }

    #[Test]
    public function readsAReferenceToTheLastByteOfTheDictionary(): void
    {
        $literals = str_repeat('x', 4095 - RtfBuilder::PREBUFFER_LENGTH) . 'yz';

        static::assertSame(
            "{$literals}yz",
            CompressedRtf::decompress(RtfBuilder::compressed([$literals, [4095, 2]]), 5000),
        );
    }

    #[Test]
    public function decompressesExactlyTheDeclaredSize(): void
    {
        static::assertSame('abc', CompressedRtf::decompress(RtfBuilder::compressed(['abc']), 3));
    }

    #[Test]
    public function ignoresBytesAfterTheCompressedSize(): void
    {
        static::assertSame('abc', CompressedRtf::decompress(RtfBuilder::compressed(['abc']) . 'junk', 3));
    }

    #[Test]
    public function returnsStoredRtfAsItIs(): void
    {
        static::assertSame('{\rtf1 plain}', CompressedRtf::decompress(RtfBuilder::stored('{\rtf1 plain}'), 100));
    }

    #[Test]
    public function returnsStoredRtfUpToItsDeclaredSize(): void
    {
        static::assertSame('{\rtf1', CompressedRtf::decompress(RtfBuilder::stored('{\rtf1 plain}', 6), 100));
    }

    #[Test]
    public function returnsEmptyStoredRtf(): void
    {
        static::assertSame('', CompressedRtf::decompress(RtfBuilder::stored(''), 1));
    }

    #[DataProvider('crcProvider')]
    #[Test]
    public function computesTheCrcOfTheSpecification(string $data): void
    {
        static::assertSame(RtfBuilder::crc($data), CompressedRtf::crc($data));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function crcProvider(): array
    {
        $bytes = '';
        foreach (range(
            start: 0,
            end: 255,
        ) as $byte) {
            $bytes .= pack('C', $byte);
        }

        return [
            'empty'            => [''],
            'one byte'         => ['a'],
            'text'             => ['{\rtf1 hello world}'],
            'every byte value' => [$bytes . $bytes],
        ];
    }

    #[DataProvider('malformedProvider')]
    #[Test]
    public function refusesMalformedCompressedRtf(string $data, int $maxBytes, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        CompressedRtf::decompress($data, $maxBytes);
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function malformedProvider(): array
    {
        $valid      = RtfBuilder::compressed(['abc']);
        $endsEarly  = 'The compressed RTF ends before its end marker';
        $withoutCrc = static fn(string $payload): string => (
            RtfBuilder::header(
                $payload,
                100,
                RtfBuilder::LZFU,
                CompressedRtf::crc($payload),
            ) . $payload
        );
        $beforeStart = 'The compressed RTF refers to offset 300, before the start of its data';

        return [
            'empty'                                   => [
                '',
                100,
                'The TNEF data ends early: 4 bytes are needed at offset 0, but 0 remain',
            ],
            'header cut short'                        => [
                substr($valid, offset: 0, length: 15),
                100,
                'The TNEF data ends early',
            ],
            'compressed size below the header'        => [
                pack('VVVV', 11, 0, RtfBuilder::MELA, 0),
                100,
                'The compressed RTF claims 11 bytes, but 12 follow its size',
            ],
            'compressed size past the end'            => [
                pack('VVVV', 13, 0, RtfBuilder::MELA, 0),
                100,
                'The compressed RTF claims 13 bytes, but 12 follow its size',
            ],
            'raw size above the limit'                => [
                $valid,
                2,
                'The compressed RTF holds 3 bytes, more than the 2 allowed',
            ],
            'unknown compression type'                => [
                pack('VVVV', 12, 0, 0x1234_5678, 0),
                100,
                'The RTF has the unknown compression type 0x12345678',
            ],
            'CRC mismatch'                            => [
                RtfBuilder::compressed(['abc'], crc: 0x1234),
                100,
                'The compressed RTF fails its CRC check',
            ],
            'literals past the declared size'         => [
                RtfBuilder::compressed(['abc'], rawSize: 2),
                100,
                'The compressed RTF grows past the 2 bytes its header declares',
            ],
            'reference past the declared size'        => [
                RtfBuilder::compressed(['a', [0, 5]], rawSize: 3),
                100,
                'The compressed RTF grows past the 3 bytes its header declares',
            ],
            'reference to bytes never written'        => [
                RtfBuilder::compressed(['a', [300, 2]]),
                100,
                $beforeStart,
            ],
            'reference just past the write position'  => [
                RtfBuilder::compressed([[RtfBuilder::PREBUFFER_LENGTH + 1, 2]]),
                100,
                'The compressed RTF refers to offset 208, before the start of its data',
            ],
            'no tokens'                               => [$withoutCrc(''), 100, $endsEarly],
            'no end marker'                           => [
                RtfBuilder::unterminated(['abc']),
                100,
                $endsEarly,
            ],
            'no end marker after a full control byte' => [
                RtfBuilder::unterminated(['abcdefgh']),
                100,
                $endsEarly,
            ],
            'missing literal'                         => [$withoutCrc("\x00"), 100, $endsEarly],
            'half a reference'                        => [$withoutCrc("\x01\x00"), 100, $endsEarly],
            'stored RTF shorter than declared'        => [
                RtfBuilder::stored('{\rtf1}', 8),
                100,
                'The uncompressed RTF claims 8 bytes, but 7 follow its header',
            ],
        ];
    }

    #[Test]
    public function refusesAReferenceBeforeTheStartEvenWhenTheDataIsLong(): void
    {
        $literals = str_repeat('x', times: 3000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The compressed RTF refers to offset 4000, before the start of its data');

        CompressedRtf::decompress(RtfBuilder::compressed([$literals, [4000, 2]]), strlen($literals) + 2);
    }
}
