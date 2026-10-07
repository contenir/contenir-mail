<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime;
use Contenir\Mail\Protocol\ErrorCapture;

use function array_unique;
use function fwrite;
use function hrtime;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function rewind;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function tmpfile;
use function trim;
use function usleep;

/**
 * Runs a sendmail program with the message on its standard input, for the Sendmail transport
 * when it is given a path.
 *
 * The command is `path [parameters] -oi -f sender -- recipients`, an argument list run
 * without a shell, so no argument is ever parsed by one.
 *
 * Standard input, output and error are temporary files rather than pipes, so a program that
 * writes a lot before it reads, or a lot to both outputs, cannot block the transport. PHP
 * closes and removes them once they go out of scope.
 *
 * @internal Used by Sendmail.
 *
 * @mago-expect lint:cyclomatic-complexity Building the command, running the program and waiting for it with a timeout.
 * @mago-expect lint:kan-defect Building the command, running the program and waiting for it with a timeout.
 */
final readonly class SendmailProcess
{
    /**
     * Send a message: its To, Cc and Bcc addresses are the recipients, its Sender or first From
     * address the envelope sender unless the parameters set "-f", and it is written without its
     * Bcc header, in local line endings.
     *
     * @throws Exception\RuntimeException When there is no recipient, the sender starts with "-", or the program fails.
     * @throws Mime\Exception\RuntimeException When the message body cannot be written.
     */
    public static function send(SendmailConfig $config, string $path, Message $message, Headers $headers): void
    {
        $recipients = [];
        foreach ([$message->getTo(), $message->getCc(), $message->getBcc()] as $list) {
            foreach ($list as $address) {
                $recipients[] = $address->getEmail();
            }
        }

        if ([] === $recipients) {
            throw new Exception\RuntimeException('Invalid email; it has no To, Cc or Bcc address to send to');
        }

        $data = $headers->without('Bcc')->toString() . Headers::EOL . $message->getBodyText();

        self::run(
            [
                $path,
                ...$config->parameters,
                '-oi',
                ...self::senderArguments($config, $message),
                '--',
                ...array_unique(
                    $recipients,
                ),
            ],
            str_replace(
                search: "\r\n",
                replace: "\n",
                subject: $data,
            ),
            $config->timeout,
        );
    }

    /**
     * @param non-empty-list<string> $command The program and its arguments.
     * @param int $timeout Seconds to wait before stopping the program.
     * @throws Exception\RuntimeException When the program cannot be started, runs longer than the timeout, or exits with a status other than 0.
     */
    public static function run(array $command, string $input, int $timeout = SendmailConfig::DEFAULT_TIMEOUT): void
    {
        $stdin  = self::temporaryFile();
        $stdout = self::temporaryFile();
        $stderr = self::temporaryFile();

        if (fwrite($stdin, $input) !== strlen($input)) {
            throw new Exception\RuntimeException('Unable to buffer the message for sendmail');
        }

        rewind($stdin);

        [$process, $warning] = ErrorCapture::run(static function () use ($command, $stdin, $stdout, $stderr): mixed {
            $_pipes = [];

            return proc_open($command, [0 => $stdin, 1 => $stdout, 2 => $stderr], $_pipes);
        });
        if (! is_resource($process)) {
            throw new Exception\RuntimeException(sprintf(
                'Unable to run sendmail "%s": %s',
                $command[0],
                $warning,
            ));
        }

        $status = self::wait($process, $timeout);
        if (null === $status) {
            throw new Exception\RuntimeException(sprintf(
                'Sendmail "%s" did not finish within %d seconds and was stopped',
                $command[0],
                $timeout,
            ));
        }

        if (0 !== $status) {
            throw new Exception\RuntimeException(sprintf(
                'Sendmail "%s" failed with exit status %d: %s',
                $command[0],
                $status,
                self::output($stderr, $stdout),
            ));
        }
    }

    /**
     * Wait for the program to exit, stopping it once the timeout has passed.
     *
     * @param resource $process
     * @return int|null The exit status, or null when the program was stopped.
     */
    private static function wait(mixed $process, int $timeout): ?int
    {
        $deadline = hrtime(as_number: true) + ($timeout * 1_000_000_000);
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                proc_close($process);

                return $status['exitcode'];
            }

            usleep(microseconds: 10_000);
        } while (hrtime(as_number: true) < $deadline);

        proc_terminate($process);
        proc_close($process);

        return null;
    }

    /**
     * "-f" and the envelope sender as separate arguments, unless the parameters set one.
     *
     * @return list<string>
     * @throws Exception\RuntimeException When the sender starts with "-".
     */
    private static function senderArguments(SendmailConfig $config, Message $message): array
    {
        if (1 === preg_match('/(^| )-f/', $config->toString())) {
            return [];
        }

        $sender = ($message->getSender() ?? $message->getFrom()->first())?->getEmail();
        if (null === $sender) {
            return [];
        }

        if (str_starts_with($sender, '-')) {
            throw new Exception\RuntimeException(
                'The envelope sender must not start with "-", which sendmail would read as an option',
            );
        }

        return ['-f', $sender];
    }

    /**
     * What the program wrote to standard error, or else to standard output, to explain a failure.
     *
     * The program shares each file's offset, so it is rewound rather than read from where PHP last left it.
     *
     * @param resource $stderr
     * @param resource $stdout
     */
    private static function output(mixed $stderr, mixed $stdout): string
    {
        foreach ([$stderr, $stdout] as $stream) {
            rewind($stream);
            $text = stream_get_contents($stream);
            if (false !== $text && '' !== trim($text)) {
                return trim($text);
            }
        }

        return 'no output';
    }

    /**
     * @return resource
     * @throws Exception\RuntimeException When no temporary file can be created.
     */
    private static function temporaryFile(): mixed
    {
        $file = tmpfile();
        if (false === $file) {
            throw new Exception\RuntimeException('Unable to create a temporary file for sendmail');
        }

        return $file;
    }
}
