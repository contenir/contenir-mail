<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Utf8;

use function array_map;
use function preg_replace;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Makes text from received mail safe to display or to use as a file name.
 *
 * Header values are kept as the sender wrote them; these helpers are for
 * the moment they reach a screen or a file system.
 *
 * @api
 */
final class SafeText
{
    /** Longest file name in bytes on common file systems */
    public const int MAX_FILENAME_BYTES = 255;

    /** Longest extension, its dot included, kept when a long file name is shortened */
    public const int MAX_EXTENSION_BYTES = 16;

    /** Used when nothing of a file name is left */
    public const string DEFAULT_FILENAME = 'attachment';

    /**
     * C0 and C1 controls, DEL, and the Unicode bidirectional marks,
     * embeddings, overrides and isolates that can make "gpj.exe" read as "exe.jpg".
     */
    private const string UNSAFE = '/[\x00-\x1F\x7F\x{80}-\x{9F}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * Text for display: valid UTF-8, without control or bidirectional
     * formatting characters, whitespace runs collapsed to one space.
     */
    public static function display(string $text): string
    {
        $text = (string) preg_replace(self::UNSAFE, replacement: ' ', subject: Utf8::scrub($text));

        return trim((string) preg_replace('/\s+/u', replacement: ' ', subject: $text));
    }

    /**
     * A base name safe to create in a directory: the part after any "/" or
     * "\", without control or bidirectional characters, characters Windows
     * reserves, or leading and trailing dots and spaces, and at most 255
     * bytes with its extension kept. "attachment" when nothing is left.
     */
    public static function filename(string $filename): string
    {
        $name = self::display($filename);
        $name = substr($name, (int) strrpos("/{$name}", needle: '/'));
        $name = substr($name, (int) strrpos("\\{$name}", needle: '\\'));
        $name = (string) preg_replace('/[<>:"|?*]/', replacement: '_', subject: $name);
        $name = trim($name, characters: '. ');
        if ('' === $name) {
            return self::DEFAULT_FILENAME;
        }

        $dot       = strrpos($name, needle: '.');
        $extension = false !== $dot && (strlen($name) - $dot) <= self::MAX_EXTENSION_BYTES ? substr($name, $dot) : '';
        $base      = substr($name, offset: 0, length: strlen($name) - strlen($extension));

        /** @mago-expect analysis:possibly-invalid-argument The extension is at most MAX_EXTENSION_BYTES long. */
        return (
            Utf8::cut($base, self::MAX_FILENAME_BYTES - strlen($extension))
                . $extension
        );
    }

    /**
     * The addresses with display names and comments made safe by display().
     */
    public static function addressList(AddressList $addresses): AddressList
    {
        return new AddressList(...array_map(
            static fn(Address $address): Address => new Address(
                $address->getEmail(),
                self::displayOrNull($address->getName()),
                self::displayOrNull($address->getComment()),
                $address->isStrict(),
            ),
            $addresses->toArray(),
        ));
    }

    private static function displayOrNull(?string $text): ?string
    {
        return null === $text ? null : self::display($text);
    }
}
