<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\TestAsset;

use function array_map;
use function array_sum;
use function count;
use function implode;
use function in_array;
use function is_int;
use function mb_convert_encoding;
use function ord;
use function pack;
use function str_repeat;
use function str_split;
use function strlen;

/**
 * Writes TNEF containers and MAPI properties for tests, so fixtures read as what they hold.
 *
 * Nothing here checks its input: tests build broken containers on purpose.
 * RtfBuilder writes the compressed RTF a container may carry.
 */
final class TnefBuilder
{
    public const int SIGNATURE = 0x223E_9F78;

    public const int LEVEL_MESSAGE = 0x01;

    public const int LEVEL_ATTACHMENT = 0x02;

    public const int ATT_TNEF_VERSION = 0x0008_9006;

    public const int ATT_OEM_CODEPAGE = 0x0006_9007;

    public const int ATT_MESSAGE_CLASS = 0x0007_8008;

    public const int ATT_BODY = 0x0002_800C;

    public const int ATT_MSG_PROPS = 0x0006_9003;

    public const int ATT_ATTACH_REND_DATA = 0x0006_9002;

    public const int ATT_ATTACH_DATA = 0x0006_800F;

    public const int ATT_ATTACH_TITLE = 0x0001_8010;

    public const int ATT_ATTACHMENT = 0x0006_9005;

    public const int TYPE_LONG = 0x0003;

    public const int TYPE_STRING8 = 0x001E;

    public const int TYPE_UNICODE = 0x001F;

    public const int TYPE_BINARY = 0x0102;

    public const int MULTIPLE = 0x1000;

    public const int PR_BODY = 0x1000;

    public const int PR_RTF_COMPRESSED = 0x1009;

    public const int PR_ATTACH_DATA_BIN = 0x3701;

    public const int PR_ATTACH_LONG_FILENAME = 0x3707;

    public const int PR_ATTACH_MIME_TAG = 0x370E;

    /** @var list<int> */
    private const array VARIABLE_TYPES = [0x000D, self::TYPE_STRING8, self::TYPE_UNICODE, self::TYPE_BINARY];

    private string $records = '';

    public static function create(): self
    {
        return new self();
    }

    /**
     * A winmail.dat shaped like Outlook's: a body, compressed RTF, and two attachments,
     * the first with a long file name and a media type, the second with only its title.
     */
    public static function outlookMessage(): string
    {
        return self::create()
            ->message(self::ATT_TNEF_VERSION, pack('V', 0x0001_0000))
            ->codepage(1252)
            ->message(self::ATT_MESSAGE_CLASS, "IPM.Microsoft Mail.Note\0")
            ->body("Please see the attached files.\r\n")
            ->messageProperties(
                self::property(self::TYPE_LONG, 0x0E07, pack('V', 1)),
                self::property(self::TYPE_BINARY, self::PR_RTF_COMPRESSED, RtfBuilder::compressed([
                    [0, 11],
                    ' Please see the attached files.}',
                ])),
            )
            ->attachment(
                'QUARTE~1.PDF',
                "%PDF-1.4\n%quarterly figures\n%%EOF\n",
                self::property(self::TYPE_LONG, 0x0E21, pack('V', 0)),
                self::property(
                    self::TYPE_UNICODE,
                    self::PR_ATTACH_LONG_FILENAME,
                    self::unicode('Quarterly report 2026.pdf'),
                ),
                self::property(self::TYPE_STRING8, self::PR_ATTACH_MIME_TAG, "application/pdf\0"),
            )
            ->attachment('notes.txt', "Remember the milk.\r\n")
            ->build();
    }

    /**
     * A record, with its checksum worked out unless one is given.
     */
    public function attribute(int $level, int $id, string $data, ?int $checksum = null): self
    {
        $this->records .=
            pack('CVV', $level, $id, strlen($data)) . $data . pack('v', $checksum ?? self::checksum($data));

        return $this;
    }

    public function message(int $id, string $data): self
    {
        return $this->attribute(self::LEVEL_MESSAGE, $id, $data);
    }

