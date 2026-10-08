<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\TestAsset;

use function dirname;

/**
 * Keys for DKIM tests only: the published keys of RFC 8463, Appendix A, and
 * the test-only keys in tests/Unit/_files/dkim. None of them signs real mail.
 */
final class DkimKeys
{
    /** The Ed25519 seed of RFC 8463, Appendix A.1 (RFC 8032, section 7.1, test 1) */
    public const string RFC8463_ED25519_SEED = 'nWGxne/9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A=';

    /** The key record for the seed above, from RFC 8463, Appendix A.2 */
    public const string RFC8463_ED25519_RECORD = 'v=DKIM1; k=ed25519; p=11qYAYKxCrfVS/7TyWQHOg7hcvPapiMlrwIaaPcHURo=';

    /** The 1024-bit RSA key of RFC 8463, Appendix A.1 */
    public const string RFC8463_RSA_PEM = <<<'PEM'
        -----BEGIN RSA PRIVATE KEY-----
        MIICXQIBAAKBgQDkHlOQoBTzWRiGs5V6NpP3idY6Wk08a5qhdR6wy5bdOKb2jLQi
        Y/J16JYi0Qvx/byYzCNb3W91y3FutACDfzwQ/BC/e/8uBsCR+yz1Lxj+PL6lHvqM
        KrM3rG4hstT5QjvHO9PzoxZyVYLzBfO2EeC3Ip3G+2kryOTIKT+l/K4w3QIDAQAB
        AoGAH0cxOhFZDgzXWhDhnAJDw5s4roOXN4OhjiXa8W7Y3rhX3FJqmJSPuC8N9vQm
        6SVbaLAE4SG5mLMueHlh4KXffEpuLEiNp9Ss3O4YfLiQpbRqE7Tm5SxKjvvQoZZe
        zHorimOaChRL2it47iuWxzxSiRMv4c+j70GiWdxXnxe4UoECQQDzJB/0U58W7RZy
        6enGVj2kWF732CoWFZWzi1FicudrBFoy63QwcowpoCazKtvZGMNlPWnC7x/6o8Gc
        uSe0ga2xAkEA8C7PipPm1/1fTRQvj1o/dDmZp243044ZNyxjg+/OPN0oWCbXIGxy
        WvmZbXriOWoSALJTjExEgraHEgnXssuk7QJBALl5ICsYMu6hMxO73gnfNayNgPxd
        WFV6Z7ULnKyV7HSVYF0hgYOHjeYe9gaMtiJYoo0zGN+L3AAtNP9huqkWlzECQE1a
        licIeVlo1e+qJ6Mgqr0Q7Aa7falZ448ccbSFYEPD6oFxiOl9Y9se9iYHZKKfIcst
        o7DUw1/hz2Ck4N5JrgUCQQCyKveNvjzkkd8HjYs0SwM0fPjK16//5qDZ2UiDGnOe
        uEzxBDAr518Z8VFbR41in3W4Y3yCDgQlLlcETrS+zYcL
        -----END RSA PRIVATE KEY-----
        PEM;

    /** The key record for the RSA key above, from RFC 8463, Appendix A.2 */
    public const string RFC8463_RSA_RECORD =
        'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQDkHlOQoBTzWR'
            . 'iGs5V6NpP3idY6Wk08a5qhdR6wy5bdOKb2jLQiY/J16JYi0Qvx/byYzCNb3W91y3FutAC'
            . 'DfzwQ/BC/e/8uBsCR+yz1Lxj+PL6lHvqMKrM3rG4hstT5QjvHO9PzoxZyVYLzBfO2EeC3'
            . 'Ip3G+2kryOTIKT+l/K4w3QIDAQAB';

    /** The key record for test-only-rsa-2048.pem */
    public const string TEST_RSA_RECORD =
        'v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAqAeOjLWxSK/iyuJr'
            . 'idOZTzAjWb4CTT/nDxMOL8Fkmt8q9FhgwFsfIb4MZVpHo7xoN7RrF/O07cHlgOLCU+g9rWOKMOvAzWwnXfuq14qjRWGVVdYpLtHehdaQ'
            . 'xika5EW+DvjmSuEK7L2W59okaWBWHn9yFDDlSGnKDkx5mITTBSA/doNrTqKCoZ1JJZqPtzVeY9o0JZLU+zC7eLgZJran2CWlX8FeXpYrt'
            . 'BFf3C5TV3gYSdKmLNzt9Ve/zpyBQSRDy8l0lTeqC4QtAUwVjGmdyvFihTyCcOV5zeWbiG537wDcywlJkKZ6OEcJyC96vB2f3pV1bE/iBqIT'
            . 'nnl1lPaZAQIDAQAB';

    /** The key record for test-only-ed25519.pem and test-only-ed25519.key, which hold the same key */
    public const string TEST_ED25519_RECORD = 'v=DKIM1; k=ed25519; p=oICp5380gDFVCsRO1S0ugPQ7LhixGiekOv5YqLmTxiM=';

    /** The passphrase of test-only-rsa-2048-encrypted.pem */
    public const string TEST_PASSPHRASE = 'test-only-passphrase';

    /**
     * The path of a test-only key file in tests/Unit/_files/dkim.
     */
    public static function path(string $name): string
    {
        return dirname(__DIR__) . '/_files/dkim/' . $name;
    }
}
