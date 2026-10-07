<?php

namespace Contenir\Mail\Transport;

use Contenir\Mail;
use Contenir\Mail\Transport\Exception\InvalidArgumentException;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Override;
use Traversable;

use function escapeshellarg;
use function gettype;
use function implode;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function mail;
use function preg_match;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtoupper;
use function substr;
use function trim;

use const PHP_OS;
use const PHP_VERSION_ID;

/**
 * Class for sending email via the PHP internal mail() function
 */
class Sendmail implements TransportInterface
{
    /**
     * Config options for sendmail parameters
     *
     * @var string
     */
    protected $parameters;

    /**
     * Callback to use when sending mail; typically, {@link mailHandler()}
     *
     * @var callable
     */
    protected $callable;

    /**
     * error information
     *
     * @var string
     */
    protected $errstr;

    /** @var string */
    protected $operatingSystem;

    /**
     * @param  null|string|array|Traversable $parameters OPTIONAL (Default: null)
     */
    public function __construct($parameters = null)
    {
        if (null !== $parameters) {
            $this->setParameters($parameters);
        }
        $this->callable = [$this, 'mailHandler'];
    }

    /**
     * Set sendmail parameters
     *
     * Used to populate the additional_parameters argument to mail()
     *
     * @param  null|string|array|Traversable $parameters
     * @throws InvalidArgumentException
     * @return Sendmail
     */
    public function setParameters($parameters)
    {
        if (null === $parameters || is_string($parameters)) {
            $this->parameters = $parameters;
            return $this;
        }

        if (! is_array($parameters) && ! $parameters instanceof Traversable) {
            throw new InvalidArgumentException(sprintf(
                '%s expects a string, array, or Traversable object of parameters; received "%s"',
                __METHOD__,
                is_object($parameters) ? $parameters::class : gettype($parameters),
            ));
        }

        $string = '';
        foreach ($parameters as $param) {
            $string .= " {$param}";
        }

        $this->parameters = trim($string);
        return $this;
    }

    /**
     * Set callback to use for mail
     *
     * Primarily for testing purposes, but could be used to curry arguments.
     *
     * @param  callable $callable
     * @throws InvalidArgumentException
     * @return Sendmail
     */
    public function setCallable($callable)
    {
        if (! is_callable($callable)) {
            throw new InvalidArgumentException(sprintf(
                '%s expects a callable argument; received "%s"',
                __METHOD__,
                is_object($callable) ? $callable::class : gettype($callable),
            ));
        }
        $this->callable = $callable;
        return $this;
    }

    /**
     * Send a message
     *
     * @throws RuntimeException When the message has no recipients or its From header is unsafe.
     */
    #[Override]
    public function send(Mail\Message $message)
    {
        $to      = $this->prepareRecipients($message);
        $subject = $this->prepareSubject($message);
        $body    = $this->prepareBody($message);
        $headers = $this->prepareHeaders($message);
        $params  = $this->prepareParameters($message);

        // On *nix platforms, we need to replace \r\n with \n
        // sendmail is not an SMTP server, it is a unix command - it expects LF
        if (PHP_VERSION_ID < 80_000 && ! $this->isWindowsOs()) {
            $to      = str_replace("\r\n", "\n", $to);
            $subject = str_replace("\r\n", "\n", $subject);
            $body    = str_replace("\r\n", "\n", $body);
            $headers = str_replace("\r\n", "\n", $headers);
        }

        ($this->callable)($to, $subject, $body, $headers, $params);
    }

