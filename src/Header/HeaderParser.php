<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Exception\RuntimeException;

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
     * @return list<array{HeaderInterface, string|null}>
     * @throws RuntimeException When the block is not a sequence of header lines.
     */
    public function parseBlock(string $block, string $eol): array
    {
        $headers = [];
        foreach (HeaderBlock::fields($block, $eol) as [$line, $wireText]) {
            $headers[] = [$this->parseLine($line), $wireText];
        }

        return $headers;
    }

    /**
     * @param iterable<int|string, HeaderInterface|string|array{string, string}> $headers
     * @return list<HeaderInterface>
     */
    public function parseIterable(iterable $headers): array
    {
        $parsed = [];
        foreach ($headers as $name => $value) {
            $parsed[] = match (true) {
                $value instanceof HeaderInterface => $value,
                is_array($value) => $this->parseLine("{$value[0]}: {$value[1]}"),
                is_int($name)    => $this->parseLine($value),
                default          => $this->parseLine("{$name}: {$value}"),
            };
        }

        return $parsed;
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
