<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol\Sasl;

use Closure;
use Contenir\Mail\Protocol\Exception\ExceptionInterface;
use Contenir\Mail\Protocol\Exception\RuntimeException;

/**
 * Runs a SASL exchange over IMAP AUTHENTICATE, POP3 AUTH or SMTP AUTH, which differ only
 * in how a step is written and its reply read: the protocol gives those as Closures.
 *
 * A challenge the exchange cannot answer is cancelled with "*" (RFC 4422, section 3.5)
 * before the reason is thrown, so the session can go on. A refusal is reported with the
 * exchange's message, and an acceptance is checked by the exchange, so SCRAM fails closed
 * when the server has not proved it knows the password.
 *
 * @internal
 */
final readonly class Authentication
{
    /**
     * @param Closure(?string): Reply $start Sends the command with the initial response, if any,
     *     and reads the reply that follows the response; a refused mechanism may be thrown.
     * @param Closure(string): Reply $send Sends a response, as a secret, and reads the reply.
     * @param Closure(): void $cancel Sends "*" and reads the reply.
     */
    public function __construct(
        private Closure $start,
        private Closure $send,
        private Closure $cancel,
    ) {}

    /**
     * @throws ExceptionInterface When the server refuses, a challenge cannot be answered, the
     *     exchange cannot trust the acceptance, or the connection fails.
     */
    public function run(ExchangeInterface $exchange): void
    {
        $reply = ($this->start)($exchange->initialResponse());
        while (null !== $reply->challenge) {
            try {
                $response = $exchange->respond($reply->challenge);
            } catch (RuntimeException $e) {
                $this->cancel($e);
            }

            $reply = ($this->send)($response);
        }

        $refusal = $reply->refusal;
        if (null !== $refusal) {
            throw new RuntimeException($exchange->refusal($refusal->getMessage()), (int) $refusal->getCode(), $refusal);
        }

        $exchange->complete();
    }

    /**
     * End the exchange with "*", then throw $reason, after any failure to cancel.
     *
     * @throws RuntimeException
     */
    private function cancel(RuntimeException $reason): never
    {
        try {
            ($this->cancel)();
        } catch (RuntimeException $e) {
            throw new RuntimeException($reason->getMessage(), previous: $e);
        }

        throw $reason;
    }
}
