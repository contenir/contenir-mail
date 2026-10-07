<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Address;
use Contenir\Mail\Exception\InvalidArgumentException;

use function is_string;

/**
 * The SMTP envelope, when it should differ from the message's Sender, From and recipient headers.
 *
 * ```php
 * $transport->setEnvelope(new Envelope(from: 'bounces@example.com', to: ['archive@example.com']));
 * ```
 */
final readonly class Envelope
{
    /** The MAIL FROM address, or null to use the message's Sender or first From address */
    public ?string $from;

    /**
     * The RCPT TO addresses; empty to use the message's To, Cc and Bcc addresses.
     *
     * @var list<string>
     */
    public array $to;

    /**
     * @param string|iterable<string> $to
     * @throws InvalidArgumentException When an address is invalid or contains CR or LF.
     */
    public function __construct(?string $from = null, string|iterable $to = [])
    {
        $this->from = null === $from ? null : (new Address($from))->getEmail();

        $recipients = [];
        foreach (is_string($to) ? [$to] : $to as $address) {
            $recipients[] = (new Address($address))->getEmail();
        }

        $this->to = $recipients;
    }
}
