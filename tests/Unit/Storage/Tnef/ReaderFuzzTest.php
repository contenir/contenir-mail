<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Tnef;

use Closure;
use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\Tnef\AttachmentRecord;
use Contenir\Mail\Storage\Tnef\ByteReader;
use Contenir\Mail\Storage\Tnef\CompressedRtf;
use Contenir\Mail\Storage\Tnef\Dictionary;
use Contenir\Mail\Storage\Tnef\MapiProperties;
use Contenir\Mail\Storage\Tnef\Parser;
use Contenir\Mail\Storage\Tnef\Properties;
use Contenir\Mail\Storage\Tnef\Reader;
use Contenir\Mail\Storage\Tnef\Text;
use Contenir\Mail\Tests\TestAsset\RtfBuilder;
use Contenir\Mail\Tests\TestAsset\TnefBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function bin2hex;
use function in_array;
use function pack;
use function range;
use function strlen;
use function substr;
use function unpack;

/**
 * Feeds the TNEF reader random and damaged input. Each input must be read, or refused with
 * one of the package's exceptions: never a PHP warning, error or other exception.
 *
 * The random generator has a fixed seed, so every run tries the same inputs.
 *
 * @mago-expect lint:kan-defect Each test is a seeded loop over generated inputs.
 */
#[CoversClass(Reader::class)]
#[CoversClass(Parser::class)]
#[CoversClass(AttachmentRecord::class)]
#[CoversClass(ByteReader::class)]
#[CoversClass(MapiProperties::class)]
#[CoversClass(Properties::class)]
#[CoversClass(Text::class)]
#[CoversClass(CompressedRtf::class)]
#[CoversClass(Dictionary::class)]
#[Group('unit')]
final class ReaderFuzzTest extends TestCase
{
    private const int SEED = 20_261_008;

    private const int ROUNDS = 400;

    #[Test]
    public function refusesEveryTruncationThatCutsARecord(): void
    {
        $tnef       = TnefBuilder::outlookMessage();
        $boundaries = self::recordBoundaries($tnef);
        $outcomes   = [];
        foreach (range(0, strlen($tnef) - 1) as $length) {
            $outcomes[$length] = self::outcome(
                static fn(): mixed => (new Reader())->read(substr($tnef, offset: 0, length: $length)),
            );
        }

        $expected = [];
        foreach (range(0, strlen($tnef) - 1) as $length) {
            $expected[$length] = in_array($length, $boundaries, strict: true) ? 'read' : 'refused';
        }

        static::assertSame($expected, $outcomes);
    }

    #[Test]
    public function readsOrRefusesRandomRecords(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $header = pack('Vv', TnefBuilder::SIGNATURE, 0);
        foreach (range(1, self::ROUNDS) as $round) {
            $bytes = $header . self::randomBytes($random, $random->getInt(0, 300));
            self::assertReadOrRefused(static fn(): mixed => (new Reader())->read($bytes), $round, $bytes);
        }
    }

    #[Test]
    public function readsOrRefusesDamagedContainers(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $tnef   = TnefBuilder::outlookMessage();
        foreach (range(1, self::ROUNDS) as $round) {
            $bytes = self::damage($random, $tnef);
            self::assertReadOrRefused(static fn(): mixed => (new Reader())->read($bytes), $round, $bytes);
        }
    }

    #[Test]
    public function readsOrRefusesRandomRecordsWithRightChecksums(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $levels = [TnefBuilder::LEVEL_MESSAGE, TnefBuilder::LEVEL_ATTACHMENT];
        $ids    = [
            TnefBuilder::ATT_OEM_CODEPAGE,
            TnefBuilder::ATT_BODY,
            TnefBuilder::ATT_MSG_PROPS,
            TnefBuilder::ATT_ATTACH_REND_DATA,
            TnefBuilder::ATT_ATTACH_TITLE,
            TnefBuilder::ATT_ATTACH_DATA,
            TnefBuilder::ATT_ATTACHMENT,
        ];
        foreach (range(1, self::ROUNDS) as $round) {
            $builder = TnefBuilder::create()->startAttachment();
            for ($records = $random->getInt(1, 6); $records > 0; $records--) {
                $builder->attribute(
                    $levels[$random->getInt(0, 1)],
                    $ids[$random->getInt(0, 6)],
                    self::randomBytes($random, $random->getInt(0, 64)),
                );
            }

            $bytes = $builder->build();
            self::assertReadOrRefused(static fn(): mixed => (new Reader())->read($bytes), $round, $bytes);
        }
    }

