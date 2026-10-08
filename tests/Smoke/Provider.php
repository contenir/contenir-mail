<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Smoke;

use Contenir\Mail\Protocol\Security;

use function getenv;

/**
 * The servers of one mail provider, and the ways it lets you sign in.
 */
enum Provider: string
{
    case Gmail     = 'gmail';
    case Outlook   = 'outlook';
    case Office365 = 'office365';
    case Custom    = 'custom';

    /**
     * @return array{string, int, Security}
     */
    public function smtp(): array
    {
        return match ($this) {
            self::Gmail => ['smtp.gmail.com', 587, Security::StartTls],
            self::Outlook => ['smtp-mail.outlook.com', 587, Security::StartTls],
            self::Office365 => ['smtp.office365.com', 587, Security::StartTls],
            self::Custom => [
                self::env('SMOKE_SMTP_HOST'),
                (int) self::env('SMOKE_SMTP_PORT', '587'),
                Security::StartTls,
            ],
        };
    }

    /**
     * @return array{string, int, Security}
     */
    public function imap(): array
    {
        return match ($this) {
            self::Gmail => ['imap.gmail.com', 993, Security::Tls],
            self::Outlook, self::Office365 => ['outlook.office365.com', 993, Security::Tls],
            self::Custom => [self::env('SMOKE_IMAP_HOST'), (int) self::env('SMOKE_IMAP_PORT', '993'), Security::Tls],
        };
    }

    /**
     * @return array{string, int, Security}
     */
    public function pop3(): array
    {
        return match ($this) {
            self::Gmail => ['pop.gmail.com', 995, Security::Tls],
            self::Outlook, self::Office365 => ['outlook.office365.com', 995, Security::Tls],
            self::Custom => [self::env('SMOKE_POP3_HOST'), (int) self::env('SMOKE_POP3_PORT', '995'), Security::Tls],
        };
    }

    /**
     * Whether the provider still accepts a password, such as a Gmail app password.
     * Microsoft has turned password sign-in off for IMAP, POP3 and SMTP.
     */
    public function acceptsPasswords(): bool
    {
        return self::Outlook !== $this && self::Office365 !== $this;
    }

    /**
     * The Microsoft sign-in tenant: personal accounts or work and school accounts.
     */
    public function tenant(): string
    {
        return self::Outlook === $this ? 'consumers' : 'organizations';
    }

    private static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return false === $value || '' === $value ? $default : $value;
    }
}
