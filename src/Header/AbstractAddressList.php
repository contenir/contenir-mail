<?php

namespace Contenir\Mail\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Headers;
use Contenir\Mail\Storage\Exception\RuntimeException;

use function addcslashes;
use function array_filter;
use function array_map;
use function assert;
use function idn_to_ascii;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function strpbrk;
use function strtolower;
use function strtr;
use function trim;

use const IDNA_DEFAULT;
use const IDNA_ERROR_BIDI;
use const IDNA_ERROR_CONTEXTJ;
use const IDNA_ERROR_DISALLOWED;
use const IDNA_ERROR_DOMAIN_NAME_TOO_LONG;
use const IDNA_ERROR_EMPTY_LABEL;
use const IDNA_ERROR_HYPHEN_3_4;
use const IDNA_ERROR_INVALID_ACE_LABEL;
use const IDNA_ERROR_LABEL_HAS_DOT;
use const IDNA_ERROR_LABEL_TOO_LONG;
use const IDNA_ERROR_LEADING_COMBINING_MARK;
use const IDNA_ERROR_LEADING_HYPHEN;
use const IDNA_ERROR_PUNYCODE;
use const IDNA_ERROR_TRAILING_HYPHEN;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * Base class for headers composing address lists (to, from, cc, bcc, reply-to)
 */
abstract class AbstractAddressList implements HeaderInterface
{
    private const IDNA_ERROR_MAP = [
        IDNA_ERROR_EMPTY_LABEL            => 'empty label',
        IDNA_ERROR_LABEL_TOO_LONG         => 'label too long',
        IDNA_ERROR_DOMAIN_NAME_TOO_LONG   => 'domain name too long',
        IDNA_ERROR_LEADING_HYPHEN         => 'leading hyphen',
        IDNA_ERROR_TRAILING_HYPHEN        => 'trailing hyphen',
        IDNA_ERROR_HYPHEN_3_4             => 'consecutive hyphens',
        IDNA_ERROR_LEADING_COMBINING_MARK => 'leading combining mark',
        IDNA_ERROR_DISALLOWED             => 'disallowed',
        IDNA_ERROR_PUNYCODE               => 'invalid punycode encoding',
        IDNA_ERROR_LABEL_HAS_DOT          => 'has dot',
        IDNA_ERROR_INVALID_ACE_LABEL      => 'label not in ASCII encoding',
        IDNA_ERROR_BIDI                   => 'fails bidirectional criteria',
        IDNA_ERROR_CONTEXTJ               => 'one or more characters fail CONTEXTJ rule',
    ];

    /**
     * RFC 5322 specials that force a display name into a quoted-string.
     *
     * "." is left out: unquoted in a display name it is accepted obsolete
     * syntax, and quoting it would change the output for names such as
     * "John Q. Public".
     */
    private const string NAME_SPECIALS = '()<>[]:;@\\,"';

    /** @var null|AddressList */
    protected $addressList;

    /** @var string Normalized field name */
    protected $fieldName;

    /**
     * Header encoding
     *
     * @var string
     */
    protected $encoding = 'ASCII';

    /** @var string lower case field name */
    protected static $type;

    /** @var string[] lower case aliases for the field name */
    protected static $typeAliases = [];

