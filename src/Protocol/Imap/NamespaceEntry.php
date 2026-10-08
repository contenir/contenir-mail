<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Imap;

/**
 * One namespace a server offers (RFC 2342): the prefix its mailbox names
 * start with, such as "INBOX." or "#shared/", and the hierarchy delimiter
 * used in it.
 *
 * @api
 */
final readonly class NamespaceEntry
{
    /**
     * @param string $prefix The prefix as UTF-8; empty for the namespace of names without one.
     * @param string|null $delimiter The hierarchy delimiter; null when the namespace is flat.
     */
    public function __construct(
        public string $prefix,
        public ?string $delimiter,
    ) {}
}
