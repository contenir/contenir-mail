<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Sasl\Xoauth2 as SaslXoauth2;
use Deprecated;
use Override;
use SensitiveParameter;

/**
 * XOAUTH2 under its 0.2 name, kept so code written for it still works: it is a
 * Protocol\Sasl\Xoauth2, which every method that took this class now takes.
 *
 * Building one is deprecated, as PHP 8.4 and later report; the settings and Closures
 * it takes are unchanged. Objects built from settings, as SmtpConfig, ImapConfig and
 * Pop3Config build them, are Protocol\Sasl\Xoauth2 and not this class, so test for that.
 *
 * @deprecated 0.3.0 Use Protocol\Sasl\Xoauth2, which signs in to IMAP, POP3 and SMTP alike.
 * @api
 */
final readonly class XOAuth2 extends SaslXoauth2
{
    /**
     * @throws InvalidArgumentException When the username or token is empty or contains a control character.
     */
    #[Deprecated('use Contenir\Mail\Protocol\Sasl\Xoauth2', since: '0.3.0')]
    public function __construct(string $username, #[SensitiveParameter] string|Closure $accessToken)
    {
        parent::__construct($username, $accessToken);
    }

    /**
     * @param iterable<mixed, mixed> $config Keys "username" and "access_token".
     * @throws \Contenir\Mail\Exception\InvalidArgumentException When a key is unknown or a value is invalid.
     *
     * @mago-expect analysis:deprecated-class,deprecated-method What it builds is deprecated, as the class is.
     */
    #[Override]
    public static function fromIterable(#[SensitiveParameter] iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self($reader->string('username', ''), $reader->stringOrCallable('access_token') ?? '');
    }
}
