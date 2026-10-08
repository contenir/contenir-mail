<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage\Tnef;

use Contenir\Mail\Header\SafeText;
use Contenir\Mail\Storage\Exception\RuntimeException;

use function count;
use function count_chars;
use function sprintf;
use function strlen;

/**
 * Reads one TNEF container, record by record, keeping what it has found so far.
 *
 * Records are a level byte, a 32-bit attribute id, a 32-bit length, the
 * data, and a 16-bit checksum: the sum of the data bytes. A record whose
 * checksum does not match stops reading: it is refused, not skipped.
 *
 * @internal Used by Reader, which builds a parser for each container.
 *
 * @mago-expect lint:cyclomatic-complexity A branch for each TNEF attribute read, and for each check on hostile input.
 * @mago-expect lint:kan-defect A branch for each TNEF attribute read, and for each check on hostile input.
 */
final class Parser
{
    /** The first four bytes of every TNEF container, read little-endian */
    private const int SIGNATURE = 0x223E_9F78;

    /** The level of records about the message */
    private const int LEVEL_MESSAGE = 0x01;

    /** The level of records about an attachment */
    private const int LEVEL_ATTACHMENT = 0x02;

    /** attBody: the plain-text body, in the message's code page */
    private const int ATT_BODY = 0x0002_800C;

    /** attOemCodepage: the code page of 8-bit text */
    private const int ATT_OEM_CODEPAGE = 0x0006_9007;

    /** attMsgProps: the message's MAPI properties */
    private const int ATT_MSG_PROPS = 0x0006_9003;

    /** attAttachRendData: the first record of each attachment */
    private const int ATT_ATTACH_REND_DATA = 0x0006_9002;

    /** attAttachData: an attachment's bytes */
    private const int ATT_ATTACH_DATA = 0x0006_800F;

    /** attAttachTitle: an attachment's short file name */
    private const int ATT_ATTACH_TITLE = 0x0001_8010;

    /** attAttachment: an attachment's MAPI properties */
    private const int ATT_ATTACHMENT = 0x0006_9005;

    /** PR_BODY */
    private const int PR_BODY = 0x1000;

    /** PR_RTF_COMPRESSED */
    private const int PR_RTF_COMPRESSED = 0x1009;

    /** PR_ATTACH_DATA_BIN, which holds an attachment's bytes when attAttachData does not */
    private const int PR_ATTACH_DATA_BIN = 0x3701;

    /** PR_ATTACH_LONG_FILENAME */
    private const int PR_ATTACH_LONG_FILENAME = 0x3707;

    /** PR_ATTACH_MIME_TAG */
    private const int PR_ATTACH_MIME_TAG = 0x370E;

    private string $charset = Text::DEFAULT_CHARSET;

    private ?string $body = null;

    private Properties $properties;

    /** @var list<AttachmentRecord> */
    private array $attachments = [];

    /** The attachment being read: the last one started */
    private ?AttachmentRecord $current = null;

    private int $bytes = 0;

    public function __construct(
        private readonly int $maxAttachments,
        private readonly int $maxBytes,
    ) {
        $this->properties = new Properties();
    }

    /**
     * @throws RuntimeException When the bytes are not TNEF, are malformed, or exceed a limit.
     */
    public function parse(string $bytes): Contents
    {
        $input = new ByteReader($bytes);
        if (strlen($bytes) < 6 || self::SIGNATURE !== $input->uint32()) {
            throw new RuntimeException('The data is not TNEF: it does not start with the TNEF signature');
        }

        $input->uint16();
        while ($input->remaining() > 0) {
            $level = $input->uint8();
            $id    = $input->uint32();
            $data  = self::checked($id, $input->bytes($input->uint32()), $input->uint16());
            match ($level) {
                self::LEVEL_MESSAGE => $this->messageAttribute($id, $data),
                self::LEVEL_ATTACHMENT => $this->attachmentAttribute($id, $data),
                default => throw new RuntimeException(sprintf('A TNEF attribute has the unknown level %d', $level)),
            };
        }

        return $this->contents();
    }

