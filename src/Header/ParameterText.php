<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use function count;
use function explode;
use function preg_replace;
use function rawurldecode;
use function sprintf;
use function str_replace;
use function strcspn;
use function strlen;
use function strtoupper;
use function substr;

/**
 * Parameter values as MimeParameterParser reads them: quoted strings, tokens, and the cleaning of decoded text.
 *
 * @internal Used by MimeParameterParser.
 */
final class ParameterText
{
    /** Characters that end an unquoted value */
    private const string TOKEN_END = "; \t";

    /**
     * The text of a quoted string starting at $offset, just after its opening quote, with quoted pairs resolved.
     *
     * @return array{string, int} the text and the offset after the closing quote, or the end
     */
    public static function unquote(string $value, int $offset): array
    {
        $text   = '';
        $length = strlen($value);
        while ($offset < $length) {
            $character = $value[$offset];
            ++$offset;
            if ('"' === $character) {
                break;
            }

            if ('\\' === $character && $offset < $length) {
                $character = $value[$offset];
                ++$offset;
            }

            $text .= $character;
        }

        return [$text, $offset];
    }

    /**
     * @return array{string, int} the token and the offset after it
     */
    public static function token(string $value, int $offset): array
    {
        $length = strcspn($value, characters: self::TOKEN_END, offset: $offset);

        return [substr($value, $offset, $length), $offset + $length];
    }

    /**
     * Valid UTF-8 without control characters; a tab becomes a space.
     */
    public static function clean(string $value): string
    {
        return (string) preg_replace(
            '/[\x00-\x08\x0A-\x1F\x7F]/',
            replacement: '',
            subject: str_replace(
                search: "\t",
                replace: ' ',
                subject: EncodedWordDecoder::scrub($value),
            ),
        );
    }

    /**
     * Join the sections of a continued or extended parameter (RFC 2231, sections 3 and 4).
     *
     * @param array<int, array{string, bool}> $parts
     * @throws Exception\InvalidArgumentException When a section is missing or there are too many.
     */
    public static function join(array $parts, string $headerLine, string $fieldName): string
    {
        $count = count($parts);
        if ($count > MimeParameterParser::MAX_SECTIONS) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Invalid header line for %s string - more than %d continuation sections',
                $fieldName,
                MimeParameterParser::MAX_SECTIONS,
            ));
        }

        $charset = null;
        $bytes   = '';
        for ($i = 0; $i < $count; ++$i) {
            [$text, $extended] = $parts[$i] ?? throw new Exception\InvalidArgumentException(
                "Invalid header line for {$fieldName} string - incomplete continuation; HeaderLine: {$headerLine}",
            );
            $fields = explode("'", $text, limit: 3);
            $value  = $fields[2] ?? null;
            if ($extended && 0 === $i && null !== $value) {
                $charset = strtoupper($fields[0]);
                $text    = $value;
            }

            $bytes .= $extended ? rawurldecode($text) : $text;
        }

        return self::clean(match ($charset) {
            null    => EncodedWordDecoder::decode($bytes),
            ''      => $bytes,
            default => EncodedWordDecoder::toUtf8($bytes, $charset),
        });
    }
}
