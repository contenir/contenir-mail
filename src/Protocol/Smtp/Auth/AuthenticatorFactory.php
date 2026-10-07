<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\Exception\InvalidArgumentException;
use SensitiveParameter;

use function get_debug_type;
use function implode;
use function is_string;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * Builds a built-in authenticator from settings such as
 * `['type' => 'login', 'username' => 'orders', 'password' => '…']`.
 *
 * @mago-expect analysis:mixed-assignment Settings arrive untyped; each authenticator reads them into types.
 */
final readonly class AuthenticatorFactory
{
    /** The accepted "type" values; "-" and "_" are ignored, so "cram-md5" also works */
    public const array TYPES = ['plain', 'login', 'crammd5', 'xoauth2'];

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

        return match (str_replace(
            search: ['-', '_'],
            replace: '',
            subject: strtolower($type),
        )) {
            'plain'   => Plain::fromIterable($settings),
            'login'   => Login::fromIterable($settings),
            'crammd5' => CramMd5::fromIterable($settings),
            'xoauth2' => XOAuth2::fromIterable($settings),
            default   => throw new InvalidArgumentException(sprintf(
                'SMTP authentication: unknown type "%s"; expected one of %s',
                $type,
                implode(', ', self::TYPES),
            )),
        };
    }
}
