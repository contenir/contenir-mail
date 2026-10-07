<?php

declare(strict_types=1);

namespace Contenir\Mail\Header;

use Override;

use function array_key_exists;
use function str_replace;
use function strtolower;

/**
 * The built-in header classes, with any custom classes layered on top.
 */
final readonly class HeaderLocator implements HeaderLocatorInterface
{
    private const array DEFAULTS = [
        'bcc'                     => Bcc::class,
        'cc'                      => Cc::class,
        'contentdisposition'      => ContentDisposition::class,
        'contenttransferencoding' => ContentTransferEncoding::class,
        'contenttype'             => ContentType::class,
        'date'                    => Date::class,
        'from'                    => From::class,
        'inreplyto'               => InReplyTo::class,
        'messageid'               => MessageId::class,
        'mimeversion'             => MimeVersion::class,
        'received'                => Received::class,
        'references'              => References::class,
        'replyto'                 => ReplyTo::class,
        'sender'                  => Sender::class,
        'subject'                 => Subject::class,
        'to'                      => To::class,
    ];

    /** @var array<string, class-string<HeaderInterface>> */
    private array $classes;

    /**
     * @param array<string, class-string<HeaderInterface>> $classes header name => class, overriding the defaults
     */
    public function __construct(array $classes = [])
    {
        $normalised = self::DEFAULTS;
        foreach ($classes as $name => $class) {
            $normalised[self::normalise($name)] = $class;
        }

        $this->classes = $normalised;
    }

    /**
     * @param class-string<HeaderInterface> $class
     */
    public function with(string $name, string $class): self
    {
        return new self([...$this->classes, self::normalise($name) => $class]);
    }

    #[Override]
    public function get(string $name): ?string
    {
        return $this->classes[self::normalise($name)] ?? null;
    }

    #[Override]
    public function has(string $name): bool
    {
        return array_key_exists(self::normalise($name), $this->classes);
    }

    /**
     * "Content-Type", "content_type" and "contenttype" all name the same header.
     */
    private static function normalise(string $name): string
    {
        return str_replace(
            search: ['-', '_', ' ', '.'],
            replace: '',
            subject: strtolower($name),
        );
    }
}
