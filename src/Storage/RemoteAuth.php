<?php

declare(strict_types=1);

namespace Contenir\Mail\Storage;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Sasl\MechanismInterface;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorFactory;
use SensitiveParameter;

use function get_debug_type;
use function is_string;
use function sprintf;

/**
 * Reads the "auth" setting of an IMAP or POP3 mailbox: a SASL mechanism, or the settings of
 * a built-in one, such as `['type' => 'scram-sha-256', 'username' => 'jo', 'password' => '…']`.
 *
 * Types are named as for SMTP (AuthenticatorFactory::TYPES), but only "xoauth2" and
 * "scram-sha-256" are read here, and "type" defaults to "xoauth2", as settings without one
 * were XOAUTH2 settings before SCRAM was added. Any other mechanism is given as an object.
 *
 * @internal
 *
 * @mago-expect analysis:mixed-assignment Settings arrive untyped; each mechanism reads them into types.
 */
final readonly class RemoteAuth
{
    /**
     * @throws InvalidArgumentException When the setting is neither a SASL mechanism nor valid
     *     settings for a built-in one.
     */
    public static function fromReader(ConfigReader $reader): ?MechanismInterface
    {
        return $reader->section('auth', MechanismInterface::class, self::fromIterable(...));
    }

    /**
     * The username a built-in mechanism signs in as; "" for any other mechanism.
     */
    public static function username(MechanismInterface $auth): string
    {
        return $auth instanceof Xoauth2 || $auth instanceof ScramSha256 ? $auth->username : '';
    }

    /**
     * @param iterable<mixed, mixed> $config The optional "type", "xoauth2" by default, or "scram-sha-256",
     *     and the mechanism's own keys.
     * @throws InvalidArgumentException When the type is unknown or the other settings are invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): MechanismInterface
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

        return match (AuthenticatorFactory::type($type, 'Mailbox authentication')) {
            'xoauth2'       => Xoauth2::fromIterable($settings),
            'scram-sha-256' => ScramSha256::fromIterable($settings),
            default         => throw new InvalidArgumentException(sprintf(
                'Mailbox authentication: unknown type "%s"; expected xoauth2 or scram-sha-256',
                $type,
            )),
        };
    }
}
