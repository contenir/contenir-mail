<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Smoke;

use Closure;
use Contenir\Mail\Exception\RuntimeException as RefusedException;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Protocol\Smtp\Auth\AuthenticatorInterface;
use Contenir\Mail\Protocol\Smtp\Auth\Plain;
use Contenir\Mail\Protocol\Smtp\Auth\XOAuth2;
use Contenir\Mail\Storage;
use Contenir\Mail\Transport\Smtp;
use RuntimeException;
use Throwable;

use function bin2hex;
use function fwrite;
use function max;
use function preg_match;
use function random_bytes;
use function sleep;
use function sprintf;
use function time;

use const STDOUT;

/**
 * Each check against the provider, run in turn and reported as it finishes.
 * A check that cannot run with the credentials given is skipped, not failed.
 *
 * @mago-expect lint:cyclomatic-complexity Each check, with what it does for each kind of credential.
 */
final class SmokeChecks
{
    /** How long a sent message may take to arrive */
    private const int DELIVERY_SECONDS = 90;

    /** How many of the newest POP3 messages are searched for the one sent */
    private const int POP3_SEARCH_DEPTH = 25;

    private int $failures = 0;

    private int $passes = 0;

    private int $skips = 0;

    private bool $sent = false;

    private ?string $token = null;

    private string $subject;

    public function __construct(
        private readonly Account $account,
        private readonly MicrosoftDeviceCode $deviceCode,
    ) {
        $this->subject = 'contenir-mail smoke test ' . bin2hex(random_bytes(4));
    }

    /**
     * @return int 0 when every check passed, 1 otherwise.
     */
    public function run(): int
    {
        $this->line(sprintf('Provider %s, account %s', $this->account->provider->value, $this->account->user));
        $this->check('Get an access token', $this->obtainToken(...));
        $this->check('Send over SMTP (STARTTLS)', $this->send(...));
        $this->check('Read it back over IMAP (TLS)', $this->readOverImap(...));
        $this->check('Read it back over POP3 (TLS)', $this->readOverPop3(...));
        $this->check('Refuse a bad access token cleanly', $this->refuseBadToken(...));

        $this->line(sprintf('%d passed, %d skipped, %d failed.', $this->passes, $this->skips, $this->failures));
        if (! $this->sent) {
            $this->line('Nothing was sent, so the provider was not tested; see tests/Smoke/README.md.');
        }

        return 0 === $this->failures && $this->sent ? 0 : 1;
    }

    /**
     * @param Closure(): string $check Returns what it did, or throws SkippedException.
     */
    private function check(string $name, Closure $check): void
    {
        try {
            $this->line("PASS  {$name}: {$check()}");
            $this->passes++;
        } catch (SkippedException $e) {
            $this->skips++;
            $this->line("SKIP  {$name}: {$e->getMessage()}");
        } catch (Throwable $e) {
            $this->failures++;
            $this->line(sprintf('FAIL  %s: %s (%s)', $name, $e->getMessage(), $e::class));
        }
    }

    private function line(string $text): void
    {
        fwrite(STDOUT, "{$text}\n");
    }

    private function obtainToken(): string
    {
        if (null !== $this->account->token) {
            $this->token = $this->account->token;

            return 'from SMOKE_TOKEN';
        }

        if ($this->account->provider->acceptsPasswords() || null === $this->account->microsoftClientId) {
            throw new SkippedException('no SMOKE_TOKEN, and no SMOKE_MS_CLIENT_ID for a Microsoft sign-in');
        }

        $this->token = $this->deviceCode->token($this->account->provider, $this->account->microsoftClientId);

        return 'signed in with a device code';
    }

    private function authenticator(): AuthenticatorInterface
    {
        if (null !== $this->token) {
            return new XOAuth2($this->account->user, $this->token);
        }

        if (null !== $this->account->password && $this->account->provider->acceptsPasswords()) {
            return new Plain($this->account->user, $this->account->password);
        }

        throw new SkippedException(
            $this->account->provider->acceptsPasswords()
                ? 'no SMOKE_PASSWORD or SMOKE_TOKEN'
                : 'no access token, and this provider does not accept passwords',
        );
    }

