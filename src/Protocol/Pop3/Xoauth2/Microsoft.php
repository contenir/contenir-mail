<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Pop3\Xoauth2;

use Contenir\Mail\Protocol\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Override;
use SensitiveParameter;

use function preg_match;

/**
 * POP3 with XOAUTH2 authentication, as Microsoft 365 offers it.
 *
 * @final
 * @api
 */
class Microsoft extends Pop3
{
    protected const string AUTH_INITIALIZE_REQUEST      = 'AUTH XOAUTH2';
    protected const string AUTH_RESPONSE_INITIALIZED_OK = '+';

    /**
     * @param string $user the target mailbox to access
     * @param string $password OAUTH2 accessToken, as for authenticate()
     * @param bool $tryApop obsolete parameter not used here
     * @throws ExceptionInterface When the mechanism or the token is refused. A refused token is answered
     *     with the empty response that ends the exchange (RFC 7628, section 3.2.3) before this is thrown.
     */
    #[Override]
    public function login(string $user, #[SensitiveParameter] string $password, bool $tryApop = true): void
    {
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $user) || 1 === preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new InvalidArgumentException(
                'XOAUTH2 user names and tokens cannot contain control characters; they would change the SASL fields',
            );
        }

        $this->authenticate(new XOAuth2($user, $password));
    }
}
