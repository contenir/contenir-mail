<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

/**
 * How an mbox file escapes body lines that would read as a "From " separator.
 *
 * Each message starts with a line "From sender date". A body line that
 * starts with "From " is written with a ">" in front; the variants differ in
 * whether lines that already start with ">From " get another one, and so
 * whether the escaping can be undone. The Content-Length variants (mboxcl,
 * mboxcl2) are read as mboxo, splitting on "From " lines.
 *
 * @api
 */
enum MboxFormat: string
{
    /**
     * Only "From " lines are quoted, so ">From " cannot be told apart from an
     * escaped "From " and body lines are read as they are written. The safe
     * default when the writer is unknown.
     */
    case Mboxo = 'mboxo';

    /**
     * Every line matching ">*From " gets one more ">", so reading removes one
     * ">" from such lines and restores the body exactly. Used by mutt, Postfix
     * (with mailbox_delivery_lock) and procmail.
     */
    case Mboxrd = 'mboxrd';
}
