<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use Contenir\Mail\Protocol\Sasl\ScramSha256 as Exchange;
use Contenir\Mail\Protocol\Smtp\Auth\ScramSha256;

use function base64_decode;
use function base64_encode;

/**
 * The SCRAM-SHA-256 test vector of RFC 7677, section 3, and builders for exchanges that replay it.
 */
final class ScramVector
{
    public const string USER = 'user';

    /**
     * @mago-expect lint:no-literal-password The password published in RFC 7677's test vector.
     */
    public const string PASSWORD = 'pencil';

    public const string CLIENT_NONCE = 'rOprNGfwEbeRWgbNEkqO';

    public const string SERVER_NONCE = self::CLIENT_NONCE . '%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0';

    public const string SALT = 'W22ZaJ0SNY7soEsUEjb6gQ==';

    public const string CLIENT_FIRST = 'n,,n=user,r=' . self::CLIENT_NONCE;

    public const string SERVER_FIRST = 'r=' . self::SERVER_NONCE . ',s=' . self::SALT . ',i=4096';

    public const string CLIENT_FINAL =
        'c=biws,r='
            . self::SERVER_NONCE
            . ',p=dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ=';

    public const string SERVER_FINAL = 'v=6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=';

    /**
     * An exchange with the vector's credentials and client nonce.
     */
    public static function exchange(): Exchange
    {
        return new Exchange(self::USER, self::PASSWORD, self::CLIENT_NONCE);
    }

    /**
     * An authenticator with the vector's credentials, whose exchanges use the vector's client nonce.
     */
    public static function authenticator(): ScramSha256
    {
        return new ScramSha256(self::USER, self::PASSWORD, static fn(): string => self::CLIENT_NONCE);
    }

    /**
     * A server-first message for the vector's nonce and salt, with another iteration count.
     */
    public static function serverFirst(string $iterations): string
    {
        return 'r=' . self::SERVER_NONCE . ',s=' . self::SALT . ",i={$iterations}";
    }

    public static function b64(string $message): string
    {
        return base64_encode($message);
    }

    /**
     * The message a base64 line carries, or "" when it is not base64.
     */
    public static function decode(string $line): string
    {
        return (string) base64_decode($line, strict: true);
    }
}
