<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\Storage\Part\MimeParser;
use Override;

use function count;
use function file_exists;
use function is_dir;
use function is_iterable;
use function rtrim;

use const DIRECTORY_SEPARATOR;

/**
 * A read-only maildir: one file per message in cur/, and in new/ for recent ones.
 *
 * File names carry the unique ID, the size (",S=1234") and the flags
 * (":2,FRS"). Hidden entries, symbolic links and anything but regular files
 * are skipped, so a message is always a file inside the maildir.
 *
 * @mago-expect lint:too-many-methods The AbstractStorage operations, and the maildir handling Folder\Maildir shares.
 *
 * @api
 */
class Maildir extends AbstractStorage
{
    /**
     * @var list<array{uniq: string, flags: list<Flag|string>, filename: string, size: int|null}>
     */
    protected array $files = [];

    /**
     * @param MaildirConfig|iterable<mixed, mixed> $config A MaildirConfig, or its settings.
     * @throws Exception\ExceptionInterface When the settings are invalid or the directory is not a maildir.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a setting is unknown or has the wrong type.
     */
    public function __construct(MaildirConfig|iterable $config)
    {
        $config             = is_iterable($config) ? MaildirConfig::fromIterable($config) : $config;
        $this->has['top']   = true;
        $this->has['flags'] = true;
        $this->openMaildir(rtrim($config->dirname, DIRECTORY_SEPARATOR));
    }

    /**
     * The number of messages, or of those with every flag given.
     */
    #[Override]
    public function countMessages(Flag|string ...$flags): int
    {
        $count = 0;
        foreach ($this->files as $file) {
            $count += MaildirFiles::hasFlags($file['flags'], $flags) ? 1 : 0;
        }

        return $count;
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     */
    #[Override]
    public function getSize(int $id): int
    {
        return MaildirFiles::size($this->file($id)['filename'], $this->file($id)['size']);
    }

    #[Override]
    public function getSizes(): array
    {
        $sizes = [];
        foreach ($this->files as $index => $file) {
            $sizes[$index + 1] = MaildirFiles::size($file['filename'], $file['size']);
        }

        return $sizes;
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When its file cannot be read.
     */
    #[Override]
    public function getMessage(int $id): Message
    {
        $file = $this->file($id);
        [$headers, $body] = MimeParser::split(MaildirFiles::content($file['filename']));

        return new Message(new Part($headers, $body), $file['flags']);
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When its file cannot be read.
     */
    #[Override]
    public function getRawHeader(int $id): string
    {
        $content = MaildirFiles::content($this->file($id)['filename']);
        [, $body] = MimeParser::split($content);

        return $content->slice(0, $content->length() - $body->length())->read();
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     * @throws Exception\RuntimeException When its file cannot be read.
     */
    #[Override]
    public function getRawContent(int $id): string
    {
        return MimeParser::split(MaildirFiles::content($this->file($id)['filename']))[1]->read();
    }

    #[Override]
    public function close(): void
    {
        $this->files = [];
        $this->open  = false;
    }

    #[Override]
    public function noop(): void {}

    /**
     * @throws Exception\RuntimeException Always: use Writable\Maildir to change a maildir.
     */
    #[Override]
    public function removeMessage(int $id): void
    {
        throw new Exception\RuntimeException('Maildir is read-only; use Writable\Maildir');
    }

    /**
     * @throws Exception\OutOfBoundsException When there is no such message.
     */
    #[Override]
    public function getUniqueId(int $id): string
    {
        return $this->file($id)['uniq'];
    }

    #[Override]
    public function getUniqueIds(): array
    {
        $ids = [];
        foreach ($this->files as $index => $file) {
            $ids[$index + 1] = $file['uniq'];
        }

        return $ids;
    }

    /**
     * @throws Exception\OutOfBoundsException When no message has that unique ID.
     */
    #[Override]
    public function getNumberByUniqueId(string $id): int
    {
        foreach ($this->files as $index => $file) {
            if ($file['uniq'] === $id) {
                return $index + 1;
            }
        }

        throw new Exception\OutOfBoundsException('Unique ID not found');
    }

    /**
     * Whether a directory is a maildir: it has cur/, and new/ and tmp/, where present, are directories.
     *
     * @internal
     */
    public static function isMaildir(string $dirname): bool
    {
        foreach (['new', 'tmp'] as $subdir) {
            $path = $dirname . DIRECTORY_SEPARATOR . $subdir;
            if (file_exists($path) && ! is_dir($path)) {
                return false;
            }
        }

        return is_dir($dirname . DIRECTORY_SEPARATOR . 'cur');
    }

    /**
     * Read the messages of a maildir as the current ones.
     *
     * @throws Exception\InvalidArgumentException When the directory is not a maildir.
     * @throws Exception\RuntimeException When cur/ or new/ cannot be read.
     */
    protected function openMaildir(string $dirname): void
    {
        if (! is_dir($dirname) || ! static::isMaildir($dirname)) {
            throw new Exception\InvalidArgumentException("{$dirname} is not a maildir");
        }

        $this->files = MaildirFiles::read($dirname);
        $this->open  = true;
    }

    /**
     * @return array{uniq: string, flags: list<Flag|string>, filename: string, size: int|null}
     * @throws Exception\OutOfBoundsException When there is no such message.
     */
    protected function file(int $id): array
    {
        return (
            $this->files[self::checkNumber($id, count($this->files)) - 1]
                ?? throw new Exception\OutOfBoundsException("There is no message {$id}")
        );
    }
}
