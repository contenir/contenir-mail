<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Imap;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Imap\MailboxName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Modified UTF-7 mailbox names (RFC 3501, section 5.1.3).
 */
#[CoversClass(MailboxName::class)]
#[Group('unit')]
final class MailboxNameTest extends TestCase
{
    #[Test]
    #[DataProvider('nameProvider')]
    public function encodesAName(string $name, string $encoded): void
    {
        static::assertSame($encoded, MailboxName::encode($name));
    }

    #[Test]
    #[DataProvider('nameProvider')]
    public function decodesAName(string $name, string $encoded): void
    {
        static::assertSame($name, MailboxName::decode($encoded));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'ASCII'                   => ['INBOX', 'INBOX'],
            'an ampersand'            => ['R&D', 'R&-D'],
            'ampersands only'         => ['&&', '&-&-'],
            'one accented letter'     => ['Entwürfe', 'Entw&APw-rfe'],
            'the RFC 3501 example'    => ['~peter/mail/台北/日本語', '~peter/mail/&U,BTFw-/&ZeVnLIqe-'],
            'a run needing padding'   => ['☺!', '&Jjo-!'],
            'a character outside BMP' => ['📁', '&2D3cwQ-'],
            'a control character'     => ["a\rb", 'a&AA0-b'],
            'an empty name'           => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function keepsAMalformedRunAsWritten(string $written): void
    {
        static::assertSame($written, MailboxName::decode($written));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedProvider(): array
    {
        return [
            'not closed'          => ['&ZeVn'],
            'an odd byte count'   => ['&AGEA-'],
            'a lone surrogate'    => ['&2D3-'],
            'not base64'          => ['&#!-'],
            'standard base64 "/"' => ['&U/BTFw-'],
        ];
    }

    #[Test]
    public function refusesANameThatIsNotUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A mailbox name must be UTF-8 text');

        MailboxName::encode("Entw\xFCrfe");
    }
}
