<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime;
use Contenir\Mail\SystemClock;
use Override;
use Psr\Clock\ClockInterface;
use Random\RandomException;

use function bin2hex;
use function chmod;
use function fclose;
use function fopen;
use function get_debug_type;
use function is_string;
use function preg_match;
use function random_bytes;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Writes each message to a new file instead of sending it, for development and testing.
 *
 * Files are created with permissions 0600 and never overwrite an existing file, so a
 * name planted in a shared directory, or a symlink in its place, makes the send fail.
 *
 * @mago-expect analysis:mixed-assignment The name callback is user code; its result is checked before use.
 * @api
 */
final class File implements TransportInterface
{
    private FileConfig $config;

    private ?string $lastFile = null;

    /**
     * @param FileConfig|iterable<mixed, mixed>|null $config A config, or the settings FileConfig::fromIterable() reads.
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When the settings are invalid.
     */
    public function __construct(
        FileConfig|iterable|null $config = null,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->config = $config instanceof FileConfig ? $config : FileConfig::fromIterable($config ?? []);
    }

    public function getConfig(): FileConfig
    {
        return $this->config;
    }

    /**
     * Write the message to a new file in the configured directory.
     *
     * The message is written to the file as it is made, so an attachment read from a stream is
     * never held in memory as a whole; a file that cannot be finished is removed.
     *
     * @throws Exception\RuntimeException When the file name is not a plain name, a header is unsafe,
     *     the file exists or cannot be created, or the message cannot be written to it.
     * @throws Mime\Exception\RuntimeException When the message cannot be composed, such as after
     *     embed() without setHtml().
     * @throws RandomException When the system has no source of randomness for the default name.
     */
    #[Override]
    public function send(Message $message): void
    {
        $name = null === $this->config->callback
            ? sprintf('ContenirMail_%d_%s.eml', $this->clock->now()->getTimestamp(), bin2hex(random_bytes(8)))
            : ($this->config->callback)($this);
        if (! is_string($name) || ! self::isPlainName($name)) {
            throw new Exception\RuntimeException(sprintf(
                'The file name callback must return a plain file name without directories; got %s',
                is_string($name) ? "\"{$name}\"" : get_debug_type($name),
            ));
        }

        $headers = HeaderGuard::check($message->getHeaders())->toString() . Headers::EOL;
        $file    = $this->config->path . DIRECTORY_SEPARATOR . $name;

        $error = '';
        set_error_handler(static function (int $_number, string $text) use (&$error): bool {
            $error = $text;
            return true;
        });
        $handle = fopen($file, mode: 'xb');
        restore_error_handler();

        if (false === $handle) {
            throw new Exception\RuntimeException(sprintf('Unable to create mail file "%s": %s', $file, $error));
        }

        chmod($file, permissions: 0o600);
        $written = false;
        try {
            Mime\StreamOutput::write($handle, $headers);
            $message->writeBodyTo($handle);
            $written = true;
        } catch (Mime\Exception\RuntimeException $e) {
            throw new Exception\RuntimeException(
                sprintf('Unable to write all of mail file "%s": %s', $file, $e->getMessage()),
                previous: $e,
            );
        } finally {
            fclose($handle);
            if (! $written) {
                unlink($file);
            }
        }

        $this->lastFile = $file;
    }

    /**
     * The path of the last file written, or null before the first send.
     */
    public function getLastFile(): ?string
    {
        return $this->lastFile;
    }

    /**
     * A name with no directory separator, control character or drive colon, and not "." or "..".
     */
    private static function isPlainName(string $name): bool
    {
        return (
            '' !== $name
                && '.' !== $name
                && '..' !== $name
                && 1 !== preg_match('#[/\\\\:\x00-\x1F\x7F]#', $name)
        );
    }
}