    private function send(): string
    {
        $authenticator = $this->authenticator();
        [$host, $port, $security] = $this->account->provider->smtp();
        $message = (new Message())->setFrom($this->account->user, 'contenir-mail smoke test')
            ->setTo($this->account->recipient())
            ->setSubject($this->subject)
            ->setText("Grüße from contenir-mail.\r\n.A line starting with a dot.\r\n")
            ->attach(Attachment::fromString("smoke\n", 'smoke.txt', 'text/plain'));

        (new Smtp(['host' => $host, 'port' => $port, 'security' => $security, 'auth' => $authenticator]))->send(
            $message,
        );
        $this->sent = true;

        return sprintf(
            '%s to %s via %s:%d with %s',
            $this->subject,
            $this->account->recipient(),
            $host,
            $port,
            $authenticator->mechanism(),
        );
    }

    private function readOverImap(): string
    {
        [$host, $port, $security] = $this->account->provider->imap();
        $settings = ['host' => $host, 'port' => $port, 'security' => $security, ...$this->signIn()];

        return $this->waitFor(function () use ($settings): ?string {
            $mailbox = new Storage\Imap($settings);
            $found   = $this->findInNewest($mailbox);
            $mailbox->close();

            return $found;
        });
    }

    private function readOverPop3(): string
    {
        [$host, $port, $security] = $this->account->provider->pop3();
        $settings = ['host' => $host, 'port' => $port, 'security' => $security, ...$this->signIn()];

        return $this->waitFor(function () use ($settings): ?string {
            $mailbox = new Storage\Pop3($settings);
            $found   = $this->findInNewest($mailbox);
            $mailbox->close();

            return $found;
        });
    }

    /**
     * The sign-in settings for IMAP and POP3: the access token when there is one, and the password otherwise.
     *
     * @return array<string, mixed>
     */
    private function signIn(): array
    {
        if (null !== $this->token) {
            return ['auth' => new XOAuth2($this->account->user, $this->token)];
        }

        if (null === $this->account->password || ! $this->account->provider->acceptsPasswords()) {
            throw new SkippedException('no access token or password');
        }

        return ['user' => $this->account->user, 'password' => $this->account->password];
    }

    /**
     * Where the message sent was found, if it is among the newest messages.
     */
    private function findInNewest(Storage\AbstractStorage $mailbox): ?string
    {
        $count = $mailbox->countMessages();
        for ($number = $count; $number > max(0, $count - self::POP3_SEARCH_DEPTH); $number--) {
            $message = $mailbox->getMessage($number);
            if ($message->getSubject() === $this->subject) {
                return sprintf('found as message %d of %d, %d parts', $number, $count, $message->countParts());
            }
        }

        return null;
    }

    /**
     * @param Closure(): ?string $attempt
     * @throws RuntimeException When the message has not arrived in time.
     */
    private function waitFor(Closure $attempt): string
    {
        $deadline = time() + self::DELIVERY_SECONDS;
        do {
            $found = $attempt();
            if (null !== $found) {
                return $found;
            }

            sleep(5);
        } while (time() < $deadline);

        throw new RuntimeException(sprintf(
            '"%s" did not arrive within %d seconds',
            $this->subject,
            self::DELIVERY_SECONDS,
        ));
    }

    /**
     * A token the provider has never issued must give the readable refusal, not
     * raw base64, and leave nothing hanging.
     */
    private function refuseBadToken(): string
    {
        if (! $this->sent) {
            throw new SkippedException('the provider was not reached with a working login');
        }

        [$host, $port, $security] = $this->account->provider->smtp();
        try {
            (new Smtp([
                'host'     => $host,
                'port'     => $port,
                'security' => $security,
                'auth'     => new XOAuth2($this->account->user, 'not-a-real-token-' . bin2hex(random_bytes(8))),
            ]))->send(
                (new Message())->setFrom($this->account->user)
                    ->setTo($this->account->recipient())
                    ->setText('x'),
            );
        } catch (RefusedException $e) {
            if (1 === preg_match('#^[A-Za-z0-9+/]{16,}={0,2}$#D', $e->getMessage())) {
                throw new RuntimeException("refused, but the error is raw base64: {$e->getMessage()}", previous: $e);
            }

            return sprintf('refused with "%s"', $e->getMessage());
        }

        throw new RuntimeException('the server accepted a token it never issued');
    }
}
