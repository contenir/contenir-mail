<?php

declare(strict_types=1);

namespace Contenir\Mail\Dkim;

use Contenir\Mail\Dkim\Exception\InvalidArgumentException;
use Contenir\Mail\Dkim\Exception\RuntimeException;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime;
use Contenir\Mail\SystemClock;
use Contenir\Mail\Transport;
use Contenir\Mail\Transport\HeaderGuard;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

use function array_key_exists;
use function array_reverse;
use function base64_encode;
use function explode;
use function hash;
use function implode;
use function preg_replace;
use function preg_split;
use function str_split;
use function strlen;
use function strstr;
use function strtolower;
use function substr;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Signs messages with DKIM (RFC 6376), with RSA-SHA256 or Ed25519-SHA256 (RFC 8463).
 *
 * ```php
 * $signer = new Signer(['domain' => 'example.com', 'selector' => 'mail2026', 'private_key_path' => '/etc/dkim/mail2026.pem']);
 * $transport->send($signer->sign($message));
 * ```
 *
 * sign() returns a new message that holds the headers and body exactly as
 * they were signed, with the DKIM-Signature header first. Send that message
 * and change nothing in it: any change to a signed header or to the body
 * breaks the signature.
 *
 * @mago-expect lint:cyclomatic-complexity Builds, folds and signs the tags of RFC 6376, section 3.5, each of them optional or derived.
 * @mago-expect lint:kan-defect Builds, folds and signs the tags of RFC 6376, section 3.5, each of them optional or derived.
 */
final readonly class Signer
{
    /** The longest line the signature header is folded to, where its tags allow */
    private const int LINE_LENGTH = 78;

    private DkimConfig $config;

    /**
     * @param DkimConfig|iterable<mixed, mixed> $config A config, or the settings DkimConfig::fromIterable() reads.
     * @param ClockInterface $clock The time written as t= and from which x= is counted.
     * @throws InvalidArgumentException When the settings are invalid.
     * @throws RuntimeException When the extension the key needs is not loaded.
     */
    public function __construct(
        #[SensitiveParameter]
        DkimConfig|iterable $config,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->config = $config instanceof DkimConfig ? $config : DkimConfig::fromIterable($config);
    }

    public function getConfig(): DkimConfig
    {
        return $this->config;
    }

    /**
     * A copy of the message, signed, with its DKIM-Signature header first.
     *
     * The copy's body is the text the transports would send, with every line
     * ending in CRLF, so it is sent exactly as it was signed; the message
     * given is left as it is.
     *
     * @throws InvalidArgumentException When the message has no From header.
     * @throws Transport\Exception\RuntimeException When a header contains a line break that is not folding.
     * @throws Mime\Exception\RuntimeException When the message body cannot be written.
     * @throws RuntimeException When the key cannot sign.
     */
    public function sign(Message $message): Message
    {
        $headers = HeaderGuard::check($message->getHeaders());
        $body    = (string) preg_replace('/\r\n|\r|\n/', replacement: Headers::EOL, subject: $message->getBodyText());
        $fields  = self::fields($headers->without('Bcc'));
        if (! array_key_exists('from', $fields)) {
            throw new InvalidArgumentException('A message without a From header cannot be signed with DKIM');
        }

        $canonical = '';
        $names     = [];
        foreach ($this->config->headers as $name) {
            foreach (array_reverse($fields[strtolower($name)] ?? []) as $field) {
                $canonical .= $this->config->headerCanonicalization->header($field);
                $names[]   = $name;
            }
        }

        $pieces    = $this->tags($this->config->bodyCanonicalization->body($body), $names);
        $prefix    = self::fold($pieces);
        $signature = base64_encode($this->config->privateKey->sign(
            $canonical
                . substr(
                    $this->config->headerCanonicalization->header(SignatureHeader::NAME . ': ' . $prefix),
                    offset: 0,
                    length: -2,
                ),
        ));
        foreach (str_split($signature) as $character) {
            $pieces[] = ['', $character];
        }

        $header = SignatureHeader::fromString(SignatureHeader::NAME . ': ' . self::fold($pieces));

        return (new Message($headers->withFirst($header)))->setBody($body);
    }

    /**
     * The tags of the signature up to an empty b=, as pieces to fold: the
     * white space allowed before each, and its text.
     *
     * @param list<string> $names The name of each header field signed, in the order signed.
     * @return list<array{string, string}>
     */
    private function tags(string $canonicalBody, array $names): array
    {
        $config = $this->config;
        $now    = $this->clock->now()->getTimestamp();
        $tags   = [
            'v' => '1',
            'a' => $config->algorithm->value,
            'c' => "{$config->headerCanonicalization->value}/{$config->bodyCanonicalization->value}",
            'd' => $config->domain,
            's' => $config->selector,
            'i' => $config->identity,
            't' => $config->includeTimestamp ? $now : null,
            'x' => null === $config->expiresAfter ? null : $now + $config->expiresAfter,
            'l' => $config->signBodyLength ? strlen($canonicalBody) : null,
        ];

        $pieces = [];
        foreach ($tags as $tag => $value) {
            if (null === $value) {
                continue;
            }

            $pieces[] = [' ', "{$tag}={$value};"];
        }

        foreach (explode("\0", 'h=' . implode(":\0", $names) . ';') as $index => $text) {
            $pieces[] = [0 === $index ? ' ' : '', $text];
        }

        return [
            ...$pieces,
            [' ', 'bh=' . base64_encode(hash('sha256', $canonicalBody, binary: true)) . ';'],
            [' ', 'b='],
        ];
    }

    /**
     * Join the pieces, starting a continuation line before any piece that would take its line past 78 characters.
     *
     * The first line starts after the name and its colon; the space after the colon is the first piece's.
     *
     * @param list<array{string, string}> $pieces
     */
    private static function fold(array $pieces): string
    {
        $value  = '';
        $column = strlen(SignatureHeader::NAME) + 1;
        foreach ($pieces as [$space, $text]) {
            $width = strlen($space) + strlen($text);
            if (($column + $width) > self::LINE_LENGTH) {
                $value  .= Headers::FOLDING . $text;
                $column = 1 + strlen($text);
                continue;
            }

            $value  .= $space . $text;
            $column += $width;
        }

        return substr($value, offset: 1);
    }

    /**
     * Each header field as it is written, folded, by lower-case name, in order.
     *
     * @return array<string, list<string>>
     */
    private static function fields(Headers $headers): array
    {
        $fields = [];
        foreach ((array) preg_split('/\r\n(?![ \t])/', $headers->toString(), flags: PREG_SPLIT_NO_EMPTY) as $field) {
            $fields[strtolower((string) strstr((string) $field, needle: ':', before_needle: true))][] = (string) $field;
        }

        return $fields;
    }
}
