<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Storage\Part\Content;
use Contenir\Mail\Storage\Part\MimeParser;
use Override;

use function array_keys;
use function count;
use function ctype_digit;
use function fclose;
use function fopen;
use function is_file;
use function is_iterable;

/**
 * A read-only mbox file: messages one after another, each starting with a "From " line.
 *
 * Files may use CRLF or bare LF line breaks. Messages are numbered in file
 * order and have no unique IDs other than their numbers. Bodies are read
 * from the file only when asked for, and stay readable after the storage
 * is closed; see MboxFormat for how ">From " lines are read.
 *
 * @mago-expect lint:too-many-methods The AbstractStorage operations, and the file handling Folder\Mbox shares.
 *
 * @api
 */
class Mbox extends AbstractStorage
{
    /** @var resource|null */
    protected mixed $fh = null;

    protected string $filename = '';

    protected MboxFormat $format = MboxFormat::Mboxo;

    /** @var list<array{int, int}> start and end offset of each message, after its "From " line */
    protected array $positions = [];

    /**
     * @param MboxConfig|iterable<mixed, mixed> $config An MboxConfig, or its settings.
     * @throws Exception\ExceptionInterface When the settings are invalid or the file is not an mbox file.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(MboxConfig|iterable $config)
    {
        $config                = is_iterable($config) ? MboxConfig::fromIterable($config) : $config;
        $this->format          = $config->format;
        $this->has['top']      = true;
        $this->has['uniqueid'] = false;
        $this->openMboxFile($config->filename);
    }

    /**
     * The number of messages; none have flags, so none when flags are asked for.
     */
    #[Override]
    public function countMessages(Flag|string ...$flags): int
    {
        return [] === $flags ? count($this->positions) : 0;
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     */
    #[Override]
    public function getSize(int $number): int
    {
        [$start, $end] = $this->position($number);

        return $end - $start;
    }

    #[Override]
    public function getSizes(): array
    {
        $sizes = [];
        foreach ($this->positions as $index => [$start, $end]) {
            $sizes[$index + 1] = $end - $start;
        }

        return $sizes;
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When its headers cannot be read.
     */
    #[Override]
    public function getMessage(int $number): Message
    {
        [$headers, $body] = MimeParser::split($this->content($number));

        return new Message(new Part($headers, $body));
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When its headers cannot be read.
     */
    #[Override]
    public function getRawHeader(int $number): string
    {
        $content = $this->content($number);
        [, $body] = MimeParser::split($content);

        return $content->slice(0, $content->length() - $body->length())->read();
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When its headers cannot be read.
     */
    #[Override]
    public function getRawContent(int $number): string
    {
        return MimeParser::split($this->content($number))[1]->read();
    }

    /**
     * Let go of the file. Messages already read keep it open until they are
     * gone, so their bodies can still be read.
     */
    #[Override]
    public function close(): void
    {
        $this->fh        = null;
        $this->positions = [];
        $this->open      = false;
    }

    #[Override]
    public function noop(): void {}

    /**
     * @throws Exception\RuntimeException Always: mbox files are read-only here.
     */
    #[Override]
    public function removeMessage(int $number): void
    {
        throw new Exception\RuntimeException('mbox is read-only');
    }

    /**
     * The message number, as mbox messages have no other unique ID.
     *
     * @throws Exception\OutOfBoundsException When there is no such message.
     */
    #[Override]
    public function getUniqueId(int $number): string
    {
        return (string) self::checkNumber($number, count($this->positions));
    }

    #[Override]
    public function getUniqueIds(): array
    {
        $ids = [];
        foreach (array_keys($this->positions) as $index) {
            $ids[$index + 1] = (string) ($index + 1);
        }

        return $ids;
    }

    /**
     * @throws Exception\OutOfBoundsException When no message has that number.
     */
    #[Override]
    public function getNumberByUniqueId(string $uniqueId): int
    {
        if (! ctype_digit($uniqueId)) {
            throw new Exception\OutOfBoundsException("There is no message {$uniqueId}");
        }

        return self::checkNumber((int) $uniqueId, count($this->positions));
    }

    /**
     * Open a file as the current mbox and find its messages.
     *
     * @throws Exception\InvalidArgumentException When the file is not an mbox file.
     * @throws Exception\RuntimeException When the file cannot be opened.
     */
    protected function openMboxFile(string $filename): void
    {
        $this->close();
        if (! is_file($filename)) {
            throw new Exception\InvalidArgumentException("{$filename} is not a file");
        }

        $fh = FileSystem::quietly(static fn(): mixed => fopen($filename, mode: 'rb'));
        if (false === $fh) {
            throw new Exception\RuntimeException("Cannot open mbox file {$filename}");
        }

        $positions = MboxScanner::findMessages($fh);
        if (null === $positions) {
            fclose($fh);

            throw new Exception\InvalidArgumentException("{$filename} is not an mbox file");
        }

        $this->fh        = $fh;
        $this->filename  = $filename;
        $this->positions = $positions;
        $this->open      = true;
    }

    /**
     * @return array{int, int}
     * @throws Exception\OutOfBoundsException When there is no such message.
     */
    private function position(int $number): array
    {
        return $this->positions[self::checkNumber($number, count($this->positions)) - 1] ?? [0, 0];
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When the storage has been closed.
     */
    private function content(int $number): Content
    {
        [$start, $end] = $this->position($number);

        /** @var resource $fh Open while there are positions: close() clears both. */
        $fh = $this->fh;

        return Content::fromStream($fh, $start, $end, MboxFormat::Mboxrd === $this->format);
    }
}
