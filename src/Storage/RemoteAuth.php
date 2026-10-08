<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use SensitiveParameter;

use function get_debug_type;
use function is_string;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * Reads the "auth" setting of an IMAP or POP3 mailbox: an XOAuth2 or ScramSha256 authenticator,
 * or its settings, such as `['type' => 'scram-sha-256', 'username' => 'jo', 'password' => '…']`.
 *
 * Settings without a "type" are XOAUTH2 ones, as before SCRAM was added.
 *
 * @internal
 *
 * @mago-expect analysis:mixed-assignment Settings arrive untyped; each authenticator reads them into types.
 */
final readonly class RemoteAuth
{
    /**
     * @param string $context The Config class, named in error messages.
     * @throws InvalidArgumentException When the setting is neither an authenticator these protocols
     *     support nor valid settings for one.
     */
    public static function fromReader(ConfigReader $reader, string $context): XOAuth2|ScramSha256|null
    {
        $auth = $reader->section('auth', AuthenticatorInterface::class, self::fromIterable(...));
        if (null === $auth || $auth instanceof XOAuth2 || $auth instanceof ScramSha256) {
            return $auth;
        }

        throw new InvalidArgumentException(sprintf(
            '%s: option "auth" must be an XOAuth2 or ScramSha256 authenticator, got %s',
            $context,
            $auth::class,
        ));
    }

    /**
     * @param iterable<mixed, mixed> $config The optional "type", "xoauth2" by default, or "scram-sha-256"
     *     ("-" and "_" are ignored), and the authenticator's own keys.
     * @throws InvalidArgumentException When the type is unknown or the other settings are invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): XOAuth2|ScramSha256
    {
        $settings = [];
        $type     = 'xoauth2';
        foreach ($config as $key => $value) {
            if ('type' === $key) {
                $type = $value;
                continue;
            }

            $settings[$key] = $value;
        }

        if (! is_string($type)) {
            throw new InvalidArgumentException(sprintf(
                'Mailbox authentication: option "type" must be a string, got %s',
                get_debug_type($type),
            ));
        }

        return match (str_replace(
            search: ['-', '_'],
            replace: '',
            subject: strtolower($type),
        )) {
            'xoauth2'     => XOAuth2::fromIterable($settings),
            'scramsha256' => ScramSha256::fromIterable($settings),
            default       => throw new InvalidArgumentException(sprintf(
                'Mailbox authentication: unknown type "%s"; expected xoauth2 or scram-sha-256',
                $type,
            )),
        };
    }
}