    /**
     * The data of a record, once its checksum, the sum of its bytes modulo 65536, is found right.
     *
     * The bytes are counted by value, so large data is never split into an array.
     *
     * @throws RuntimeException When the checksum does not match.
     */
    private static function checked(int $id, string $data, int $checksum): string
    {
        $sum = 0;
        foreach (count_chars($data, mode: 1) as $byte => $count) {
            $sum += $byte * $count;
        }

        if (($sum & 0xFFFF) !== $checksum) {
            throw new RuntimeException(sprintf(
                'The TNEF attribute 0x%08X fails its checksum: 0x%04X was given, 0x%04X is right',
                $id,
                $checksum,
                $sum & 0xFFFF,
            ));
        }

        return $data;
    }

    /**
     * @throws RuntimeException When the data is malformed.
     */
    private function messageAttribute(int $id, string $data): void
    {
        match ($id) {
            self::ATT_OEM_CODEPAGE => $this->charset = Text::charset((new ByteReader($data))->uint32()),
            self::ATT_BODY => $this->body = $data,
            self::ATT_MSG_PROPS => $this->properties = MapiProperties::read($data),
            default => null,
        };
    }

    /**
     * @throws RuntimeException When the data is malformed, comes before its attachment starts,
     *     or starts an attachment past the limit.
     */
    private function attachmentAttribute(int $id, string $data): void
    {
        match ($id) {
            self::ATT_ATTACH_REND_DATA => $this->startAttachment(),
            self::ATT_ATTACH_TITLE => $this->current($id)->title = $data,
            self::ATT_ATTACH_DATA => $this->current($id)->data = $data,
            self::ATT_ATTACHMENT => $this->current($id)->properties = MapiProperties::read($data),
            default => $this->current($id),
        };
    }

    /**
     * @throws RuntimeException When the container already holds as many attachments as allowed.
     */
    private function startAttachment(): void
    {
        if (count($this->attachments) >= $this->maxAttachments) {
            throw new RuntimeException(sprintf('The TNEF data holds more than %d attachments', $this->maxAttachments));
        }

        $this->current       = new AttachmentRecord();
        $this->attachments[] = $this->current;
    }

    /**
     * The attachment an attachment attribute belongs to: the last one started.
     *
     * @throws RuntimeException When no attachment has started.
     */
    private function current(int $id): AttachmentRecord
    {
        return (
            $this->current ?? throw new RuntimeException(sprintf(
                'The TNEF attachment attribute 0x%08X comes before any attachment starts',
                $id,
            ))
        );
    }

    /**
     * @throws RuntimeException When the output exceeds its limit, or the compressed RTF is malformed.
     */
    private function contents(): Contents
    {
        $attachments = [];
        foreach ($this->attachments as $record) {
            $properties    = $record->properties;
            $title         = null === $record->title ? null : Text::utf8($record->title, $this->charset);
            $attachments[] = new TnefAttachment(
                SafeText::filename($properties->text(self::PR_ATTACH_LONG_FILENAME, $this->charset) ?? $title ?? ''),
                $this->output($record->data ?? $properties->binary(self::PR_ATTACH_DATA_BIN) ?? ''),
                Text::mediaType($properties->text(self::PR_ATTACH_MIME_TAG, $this->charset)),
            );
        }

        $text = null === $this->body
            ? $this->properties->text(self::PR_BODY, $this->charset)
            : Text::utf8($this->body, $this->charset);
        $rtf = $this->properties->binary(self::PR_RTF_COMPRESSED);

        return new Contents(
            $attachments,
            null === $text ? null : $this->output($text),
            null === $rtf ? null : $this->output(CompressedRtf::decompress($rtf, $this->maxBytes - $this->bytes)),
        );
    }

    /**
     * Count bytes of output against the limit.
     *
     * @throws RuntimeException When the output exceeds its limit.
     */
    private function output(string $output): string
    {
        $this->bytes += strlen($output);
        if ($this->bytes > $this->maxBytes) {
            throw new RuntimeException(sprintf(
                'The TNEF data holds more than the %d bytes allowed',
                $this->maxBytes,
            ));
        }

        return $output;
    }
}
