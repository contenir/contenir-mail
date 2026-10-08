<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Imap;

/**
 * The namespaces a server offers (RFC 2342): the user's own mailboxes, other
 * users' mailboxes and shared mailboxes. A kind of namespace the server does
 * not have is an empty list.
 *
 * @api
 */
final readonly class Namespaces
{
    /**
     * @param list<NamespaceEntry> $personal
     * @param list<NamespaceEntry> $otherUsers
     * @param list<NamespaceEntry> $shared
     */
    public function __construct(
        public array $personal = [],
        public array $otherUsers = [],
        public array $shared = [],
    ) {}
}
