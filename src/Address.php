<?php

declare(strict_types=1);

namespace Contenir\Mail;

use Contenir\Mail\Validator\DomainName;
use Contenir\Mail\Validator\EmailAddressValidator;
use ReflectionClass;

use function addcslashes;
use function idn_to_ascii;
use function preg_match;
use function sprintf;
use function strpbrk;
use function strrpos;
use function substr;
use function trim;

use const INTL_IDNA_VARIANT_UTS46;

/**
 * An e-mail address with an optional display name and comment.
 */
final readonly class Address
{
    /**
     * RFC 5322 specials that force a display name into a quoted-string.
     *
     * "." is left out: unquoted in a display name it is accepted obsolete
     * syntax, and quoting it would change the output for names such as
     * "John Q. Public".
     */
    private const string NAME_SPECIALS = '()<>[]:;@\\,"';

    /** C0 control characters other than tab, DEL and C1 controls, which no part of an address may hold */
    private const string CONTROLS = '/[\x00-\x08\x0A-\x1F\x7F\x{80}-\x{9F}]/u';

    /**
     * Bidirectional formatting characters, which can make an e-mail address
     * display as a different one; none belong in an address.
     */
    private const string EMAIL_BIDI = '/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /**
     * Embeddings, overrides and isolates, which can reorder the text around a
     * display name; the left-to-right and right-to-left marks stay allowed,
     * since right-to-left names can need them.
     */
    private const string NAME_BIDI = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    /** A lenient address: one "@" between parts holding no whitespace or header specials */
    private const string LENIENT_EMAIL = '/^[^\s@<>()\[\],;:"\\\\]+@[^\s@<>()\[\],;:"\\\\]+$/uD';

    private string $email;

    private ?string $name;

    private ?string $comment;

    /**
     * @throws Exception\InvalidArgumentException When the address is invalid or a part contains CR or LF.
     */
    public function __construct(string $email, ?string $name = null, ?string $comment = null)
    {
        $email     = self::checkParts($email, $name, $comment);
        $validator = new EmailAddressValidator();
        if (! $validator->isValid($email)) {
            throw new Exception\InvalidArgumentException($validator->getMessages()[0] ?? 'Invalid email address');
        }

        $this->initialise($email, $name, $comment);
    }

    /**
     * An address real mail servers take but RFC 5322 refuses, such as one with
     * consecutive or trailing dots in the local part, or a host name the strict
     * check refuses, such as one with an underscore (contenir/contenir-mail#18).
     *
     * It still needs one "@" and a domain, and refuses what could break a header
     * or an SMTP command: whitespace, control characters and the specials
     * <>()[],;:"\. A domain that is not ASCII must still convert with IDNA, as it
     * is written that way. Reading mail never applies this: use it only for
     * addresses your own application gives.
     *
     * @throws Exception\InvalidArgumentException When the address is not usable even leniently.
     *
     * @mago-expect analysis:unhandled-thrown-type ReflectionException is thrown only for internal final classes, never for this one.
     */
    public static function lenient(string $email, ?string $name = null, ?string $comment = null): self
    {
        $email = self::checkParts($email, $name, $comment);
        if (1 !== preg_match(self::LENIENT_EMAIL, $email)) {
            throw new Exception\InvalidArgumentException(
                'An address needs one "@" and a domain, without whitespace or the characters <>()[],;:"\\',
            );
        }

        $domain = substr($email, (int) strrpos($email, needle: '@') + 1);
        if (
            1 === preg_match('/[\x80-\xFF]/', $domain)
            && false === idn_to_ascii($domain, DomainName::IDNA_OPTIONS, INTL_IDNA_VARIANT_UTS46)
        ) {
            throw new Exception\InvalidArgumentException("The domain {$domain} cannot be written as ASCII");
        }

        $address = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $address->initialise($email, $name, $comment);

        return $address;
    }

    /**
     * The checks every address passes, strict or lenient: no line breaks, control
     * characters or bidirectional overrides, and UTF-8 throughout.
     *
     * @return string The address, trimmed.
     * @throws Exception\InvalidArgumentException When a check fails.
     */
    private static function checkParts(string $email, ?string $name, ?string $comment): string
    {
        // Checked before trimming, so a trailing line break is rejected rather than silently removed
        if (1 === preg_match("/[\r\n]/", $email . ($name ?? '') . ($comment ?? ''))) {
            throw new Exception\InvalidArgumentException('CRLF injection detected');
        }

        $email = trim($email);
        if ('' === $email) {
            throw new Exception\InvalidArgumentException('Email must be a valid email address');
        }

        if (! Utf8::isValid($email . ($name ?? '') . ($comment ?? ''))) {
            throw new Exception\InvalidArgumentException('Address must be UTF-8 text');
        }

        if (1 === preg_match(self::CONTROLS, $email . ($name ?? '') . ($comment ?? ''))) {
            throw new Exception\InvalidArgumentException('Address must not contain control characters');
        }

        if (
            1 === preg_match(self::EMAIL_BIDI, $email)
            || 1 === preg_match(self::NAME_BIDI, ($name ?? '') . ($comment ?? ''))
        ) {
            throw new Exception\InvalidArgumentException('Address must not contain bidirectional overrides');
        }

        return $email;
    }

    private function initialise(string $email, ?string $name, ?string $comment): void
    {
        $this->email   = $email;
        $this->name    = self::nonEmpty($name);
        $this->comment = self::nonEmpty($comment);
    }

    /**
     * Parse "Display Name <user@example.com>" or a bare address.
     *
     * @throws Exception\InvalidArgumentException When the string is not an address.
     */
    public static function fromString(string $address, ?string $comment = null): self
    {
        $matches = [];
        if (1 !== preg_match('/^((?P<name>.*)<(?P<namedEmail>[^>]+)>|(?P<email>.+))$/', $address, $matches)) {
            throw new Exception\InvalidArgumentException('Invalid address format');
        }

        $email = $matches['email'] ?? '';
        if ('' === $email) {
            $email = $matches['namedEmail'] ?? '';
        }

        // Outlook sometimes wraps addresses in single quotes, which is not valid
        return new self(trim(trim($email), characters: "'"), $matches['name'] ?? null, $comment);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * The address as it appears in a header: `"Name" <user@example.com>` or `user@example.com`.
     *
     * The display name is quoted when it contains RFC 5322 specials, so it can
     * never be read back as extra addresses.
     */
    public function toString(): string
    {
        if (null === $this->name) {
            return $this->email;
        }

        return sprintf('%s <%s>', self::quoteDisplayName($this->name), $this->email);
    }

    /**
     * @internal Shared with the address-list headers, which encode names that are not ASCII.
     */
    public static function quoteDisplayName(string $name): string
    {
        if (false === strpbrk($name, self::NAME_SPECIALS)) {
            return $name;
        }

        return sprintf('"%s"', addcslashes($name, characters: '\\"'));
    }

    private static function nonEmpty(?string $value): ?string
    {
        $value = trim($value ?? '');

        return '' === $value ? null : $value;
    }
}
