<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function in_array;
use function preg_match;
use function strtolower;
use function trim;

final readonly class MimeVersion implements HeaderInterface
{
    private string $version;

    /**
     * @throws Exception\InvalidArgumentException When the version is not "major.minor".
     */
    public function __construct(string $version = '1.0')
    {
        if (1 !== preg_match('/^[1-9]\d*\.\d+$/', $version)) {
            throw new Exception\InvalidArgumentException('Invalid MIME-Version value detected');
        }

        $this->version = $version;
    }

    /**
     * An unreadable version falls back to 1.0, the only version ever defined.
     */
    #[Override]
    public static function fromString(string $headerLine): static
    {
        [$name, $value] = GenericHeader::splitHeaderLine($headerLine);
        if (! in_array(strtolower($name), ['mimeversion', 'mime_version', 'mime-version'], strict: true)) {
            throw new Exception\InvalidArgumentException('Invalid header line for MIME-Version string');
        }

        $value = trim(HeaderWrap::mimeDecodeValue($value));

        return new self(1 === preg_match('/^[1-9]\d*\.\d+$/', $value) ? $value : '1.0');
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    #[Override]
    public function getFieldName(): string
    {
        return 'MIME-Version';
    }

    #[Override]
    public function getFieldValue(): string
    {
        return $this->version;
    }

    #[Override]
    public function getEncodedFieldValue(): string
    {
        return $this->version;
    }

    #[Override]
    public function toString(): string
    {
        return "MIME-Version: {$this->version}";
    }
}