    /**
     * Prepare recipients list
     *
     * @throws RuntimeException
     * @return string
     */
    protected function prepareRecipients(Mail\Message $message)
    {
        $headers = $message->getHeaders();

        $hasTo = $headers->has('to');
        if (! $hasTo && ! $headers->has('cc') && ! $headers->has('bcc')) {
            throw new RuntimeException(
                'Invalid email; contains no at least one of "To", "Cc", and "Bcc" header',
            );
        }

        if (! $hasTo) {
            return '';
        }

        $to = $headers->get('to');
        if (! $to instanceof Mail\Header\To || $to->getAddressList()->isEmpty()) {
            throw new RuntimeException('Invalid "To" header; contains no addresses');
        }

        // If not on Windows, return normal string
        if (! $this->isWindowsOs()) {
            return $to->getEncodedFieldValue();
        }

        // Otherwise, return list of emails
        $addresses = [];
        foreach ($to->getAddressList() as $address) {
            $addresses[] = $address->getEmail();
        }
        return implode(', ', $addresses);
    }

    /**
     * Prepare the subject line string
     *
     * @return string
     */
    protected function prepareSubject(Mail\Message $message)
    {
        return $message->getHeaders()->get('subject')?->getEncodedFieldValue() ?? '';
    }

    /**
     * Prepare the body string
     *
     * @return string
     */
    protected function prepareBody(Mail\Message $message)
    {
        if (! $this->isWindowsOs()) {
            // *nix platforms can simply return the body text
            return $message->getBodyText();
        }

        // On windows, lines beginning with a full stop need to be fixed
        $text = $message->getBodyText();
        return str_replace("\n.", "\n..", $text);
    }

    /**
     * Prepare the textual representation of headers
     *
     * @return string
     */
    protected function prepareHeaders(Mail\Message $message)
    {
        // mail() takes To and Subject as separate arguments
        foreach ($message->getFrom() as $address) {
            if (str_contains($address->getEmail(), '\\"')) {
                throw new RuntimeException('Potential code injection in From header');
            }
        }

        return $message->getHeaders()->without('To')->without('Subject')->toString();
    }

    /**
     * Prepare additional_parameters argument
     *
     * Basically, overrides the MAIL FROM envelope with either the Sender or
     * From address.
     *
     * @return string
     */
    protected function prepareParameters(Mail\Message $message)
    {
        if ($this->isWindowsOs()) {
            return '';
        }

        $parameters = (string) $this->parameters;
        if (preg_match('/(^| )\-f.+/', $parameters)) {
            return $parameters;
        }

        $sender = $message->getSender();
        if ($sender instanceof Mail\Address) {
            return $parameters . ' -f' . escapeshellarg($sender->getEmail());
        }

        $from = $message->getFrom()->first();
        if (null !== $from) {
            return $parameters . ' -f' . escapeshellarg($from->getEmail());
        }

        return $parameters;
    }

    /**
     * Send mail using PHP native mail()
     *
     * @param  string $to
     * @param  string $subject
     * @param  string $message
     * @param  string $headers
     * @param  null|string $parameters
     * @throws RuntimeException
     */
    public function mailHandler($to, $subject, $message, $headers, $parameters)
    {
        set_error_handler([$this, 'handleMailErrors']);
        if (null === $parameters) {
            $result = mail($to, $subject, $message, $headers);
        } else {
            $result = mail($to, $subject, $message, $headers, $parameters);
        }
        restore_error_handler();

        if (null !== $this->errstr || ! $result) {
            $errstr = $this->errstr;
            if (empty($errstr)) {
                $errstr = 'Unknown error';
            }
            throw new RuntimeException("Unable to send mail: {$errstr}");
        }
    }

    /**
     * Temporary error handler for PHP native mail().
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param string $errline
     * @param array  $errcontext
     * @return bool always true
     */
    public function handleMailErrors($errno, $errstr, $errfile = null, $errline = null, ?array $errcontext = null)
    {
        $this->errstr = $errstr;
        return true;
    }

    /**
     * Is this a windows OS?
     *
     * @return bool
     */
    protected function isWindowsOs()
    {
        if (! $this->operatingSystem) {
            $this->operatingSystem = strtoupper(substr(PHP_OS, 0, 3));
        }
        return 'WIN' == $this->operatingSystem;
    }
}