    /**
     * @param string $headerLine
     * @return static
     */
    public static function fromString($headerLine)
    {
        [$fieldName, $fieldValue] = GenericHeader::splitHeaderLine($headerLine);
        if (strtolower($fieldName) !== static::$type && ! in_array(strtolower($fieldName), static::$typeAliases)) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid header line for "%s" string',
                self::class,
            ));
        }

        // split value on ","
        $fieldValue = self::flattenGroups(strtr((string) $fieldValue, [Headers::FOLDING => ' ']));
        $values     = ListParser::parse($fieldValue);

        $wasEncoded = false;
        $addresses  = array_map(
            static function ($value) use (&$wasEncoded): ?Address {
                $decodedValue = HeaderWrap::mimeDecodeValue($value);
                $wasEncoded   = $wasEncoded || $decodedValue !== $value;
                $value        = trim($decodedValue);
                $comments     = self::getComments($value);
                $value        = self::stripComments($value);
                $value        = preg_replace(
                    [
                        '#(?<!\\\)"(.*)(?<!\\\)"#', // quoted-text
                        '#\\\([\x01-\x09\x0b\x0c\x0e-\x7f])#', // quoted-pair
                    ],
                    [
                        '\\1',
                        '\\1',
                    ],
                    $value,
                );
                return empty($value) ? null : Address::fromString($value, $comments);
            },
            $values,
        );
        $addresses = array_filter($addresses);

        $header = new static();
        if ($wasEncoded) {
            $header->setEncoding('UTF-8');
        }

        /** @var AddressList $addressList */
        $addressList = $header->getAddressList();
        foreach ($addresses as $address) {
            $addressList->add($address);
        }

        return $header;
    }

    /**
     * Give the clone its own address list.
     */
    public function __clone(): void
    {
        if (null !== $this->addressList) {
            $this->addressList = clone $this->addressList;
        }
    }

    /**
     * @return string
     */
    public function getFieldName()
    {
        return $this->fieldName;
    }

    /**
     * Safely convert UTF-8 encoded domain name to ASCII
     *
     * @param string $domainName the UTF-8 encoded email
     */
    protected function idnToAscii($domainName): string
    {
        /** @psalm-var string|false $ascii */
        $ascii = idn_to_ascii($domainName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $conversionInfo);
        if (is_string($ascii)) {
            return $ascii;
        }

        $messages = [];
        assert(is_array($conversionInfo));
        /* @psalm-var array{errors: numeric-string} $conversionInfo */
        $errors = (int) $conversionInfo['errors'];

        foreach (self::IDNA_ERROR_MAP as $flag => $message) {
            if (($flag & $errors) !== $flag) {
                continue;
            }

            $messages[] = $message;
        }

        throw new RuntimeException(sprintf(
            'Failed encoding domain due to errors: %s',
            implode(', ', $messages),
        ));
    }

    /**
     * @inheritDoc
     */
    public function getFieldValue($format = HeaderInterface::FORMAT_RAW)
    {
        $emails   = [];
        $encoding = $this->getEncoding();

        foreach ($this->getAddressList() as $address) {
            $email = $address->getEmail();
            $name  = $address->getName();

            if (! empty($name) && strpbrk($name, self::NAME_SPECIALS) !== false) {
                $name = sprintf('"%s"', addcslashes($name, characters: '\\"'));
            }

            if (
                HeaderInterface::FORMAT_ENCODED === $format
                && 'ASCII' !== $encoding
            ) {
                if (! empty($name)) {
                    $name = HeaderWrap::mimeEncodeValue($name, $encoding);
                }

                if (preg_match('/^(.+)@([^@]+)$/', $email, $matches)) {
                    $localPart = $matches[1];
                    $hostname  = $this->idnToAscii($matches[2]);
                    $email     = sprintf('%s@%s', $localPart, $hostname);
                }
            }

            if (empty($name)) {
                $emails[] = $email;
            } else {
                $emails[] = sprintf('%s <%s>', $name, $email);
            }
        }

        // Ensure the values are valid before sending them.
        if (HeaderInterface::FORMAT_RAW !== $format) {
            foreach ($emails as $email) {
                HeaderValue::assertValid($email);
            }
        }

        return implode(',' . Headers::FOLDING, $emails);
    }

    /**
     * @param string $encoding
     * @return self
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
        return $this;
    }

    /**
     * @return string
     */
    public function getEncoding()
    {
        return $this->encoding;
    }

    /**
     * Set address list for this header
     */
    public function setAddressList(AddressList $addressList)
    {
        $this->addressList = $addressList;
    }

    /**
     * Get address list managed by this header
     *
     * @return AddressList
     */
    public function getAddressList()
    {
        if (null === $this->addressList) {
            $this->setAddressList(new AddressList());
        }
        return $this->addressList;
    }

    /**
     * @return string
     */
    public function toString()
    {
        $name  = $this->getFieldName();
        $value = $this->getFieldValue(HeaderInterface::FORMAT_ENCODED);
        return empty($value) ? '' : sprintf('%s: %s', $name, $value);
    }

    /**
     * Replace each RFC 5322 group ("name: a@b, c@d;") with its member list.
     *
     * Quoted strings are matched first and kept as they are, so a quoted
     * display name containing ":" or ";" is never read as group syntax.
     */
    private static function flattenGroups(string $value): string
    {
        $quoted = '"(?:\\\\.|[^"\\\\])*"';

        return (string) preg_replace_callback(
            "/{$quoted}|[^:\";,]+:(?<members>(?:{$quoted}|[^;\"])*);/",
            /** @param array<array-key, string> $matches */
            static function (array $matches): string {
                $members = $matches['members'] ?? null;

                return null === $members ? $matches[0] ?? '' : "{$members},";
            },
            $value,
        );
    }

    /**
     * Retrieve comments from value, if any.
     *
     * Supposed to be private, protected as a workaround for PHP bug 68194
     *
     * @param string $value
     * @return string
     */
    protected static function getComments($value)
    {
        $matches = [];
        preg_match_all(
            '/\\(
                (?P<comment>(
                    \\\\.|
                    [^\\\\)]
                )+)
            \\)/x',
            $value,
            $matches,
        );
        return isset($matches['comment']) ? implode(', ', $matches['comment']) : '';
    }

    /**
     * Strip all comments from value, if any.
     *
     * Supposed to be private, protected as a workaround for PHP bug 68194
     *
     * @param string $value
     * @return string
     */
    protected static function stripComments($value)
    {
        return preg_replace(
            '/\\(
                (
                    \\\\.|
                    [^\\\\)]
                )+
            \\)/x',
            '',
            $value,
        );
    }
}
