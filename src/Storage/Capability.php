<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

/**
 * What a storage may support, as AbstractStorage::supports() is asked. The
 * value is the key of the same feature in AbstractStorage::getCapabilities().
 *
 * @api
 */
enum Capability: string
{
    /** Messages have unique IDs that outlive their numbers; Mbox's do not */
    case UniqueId = 'uniqueid';

    /** Messages can be removed */
    case Delete = 'delete';

    /** Folders can be created */
    case Create = 'create';

    /** The headers can be read without the body, as POP3 TOP does */
    case Top = 'top';

    /** A part of a message can be fetched without the rest; not over POP3 */
    case FetchPart = 'fetchPart';

    /** Messages keep flags, such as Flag::Seen */
    case Flags = 'flags';
}
