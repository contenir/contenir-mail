<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Pop3\Xoauth2;

use Contenir\Mail\Protocol\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Pop3;
use Contenir\Mail\Protocol\Xoauth2\Xoauth2;
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
     * @param string $password OAUTH2 accessToken
     * @param bool $tryApop obsolete parameter not used here
     * @throws ExceptionInterface
     */
    #[Override]
    public function login(string $user, #[SensitiveParameter] string $password, bool $tryApop = true): void
    {
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $user) || 1 === preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new InvalidArgumentException(
                'XOAUTH2 user names and tokens cannot contain control characters; they would change the SASL fields',
            );
        }

        $this->sendRequest(self::AUTH_INITIALIZE_REQUEST);

        $response = $this->readRemoteResponse();

        if (self::AUTH_RESPONSE_INITIALIZED_OK !== $response->status()) {
            throw new RuntimeException($response->message());
        }

        $this->request(Xoauth2::encodeXoauth2Sasl($user, $password));
    }
}
