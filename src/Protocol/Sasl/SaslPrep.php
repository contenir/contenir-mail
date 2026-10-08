<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Normalizer;
use SensitiveParameter;

use function class_exists;
use function preg_match;

/**
 * The part of SASLprep (RFC 4013) that SCRAM needs to prepare a username or password.
 *
 * Printable ASCII passes unchanged, as SASLprep leaves it alone. Other text is
 * normalised to NFKC with intl's Normalizer, which also maps the non-ASCII spaces
 * to a plain space, and then must hold no control characters. Without the intl
 * extension, such text is refused rather than sent unprepared, because the server
 * prepares its copy and would derive a different key.
 *
 * The rest of SASLprep is not applied: removing the characters "commonly mapped to
 * nothing", the other prohibited characters and the bidirectional text checks. Text
 * that would be refused or changed by those steps is rare in a mailbox login.
 *
 * @internal
 */
final readonly class SaslPrep
{
    private bool $normalize;

    /**
     * @param bool|null $normalize Whether non-ASCII text can be normalised; by default, whether intl is installed.
     */
    public function __construct(?bool $normalize = null)
    {
        $this->normalize = $normalize ?? class_exists(Normalizer::class);
    }

    /**
     * @param string $what Named in the error message, such as "password".
     * @throws InvalidArgumentException When the text is not ASCII and cannot be normalised, is not
     *     UTF-8, or holds a control character.
     */
    public function prepare(#[SensitiveParameter] string $value, string $what): string
    {
        if (1 !== preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        if (! $this->normalize) {
            throw new InvalidArgumentException(
                "The SCRAM {$what} must be printable ASCII unless the intl extension is installed",
            );
        }

        $normalized = Normalizer::normalize($value, Normalizer::NFKC);
        if (false === $normalized || 1 === preg_match('/\p{Cc}/u', $normalized)) {
            throw new InvalidArgumentException(
                "The SCRAM {$what} must be UTF-8 text without control characters",
            );
        }

        return $normalized;
    }
}