    #[Test]
    public function readsOrRefusesDamagedMapiProperties(): void
    {
        $random     = new Randomizer(new Mt19937(self::SEED));
        $properties = TnefBuilder::properties(
            TnefBuilder::property(TnefBuilder::TYPE_LONG, 0x0E21, pack('V', 0)),
            TnefBuilder::property(
                TnefBuilder::TYPE_UNICODE,
                TnefBuilder::PR_ATTACH_LONG_FILENAME,
                TnefBuilder::unicode('a.pdf'),
            ),
            TnefBuilder::namedProperty(TnefBuilder::TYPE_STRING8, 0x8001, 'Keywords', "x\0"),
            TnefBuilder::multiValued(TnefBuilder::TYPE_BINARY, 0x0001, ['one', 'two']),
        );
        foreach (range(1, self::ROUNDS) as $round) {
            $bytes = self::damage($random, $properties);
            self::assertReadOrRefused(static fn(): mixed => MapiProperties::read($bytes), $round, $bytes);
        }
    }

    #[Test]
    public function readsOrRefusesDamagedCompressedRtf(): void
    {
        $random     = new Randomizer(new Mt19937(self::SEED));
        $compressed = substr(RtfBuilder::compressed([[0, 17], 'abcdefgh', [207, 9], [12, 4], '}']), offset: 16);
        foreach (range(1, self::ROUNDS) as $round) {
            $payload = self::damage($random, $compressed);
            $bytes   = RtfBuilder::header(
                $payload,
                $random->getInt(0, 200),
                RtfBuilder::LZFU,
                CompressedRtf::crc($payload),
            )
            . $payload;
            self::assertReadOrRefused(static fn(): mixed => CompressedRtf::decompress($bytes, 100), $round, $bytes);
        }
    }

    #[Test]
    public function stopsARepeatingReferenceAtTheDeclaredSize(): void
    {
        $tokens   = ['a'];
        $produced = 1;
        while ($produced <= 10_000) {
            $tokens[] = [(RtfBuilder::PREBUFFER_LENGTH + $produced - 1) % 4096, 17];
            $produced += 17;
        }

        $bytes = RtfBuilder::compressed($tokens, rawSize: 10_000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The compressed RTF grows past the 10000 bytes its header declares');

        CompressedRtf::decompress($bytes, 10_000);
    }

    /**
     * @param Closure(): mixed $read
     */
    private static function assertReadOrRefused(Closure $read, int $round, string $bytes): void
    {
        static::assertContains(
            self::outcome($read),
            ['read', 'refused'],
            "Round {$round}, input " . bin2hex($bytes),
        );
    }

    /**
     * @param Closure(): mixed $read
     */
    private static function outcome(Closure $read): string
    {
        try {
            $read();
        } catch (ExceptionInterface) {
            return 'refused';
        }

        return 'read';
    }

    /**
     * Where each record of a container ends, and where the first starts: the lengths a container may be cut to.
     *
     * @return list<int>
     */
    private static function recordBoundaries(string $tnef): array
    {
        $boundaries = [6];
        $offset     = 6;
        while ($offset < strlen($tnef)) {
            /** @var array{1: int} $length */
            $length       = unpack('V', $tnef, $offset + 5);
            $offset       += 11 + $length[1];
            $boundaries[] = $offset;
        }

        return $boundaries;
    }

    private static function randomBytes(Randomizer $random, int $length): string
    {
        return 0 === $length ? '' : $random->getBytes($length);
    }

    /**
     * The bytes with a few of them changed, cut short, or with random bytes added.
     */
    private static function damage(Randomizer $random, string $bytes): string
    {
        for ($changes = $random->getInt(1, 4); $changes > 0; $changes--) {
            $offset = $random->getInt(0, strlen($bytes) - 1);
            $bytes  = match ($random->getInt(0, 2)) {
                0 => substr($bytes, offset: 0, length: $offset) . $random->getBytes(1) . substr($bytes, $offset + 1),
                1       => substr($bytes, offset: 0, length: $offset),
                default => substr($bytes, offset: 0, length: $offset) . $random->getBytes(4) . substr($bytes, $offset),
            };
            if ('' === $bytes) {
                return $bytes;
            }
        }

        return $bytes;
    }
}
