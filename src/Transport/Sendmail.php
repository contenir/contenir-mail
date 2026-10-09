<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Closure;
use Contenir\Mail;
use Contenir\Mail\Header\To;
use Override;
use Traversable;

use function array_is_list;
use function implode;
use function is_string;
use function iterator_to_array;
use function mail;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function str_replace;
use function trim;

use const PHP_OS_FAMILY;

/**
 * Sends mail through the local sendmail program, with PHP's mail() function or by running
 * the program directly.
 *
 * ```php
 * $transport = new Sendmail(['parameters' => '-R hdrs']);
 * $transport = new Sendmail(['path' => '/usr/sbin/sendmail']);
 * $transport->send($message);
 * ```
 *
 * With a path, the program is run without a shell as `path [parameters] -oi -f sender --
 * recipients`, with the message on its standard input; the recipients are the To, Cc and
 * Bcc addresses, and the Bcc header is left out of the message. A sender starting with "-"
 * is refused, so it cannot be read as an option.
 *
 * Without a path, mail() is used. The envelope sender is passed as "-f" only when it
 * consists of characters that are safe on a shell command line; any other sender is refused
 * rather than escaped, because mail() escapes the parameters a second time and the two
 * escapings do not compose.
 *
 * @mago-expect lint:cyclomatic-complexity Each argument of mail() differs between Windows and other systems.
 * @api
 */
final class Sendmail implements TransportInterface
{
    /**
     * An envelope sender safe to pass to sendmail unquoted: a dot-atom of letters, digits and
     * . _ + = - in the local part, and a host name.
     */
    public const string SAFE_SENDER = '/^[A-Za-z0-9_+=][A-Za-z0-9._+=-]*@[A-Za-z0-9][A-Za-z0-9.-]*$/D';

    private SendmailConfig $config;

    /** @var Closure(string, string, string, string, string): void */
    private Closure $mailer;

    /**
     * @param SendmailConfig|iterable<mixed, mixed>|string|null $config A config, the settings
     *     SendmailConfig::fromIterable() reads, or the sendmail parameters as a string or list.
     * @param (callable(string, string, string, string, string): void)|null $mailer Called instead of mail()
     *     with the recipients, subject, body, headers and parameters.
     * @param string $operatingSystem The PHP_OS_FAMILY of the host; "Windows" changes how mail() is called.
     * @throws Mail\Exception\InvalidArgumentException When the settings are invalid.
     */
    public function __construct(
        SendmailConfig|iterable|string|null $config = null,
        ?callable $mailer = null,
        private readonly string $operatingSystem = PHP_OS_FAMILY,
    ) {
        $this->config = self::readConfig($config);
        $this->mailer = null === $mailer ? $this->mail(...) : $mailer(...);
    }

    public function getConfig(): SendmailConfig
    {
        return $this->config;
    }

    /**
     * @throws Exception\RuntimeException When the message has no recipient, a header is unsafe, the
     *     envelope sender is unsafe for the command line, or mail() or the sendmail program fails.
     * @throws Mail\Mime\Exception\RuntimeException When the message body cannot be written.
     */
    #[Override]
    public function send(Mail\Message $message): void
    {
        $headers = HeaderGuard::check($message->getHeaders());
        if (null !== $this->config->path) {
            SendmailProcess::send($this->config, $this->config->path, $message, $headers);

            return;
        }

        ($this->mailer)(
            $this->prepareRecipients($headers),
            $headers->get('subject')?->getEncodedFieldValue() ?? '',
            $this->prepareBody($message),
            $headers->without('To')->without('Subject')->toString(),
            $this->prepareParameters($message),
        );
    }

    /**
     * @throws Exception\RuntimeException When there is no To, Cc or Bcc header, or To is empty.
     */
    private function prepareRecipients(Mail\Headers $headers): string
    {
        $to = $headers->get('to');
        if (null === $to && ! $headers->has('cc') && ! $headers->has('bcc')) {
            throw new Exception\RuntimeException(
                'Invalid email; contains no at least one of "To", "Cc", and "Bcc" header',
            );
        }

        if (null === $to) {
            return '';
        }

        if (! $to instanceof To || $to->getAddressList()->isEmpty()) {
            throw new Exception\RuntimeException('Invalid "To" header; contains no addresses');
        }

        if (! $this->isWindows()) {
            return $to->getEncodedFieldValue();
        }

        $addresses = [];
        foreach ($to->getAddressList() as $address) {
            $addresses[] = $address->getEmail();
        }

        return implode(', ', $addresses);
    }

    /**
     * Windows sends through SMTP itself, so a line starting with "." is doubled there.
     *
     * @throws Mail\Mime\Exception\RuntimeException
     */
    private function prepareBody(Mail\Message $message): string
    {
        $text = $message->getBodyText();

        return $this->isWindows()
            ? str_replace(
                search: "\n.",
                replace: "\n..",
                subject: $text,
            ) : $text;
    }

    /**
     * The configured parameters, with "-f" and the envelope sender added unless they set one.
     *
     * @throws Exception\RuntimeException When the sender is not safe for the command line.
     */
    private function prepareParameters(Mail\Message $message): string
    {
        if ($this->isWindows()) {
            return '';
        }

        $parameters = $this->config->toString();
        if (1 === preg_match('/(^| )-f/', $parameters)) {
            return $parameters;
        }

        $sender = ($message->getSender() ?? $message->getFrom()->first())?->getEmail();
        if (null === $sender) {
            return $parameters;
        }

        if (1 !== preg_match(self::SAFE_SENDER, $sender)) {
            throw new Exception\RuntimeException(
                'The envelope sender cannot be passed safely to sendmail; it may only contain letters, digits '
                    . 'and . _ + = - before the @. Set "-f" in the sendmail parameters to choose another.',
            );
        }

        return trim("{$parameters} -f{$sender}");
    }

    private function isWindows(): bool
    {
        return 'Windows' === $this->operatingSystem;
    }

    /**
     * Send with PHP's mail(), turning its warnings into an exception.
     *
     * The I/O boundary: the unit tests pass a mailer instead, since calling mail() would hand
     * the message to the host's sendmail. sendmail_path cannot be changed at run time.
     *
     * @throws Exception\RuntimeException When mail() fails.
     *
     * @codeCoverageIgnore
     */
    private function mail(string $to, string $subject, string $body, string $headers, string $parameters): void
    {
        $error = null;
        set_error_handler(static function (int $_number, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $sent = mail($to, $subject, $body, $headers, $parameters);
        } finally {
            restore_error_handler();
        }

        if (null !== $error || ! $sent) {
            throw new Exception\RuntimeException('Unable to send mail: ' . ($error ?? 'Unknown error'));
        }
    }

    /**
     * @param SendmailConfig|iterable<mixed, mixed>|string|null $config
     * @throws Mail\Exception\InvalidArgumentException
     */
    private static function readConfig(SendmailConfig|iterable|string|null $config): SendmailConfig
    {
        if ($config instanceof SendmailConfig) {
            return $config;
        }

        if (null === $config || is_string($config)) {
            return new SendmailConfig($config ?? []);
        }

        $values = $config instanceof Traversable ? iterator_to_array($config) : $config;
        if ([] !== $values && array_is_list($values)) {
            return SendmailConfig::fromIterable(['parameters' => $values]);
        }

        return SendmailConfig::fromIterable($values);
    }
}
