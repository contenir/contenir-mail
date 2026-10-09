<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Message;
use Override;

use function array_values;
use function implode;
use function sprintf;

/**
 * Sends through the first transport that succeeds, trying each in turn, for
 * example a second SMTP relay when the first is down.
 *
 * A transport that fails with one of this package's exceptions is skipped;
 * when every transport has failed, the send fails with each one's reason.
 *
 * @api
 */
final readonly class Failover implements TransportInterface
{
    /** @var non-empty-list<TransportInterface> */
    private array $transports;

    /**
     * @throws Exception\InvalidArgumentException When no transport is given.
     */
    public function __construct(TransportInterface ...$transports)
    {
        if ([] === $transports) {
            throw new Exception\InvalidArgumentException('A failover transport needs at least one transport');
        }

        $this->transports = array_values($transports);
    }

    /**
     * @return non-empty-list<TransportInterface>
     */
    public function getTransports(): array
    {
        return $this->transports;
    }

    /**
     * @throws Exception\RuntimeException When every transport fails.
     */
    #[Override]
    public function send(Message $message): void
    {
        $failures = [];
        $last     = null;
        foreach ($this->transports as $index => $transport) {
            try {
                $transport->send($message);

                return;
            } catch (ExceptionInterface $exception) {
                $failures[] = sprintf('%d. %s: %s', $index + 1, $transport::class, $exception->getMessage());
                $last       = $exception;
            }
        }

        throw new Exception\RuntimeException(
            'Every transport failed: ' . implode('; ', $failures),
            previous: $last,
        );
    }
}
