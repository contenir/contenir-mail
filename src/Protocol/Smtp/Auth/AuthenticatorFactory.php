<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Sasl\Xoauth2;
use SensitiveParameter;

use function get_debug_type;
use function implode;
use function in_array;
use function is_string;
use function sprintf;
use function str_replace;
use function strtolower;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * Builds a built-in authenticator from settings such as
 * `['type' => 'login', 'username' => 'orders', 'password' => '…']`.
 *
 * @mago-expect analysis:mixed-assignment Settings arrive untyped; each authenticator reads them into types.
 */
final readonly class AuthenticatorFactory
{
    /** The accepted "type" values: the mechanisms' names as IANA registers them, in any case */
    public const array TYPES = ['plain', 'login', 'cram-md5', 'xoauth2', 'scram-sha-256'];

    /**
     * @param iterable<mixed, mixed> $config The "type" key and the chosen authenticator's own keys.
     * @throws InvalidArgumentException When the type is missing or unknown, or the other settings are invalid.
     */
    public static function fromIterable(#[SensitiveParameter] iterable $config): AuthenticatorInterface
    {
        $settings = [];
        $type     = null;
        foreach ($config as $key => $value) {
            if ('type' === $key) {
                $type = $value;
                continue;
            }

            $settings[$key] = $value;
        }

        if (! is_string($type)) {
            throw new InvalidArgumentException(sprintf(
                'SMTP authentication: option "type" must be one of %s, got %s',
                implode(', ', self::TYPES),
                get_debug_type($type),
            ));
        }

        return match (self::type($type, 'SMTP authentication')) {
            'plain'         => Plain::fromIterable($settings),
            'login'         => Login::fromIterable($settings),
            'cram-md5'      => CramMd5::fromIterable($settings),
            'xoauth2'       => Xoauth2::fromIterable($settings),
            'scram-sha-256' => ScramSha256::fromIterable($settings),
            default         => throw new InvalidArgumentException(sprintf(
                'SMTP authentication: unknown type "%s"; expected one of %s',
                $type,
                implode(', ', self::TYPES),
            )),
        };
    }

    /**
     * The type in TYPES that $type names, in any case; "" when it names none.
     *
     * A spelling without the "-" of the IANA name, such as "crammd5", or with "_" in its
     * place, still names it, with a deprecation notice.
     *
     * @internal Storage\RemoteAuth reads the "type" of a mailbox's "auth" setting with it too.
     */
    public static function type(string $type, string $context): string
    {
        $lower = strtolower($type);
        if (in_array($lower, self::TYPES, strict: true)) {
            return $lower;
        }

        foreach (self::TYPES as $name) {
            if (self::squash($name) !== self::squash($lower)) {
                continue;
            }

            trigger_error(sprintf('%s: type "%s" is deprecated; use "%s"', $context, $type, $name), E_USER_DEPRECATED);

            return $name;
        }

        return '';
    }

    private static function squash(string $type): string
    {
        return str_replace(
            search: ['-', '_'],
            replace: '',
            subject: $type,
        );
    }
}
