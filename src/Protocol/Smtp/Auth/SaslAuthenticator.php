<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Smtp\Auth;

use Contenir\Mail\Protocol\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\Authentication;
use Contenir\Mail\Protocol\Sasl\MechanismInterface;
use Contenir\Mail\Protocol\Sasl\Reply;
use Override;
use SensitiveParameter;

/**
 * Runs a SASL mechanism over SMTP AUTH (RFC 4954), so a mechanism written for IMAP and
 * POP3 sends mail too.
 *
 * ```php
 * new SmtpConfig(host: 'smtp.example.com', auth: new SaslAuthenticator($mechanism));
 * ```
 *
 * The initial response follows the server's first 334, as for the other mechanisms. Each
 * response is sent with exchangeSecret(), so it stays out of the session log; a 334 reply
 * is a challenge, 235 is success, and any other code a refusal, thrown with that code.
 * The built-in Protocol\Sasl mechanisms are authenticators already.
 *
 * @api
 */
final readonly class SaslAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        private MechanismInterface $mechanism,
    ) {}

    #[Override]
    public function mechanism(): string
    {
        return $this->mechanism->mechanism();
    }

    /**
     * @throws ExceptionInterface When the server refuses the mechanism or the credentials, a challenge
     *     cannot be answered, or the mechanism cannot trust the server's acceptance.
     *
     * @mago-expect analysis:unhandled-thrown-type The Closures throw what this method declares, from where it runs them.
     */
    #[Override]
    public function authenticate(ChannelInterface $channel): void
    {
        $exchange = $this->mechanism->start();
        $command  = "AUTH {$this->mechanism->mechanism()}";

        (new Authentication(
            start: static fn(#[SensitiveParameter] ?string $initial): Reply => self::start(
                $channel,
                $command,
                $initial,
            ),
            send: static fn(#[SensitiveParameter] string $response): Reply => self::send($channel, $response),
            cancel: static function () use ($channel): void {
                $channel->exchange('*', 501);
            },
        ))->run($exchange);
    }

    /**
     * Send AUTH, then the initial response, if any, after the server's first 334.
     *
     * @throws ExceptionInterface When the server refuses the mechanism or the connection fails.
     */
    private static function start(
        ChannelInterface $channel,
        string $command,
        #[SensitiveParameter]
        ?string $initial,
    ): Reply {
        $challenge = $channel->exchange($command, 334);

        return null === $initial ? Reply::challenge($challenge) : self::send($channel, $initial);
    }

    /**
     * Send a response and read the reply: a challenge, acceptance, or refusal.
     *
     * @throws ExceptionInterface When the connection fails or the server's reply cannot be read.
     */
    private static function send(ChannelInterface $channel, #[SensitiveParameter] string $response): Reply
    {
        try {
            return Reply::challenge($channel->exchangeSecret($response, 334));
        } catch (RuntimeException $e) {
            if (0 === $e->getCode()) {
                throw $e;
            }

            return 235 === $e->getCode() ? Reply::accepted() : Reply::refused($e);
        }
    }
}
