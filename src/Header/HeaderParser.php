<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Exception\RuntimeException;
use Contenir\Mail\Utf8;

use function is_array;
use function is_int;

/**
 * Turns header text into header objects, using the class a locator names
 * for each header and falling back to GenericHeader when that class rejects it,
 * such as an address header whose address is invalid.
 *
 * @internal Used by Contenir\Mail\Headers.
 */
final readonly class HeaderParser
{
    public function __construct(
        private HeaderLocatorInterface $locator = new HeaderLocator(),
    ) {}

    /**
     * Parse a header block into headers, each with its text as written, or
     * null where that text cannot be written back as it is.
     *
     * A header that is not UTF-8 holds raw bytes in some legacy charset, which
     * RFC 5322 does not allow but many mailers still send. It is read as
     * Windows-1252, as mail clients do, so one such header does not make the
     * whole message unreadable.
     *
     * @return list<array{HeaderInterface, string|null}>
     * @throws RuntimeException When the block is not a sequence of header lines, or a name is longer than HeaderName::MAX_LENGTH.
     */
    public function parseBlock(string $block, string $eol): array
    {
        $headers = [];
        foreach (HeaderBlock::fields($block, $eol) as [$line, $wireText]) {
            if (! Utf8::isValid($line)) {
                $line = self::fromLegacyCharset($line);
            }

            $headers[] = [$this->parseLine($line), $wireText];
        }

        return $headers;
    }

    /**
     * Build headers from header objects, lines and name-value pairs. A name
     * may be at most HeaderName::MAX_LENGTH long, as GenericHeader, which
     * holds any header its class rejects, allows no longer name.
     *
     * @param iterable<int|string, HeaderInterface|string|array{string, string}> $headers
     * @return list<HeaderInterface>
     * @throws Exception\InvalidArgumentException When a line is malformed or a name is too long.
     */
    public function parseIterable(iterable $headers): array
    {
        $parsed = [];
        foreach ($headers as $name => $value) {
            if ($value instanceof HeaderInterface) {
                $parsed[] = $value;
                continue;
            }

            $line = match (true) {
                is_array($value) => "{$value[0]}: {$value[1]}",
                is_int($name)    => $value,
                default          => "{$name}: {$value}",
            };
            $parsed[] = $this->parseLine($line);
        }

        return $parsed;
    }

    /**
     * The line read as Windows-1252, or with its invalid bytes replaced where
     * it uses one of the five bytes Windows-1252 leaves undefined.
     */
    private static function fromLegacyCharset(string $line): string
    {
        $converted = EncodedWordDecoder::toUtf8($line, charset: 'WINDOWS-1252');

        return Utf8::isValid($converted) ? $converted : Utf8::scrub($line);
    }

    private function parseLine(string $line): HeaderInterface
    {
        [$name] = GenericHeader::splitHeaderLine($line);
        $class = $this->locator->get($name);
        if (null === $class) {
            return GenericHeader::fromString($line);
        }

        try {
            /** @mago-expect analysis:possibly-static-access-on-interface The locator maps names to concrete header classes. */
            return $class::fromString($line);
        } catch (ExceptionInterface) {
            return GenericHeader::fromString($line);
        }
    }
}