    public function codepage(int $codepage): self
    {
        return $this->message(self::ATT_OEM_CODEPAGE, pack('VV', $codepage, 0));
    }

    /**
     * attBody, NUL-terminated as Outlook writes it.
     */
    public function body(string $text): self
    {
        return $this->message(self::ATT_BODY, "{$text}\0");
    }

    public function messageProperties(string ...$properties): self
    {
        return $this->message(self::ATT_MSG_PROPS, self::properties(...$properties));
    }

    /**
     * Start an attachment with attAttachRendData.
     */
    public function startAttachment(): self
    {
        return $this->attribute(
            self::LEVEL_ATTACHMENT,
            self::ATT_ATTACH_REND_DATA,
            pack('vVvvV', 1, 0xFFFF_FFFF, 0, 0, 0),
        );
    }

    /**
     * An attachment: attAttachRendData, then the title, data and MAPI properties given.
     */
    public function attachment(?string $title, ?string $data, string ...$properties): self
    {
        $this->startAttachment();
        if (null !== $title) {
            $this->attribute(self::LEVEL_ATTACHMENT, self::ATT_ATTACH_TITLE, "{$title}\0");
        }

        if (null !== $data) {
            $this->attribute(self::LEVEL_ATTACHMENT, self::ATT_ATTACH_DATA, $data);
        }

        if ([] !== $properties) {
            $this->attribute(self::LEVEL_ATTACHMENT, self::ATT_ATTACHMENT, self::properties(...$properties));
        }

        return $this;
    }

    public function build(int $signature = self::SIGNATURE): string
    {
        return pack('Vv', $signature, 0x1234) . $this->records;
    }

    /**
     * The sum of the bytes modulo 65536, as MS-OXTNEF section 2.1.3.2 describes it.
     */
    public static function checksum(string $data): int
    {
        return array_sum(array_map(ord(...), str_split($data))) % 65_536;
    }

    /**
     * A MAPI property list: the count, then the properties.
     */
    public static function properties(string ...$properties): string
    {
        return pack('V', count($properties)) . implode('', $properties);
    }

    /**
     * A single-valued property; variable-length types get their count and length, fixed ones are written as given.
     */
    public static function property(int $type, int $id, string $value): string
    {
        $count = in_array($type, self::VARIABLE_TYPES, strict: true) ? pack('V', 1) : '';

        return pack('vv', $type, $id) . $count . self::value($type, $value);
    }

    /**
     * A multi-valued property of the base type given.
     *
     * @param list<string> $values
     */
    public static function multiValued(int $type, int $id, array $values): string
    {
        $written = pack('vvV', $type | self::MULTIPLE, $id, count($values));
        foreach ($values as $value) {
            $written .= self::value($type, $value);
        }

        return $written;
    }

    /**
     * A named property, identified by a number (kind 0) or a name (kind 1).
     */
    public static function namedProperty(int $type, int $id, int|string $name, string $value): string
    {
        $identity = is_int($name) ? pack('VV', 0, $name) : pack('V', 1) . self::lengthPrefixed(self::unicode($name));

        $count = in_array($type, self::VARIABLE_TYPES, strict: true) ? pack('V', 1) : '';

        return pack('vv', $type, $id) . str_repeat("\x11", times: 16) . $identity . $count . self::value($type, $value);
    }

    /**
     * NUL-terminated UTF-16LE, as PtypString values are written.
     */
    public static function unicode(string $text): string
    {
        return mb_convert_encoding($text, to_encoding: 'UTF-16LE', from_encoding: 'UTF-8') . "\0\0";
    }

    private static function value(int $type, string $value): string
    {
        return in_array($type, self::VARIABLE_TYPES, strict: true) ? self::lengthPrefixed($value) : $value;
    }

    private static function lengthPrefixed(string $value): string
    {
        return pack('V', strlen($value)) . $value . str_repeat("\0", times: (4 - (strlen($value) % 4)) % 4);
    }
}
