<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Integration\Transport;

use Contenir\Mail\AddressGroup;
use Contenir\Mail\AddressList;
use Contenir\Mail\Exception\ExceptionInterface;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Storage;
use Contenir\Mail\Transport\Smtp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function explode;
use function file_get_contents;
use function getenv;
use function is_string;
use function json_decode;
use function quoted_printable_decode;
use function str_repeat;
use function stream_context_create;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Sends through a real SMTP server, Mailpit, which requires STARTTLS and
 * AUTH, and reads back what it received through its HTTP API.
 */
#[CoversClass(Smtp::class)]
#[Group('integration')]
final class SmtpTest extends TestCase
{
    private string $api = '';

    protected function setUp(): void
    {
        if (! getenv('TESTS_CONTENIR_MAIL_SMTP_ENABLED')) {
            static::markTestSkipped('Contenir_Mail SMTP tests are not enabled');
        }

        $this->api = (string) getenv('TESTS_CONTENIR_MAIL_SMTP_API');
        $this->request('DELETE', '/api/v1/messages');
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(string $host): array
    {
        return [
            'host' => $host,
            'port' => (string) getenv('TESTS_CONTENIR_MAIL_SMTP_PORT'),
            'auth' => [
                'type'     => 'plain',
                'username' => (string) getenv('TESTS_CONTENIR_MAIL_SMTP_USER'),
                'password' => (string) getenv('TESTS_CONTENIR_MAIL_SMTP_PASSWORD'),
            ],
        ];
    }

    private function request(string $method, string $path): string
    {
        $body = file_get_contents($this->api . $path, context: stream_context_create([
            'http' => ['method' => $method],
        ]));
        static::assertTrue(is_string($body), "{$method} {$path} failed");

        return $body;
    }

    private function sendAndReadBack(Message $message): Storage\Message
    {
        (new Smtp(self::config((string) getenv('TESTS_CONTENIR_MAIL_SMTP_HOST'))))->send($message);

        return Storage\Message::fromString($this->request('GET', '/api/v1/message/latest/raw'));
    }

    #[Test]
    public function deliversUtf8SubjectAndDisplayName(): void
    {
        $received = $this->sendAndReadBack(
            (new Message())->setFrom('jo@example.org', 'Jö Bloggs')
                ->setTo('sam@example.org')
                ->setSubject('Grüße aus Köln — “quoted”')
                ->setText('Hallo'),
        );

        static::assertSame(
            ['Grüße aus Köln — “quoted”', 'Jö Bloggs'],
            [$received->getSubject(), $received->getFrom()->first()?->getName()],
        );
    }

    /**
     * Mailpit lists as Bcc the envelope recipients that are not in the headers,
     * so this shows a Bcc address reached the server only in RCPT TO.
     */
    #[Test]
    public function deliversToGroupMembersAndBccOnlyInTheEnvelope(): void
    {
        $this->sendAndReadBack(
            (new Message())->setFrom('jo@example.org')
                ->setTo(new AddressGroup('Team', AddressList::fromIterable(['a@example.org', 'b@example.org'])))
                ->setBcc('c@example.org')
                ->setSubject('Group')
                ->setText('Hi team'),
        );

        /** @var array{To: list<array{Address: string}>, Bcc: list<array{Address: string}>} $summary */
        $summary = json_decode(
            $this->request('GET', '/api/v1/message/latest'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        static::assertSame(
            [['a@example.org', 'b@example.org'], ['c@example.org']],
            [array_column($summary['To'], 'Address'), array_column($summary['Bcc'], 'Address')],
        );
    }

    #[Test]
    public function deliversAttachmentAndLongLinesIntact(): void
    {
        $line     = str_repeat('0123456789', times: 120);
        $received = $this->sendAndReadBack(
            (new Message())->setFrom('jo@example.org')
                ->setTo('sam@example.org')
                ->setSubject('Attachment')
                ->setText($line)
                ->attach(Attachment::fromString('%PDF-1.4 binary ' . "\x00\xFF", 'report.pdf', 'application/pdf')),
        );

        static::assertSame(
            ['multipart/mixed', 2, $line],
            [
                explode(';', (string) $received->getHeaders()->get('Content-Type')?->getFieldValue())[0],
                $received->countParts(),
                trim(quoted_printable_decode($received->getPart(1)->getContent())),
            ],
        );
    }

    #[Test]
    public function refusesServerWhoseCertificateNamesAnotherHost(): void
    {
        $this->expectException(ExceptionInterface::class);

        (new Smtp(self::config('127.0.0.1')))->send(
            (new Message())->setFrom('jo@example.org')
                ->setTo('sam@example.org')
                ->setText('x'),
        );
    }
}
