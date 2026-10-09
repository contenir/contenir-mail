<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\TestAsset;

use PHPUnit\Framework\Assert;

use function getenv;

/**
 * The servers that tests/Integration/servers/start.sh runs, with the ports
 * and login from its compose.yml.
 */
final class Servers
{
    public const string HOST = 'localhost';

    /** The address the certificates do not name */
    public const string IP_ADDRESS = '127.0.0.1';

    public const string USER = 'test';

    /** @mago-expect lint:no-literal-password The throwaway servers' fixed password, set in start.sh. */
    public const string PASSWORD = 'secret';

    /**
     * The one access token the introspection endpoint calls active, issued to USER.
     *
     * @mago-expect lint:no-literal-password The stub endpoint's fixed token, set in servers/oauth/introspect.py.
     */
    public const string ACCESS_TOKEN = 'valid-token-for-test';

    public const int IMAP = 143;

    public const int IMAPS = 993;

    public const int POP3 = 110;

    public const int POP3S = 995;

    /** Dovecot with no password before TLS, and access tokens checked by introspection */
    public const int STRICT_IMAP = 1143;

    public const int STRICT_POP3 = 1110;

    /** Postfix submission, checking logins against the strict Dovecot */
    public const int STRICT_SUBMISSION = 2587;

    /** Postfix submission, checking logins against the first Dovecot */
    public const int SUBMISSION = 587;

    /** The largest message Postfix accepts, as its SIZE keyword says */
    public const int SUBMISSION_SIZE_LIMIT = 1_048_576;

    /** Dovecot with a certificate from the trusted CA that has expired */
    public const int EXPIRED_IMAP = 2143;

    public const int EXPIRED_IMAPS = 2993;

    /** Dovecot with a certificate that no CA signed */
    public const int SELF_SIGNED_IMAP = 4143;

    public const int SELF_SIGNED_IMAPS = 4993;

    public const int GREENMAIL_SMTPS = 3465;

    public const int GREENMAIL_IMAPS = 3993;

    public const int GREENMAIL_POP3S = 3995;

    public static function skipUnlessRunning(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_SERVERS_ENABLED')) {
            Assert::markTestSkipped('TESTS_CONTENIR_MAIL_SERVERS_ENABLED is not set; see tests/Integration/README.md');
        }
    }
}
