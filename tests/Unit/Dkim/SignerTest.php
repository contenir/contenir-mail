<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Canonicalization;
use Contenir\Mail\Dkim\DkimConfig;
use Contenir\Mail\Dkim\Exception\InvalidArgumentException;
use Contenir\Mail\Dkim\PrivateKey;
use Contenir\Mail\Dkim\SignatureHeader;
use Contenir\Mail\Dkim\Signer;
use Contenir\Mail\Header\Date;
use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Headers;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Protocol\Smtp as SmtpProtocol;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Tests\Unit\TestAsset\DkimKeys;
use Contenir\Mail\Tests\Unit\TestAsset\DkimVerifier;
use Contenir\Mail\Tests\Unit\TestAsset\FixedClock;
use Contenir\Mail\Tests\Unit\TestAsset\InjectingHeader;
use Contenir\Mail\Tests\Unit\TestAsset\SmtpServer;
use Contenir\Mail\Transport\Exception\RuntimeException as TransportRuntimeException;
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\Smtp;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_search;
use function array_slice;
use function base64_encode;
use function count;
use function explode;
use function file_get_contents;
use function hash;
use function max;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

#[CoversClass(Signer::class)]
#[CoversClass(Headers::class)]
#[Group('unit')]
final class SignerTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    /** The time of the signatures of RFC 8463, Appendix A */
    private const int SIGNED_AT = 1_528_637_909;

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    #[Test]
    public function writesSignatureHeaderFirstWithTagsInOrderFolded(): void
    {
        $signed = self::signer()->sign(self::message());

        static::assertSame(
            "DKIM-Signature: v=1; a=ed25519-sha256; c=relaxed/relaxed;\r\n"
                . " d=football.example.com; s=brisbane; t=1528637909; h=From:To:Subject:Date;\r\n"
                . " bh=2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=; b=RUm+0NSBLm1cyOfmgMEN0NTy1A\r\n"
                . " vOxfAOwBO+21C8i5RlD6I4FG2vFfX2y8xlRl5TjDG/NJ96qVfzsrxlQ+CtAQ==\r\n",
            substr($signed->toString(), offset: 0, length: (int) strpos($signed->toString(), needle: "\r\nDate:") + 2),
        );
    }

    #[Test]
    #[DataProvider('canonicalizations')]
    public function signsSoThatTheSignatureVerifies(
        string $keyFile,
        string $record,
        Canonicalization $header,
        Canonicalization $body,
    ): void {
        $signer = new Signer(new DkimConfig(
            'example.com',
            'mail',
            PrivateKey::fromFile(DkimKeys::path($keyFile)),
            headerCanonicalization: $header,
            bodyCanonicalization: $body,
        ));

        static::assertSame('pass', DkimVerifier::verify($signer->sign(self::mimeMessage())->toString(), $record));
    }

    /**
     * @return array<string, array{string, string, Canonicalization, Canonicalization}>
     */
    public static function canonicalizations(): array
    {
        $cases = [];
        foreach ([
            'RSA'     => ['test-only-rsa-2048.pem', DkimKeys::TEST_RSA_RECORD],
            'Ed25519' => ['test-only-ed25519.pem', DkimKeys::TEST_ED25519_RECORD],
        ] as $name => [$file, $record]) {
            foreach (Canonicalization::cases() as $header) {
                foreach (Canonicalization::cases() as $body) {
                    $cases["{$name}, {$header->value}/{$body->value}"] = [$file, $record, $header, $body];
                }
            }
        }

        return $cases;
    }

    #[Test]
    public function foldsEveryLineOfTheSignatureWithin78Characters(): void
    {
        $signer = new Signer(new DkimConfig(
            'example.com',
            'mail',
            PrivateKey::fromFile(DkimKeys::path('test-only-rsa-2048.pem')),
            identity: 'news@example.com',
            signBodyLength: true,
            expiresAfter: 86_400,
        ));
        $header  = $signer->sign(self::mimeMessage())->getHeaders()->get('DKIM-Signature')?->toString() ?? '';
        $lengths = array_map(strlen(...), explode("\r\n", $header));

        static::assertSame([78, true], [max($lengths), 8 < count($lengths)]);
    }

    #[Test]
    #[DataProvider('firstLines')]
    public function foldsBeforeATagThatWouldTakeTheLinePast78Characters(string $domain, string $firstLine): void
    {
        $signer = new Signer(new DkimConfig($domain, 'mail', PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED)));
        $header = $signer->sign(self::message())->getHeaders()->get('DKIM-Signature')?->toString() ?? '';

        static::assertSame($firstLine, explode("\r\n", $header)[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function firstLines(): array
    {
        $start = 'DKIM-Signature: v=1; a=ed25519-sha256; c=relaxed/relaxed;';

        return [
            'd= ends the line at 78' => ['abcdefghijklm.com', "{$start} d=abcdefghijklm.com;"],
            'd= would end it at 79'  => ['abcdefghijklmn.com', $start],
        ];
    }

    #[Test]
    public function writesOptionalTagsInOrder(): void
    {
        $signer = new Signer(
            new DkimConfig(
                'example.com',
                'mail',
                PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
                headers: ['From'],
                identity: '@example.com',
                signBodyLength: true,
                expiresAfter: 3600,
            ),
            new FixedClock(new DateTimeImmutable('@' . self::SIGNED_AT)),
        );
        $value = $signer->sign(self::message())->getHeaders()->get('DKIM-Signature')?->getFieldValue() ?? '';

        static::assertStringStartsWith(
            'v=1; a=ed25519-sha256; c=relaxed/relaxed; d=example.com; s=mail; i=@example.com; t=1528637909;'
                . ' x=1528641509; l=54; h=From; bh=',
            $value,
        );
    }

    #[Test]
    public function leavesOutTimestampWhenAskedTo(): void
    {
        $signer = new Signer(
            new DkimConfig(
                'example.com',
                'mail',
                PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
                includeTimestamp: false,
            ),
        );
        $tags = DkimVerifier::signatureTags($signer->sign(self::message())->toString());

        static::assertArrayNotHasKey('t', $tags);
    }

    #[Test]
    public function signsLengthOfTheCanonicalBody(): void
    {
        $signer = new Signer(
            new DkimConfig(
                'example.com',
                'mail',
                PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
                signBodyLength: true,
            ),
        );
        $signed = $signer->sign(self::message())->toString();

        static::assertSame(
            (string) strlen(DkimVerifier::signedBody($signed)),
            DkimVerifier::signatureTags($signed)['l'] ?? null,
        );
    }

    #[Test]
    public function signsEveryInstanceOfAListedHeaderFromTheBottomUp(): void
    {
        $message = self::message()
            ->addHeader(new GenericHeader('X-Tag', 'first'))
            ->addHeader(new GenericHeader('X-Tag', 'second'));
        $signer = new Signer(new DkimConfig(
            'example.com',
            'mail',
            PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
            headers: ['From', 'X-Tag', 'X-Absent'],
        ));
        $signed = $signer->sign($message)->toString();

        static::assertSame(
            [
                'From:X-Tag:X-Tag',
                "from:Joe SixPack <joe@football.example.com>\r\nx-tag:second\r\nx-tag:first\r\n",
                'pass',
            ],
            [
                DkimVerifier::signatureTags($signed)['h'] ?? '',
                substr(
                    DkimVerifier::signedData($signed),
                    offset: 0,
                    length: (int) strpos(DkimVerifier::signedData($signed), needle: 'dkim-signature:'),
                ),
                DkimVerifier::verify($signed, DkimKeys::RFC8463_ED25519_RECORD),
            ],
        );
    }

    #[Test]
    public function leavesBccUnsignedAndInTheMessage(): void
    {
        $message = self::message()->addBcc('hidden@example.net');
        $signer  = new Signer(new DkimConfig(
            'example.com',
            'mail',
            PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
            headers: ['From', 'To'],
        ));
        $signed = $signer->sign($message);

        static::assertSame(
            ['From:To', 'hidden@example.net'],
            [DkimVerifier::signatureTags($signed->toString())['h'] ?? '', $signed->getBcc()->first()?->getEmail()],
        );
    }

    #[Test]
    public function writesBodyWithEveryLineEndingInCrlf(): void
    {
        $signed = self::signer()->sign(self::message()->setBody("one\ntwo\rthree\r\nfour"));

        static::assertSame("one\r\ntwo\r\nthree\r\nfour", $signed->getBodyText());
    }

    #[Test]
    public function keepsHeadersAsTheyWereWritten(): void
    {
        $message = self::message();
        $signed  = self::signer()->sign($message);

        static::assertSame(
            $message->getHeaders()->toString(),
            substr(
                $signed->getHeaders()->toString(),
                strlen((string) $signed->getHeaders()->get('DKIM-Signature')?->toString()) + 2,
            ),
        );
    }

    #[Test]
    public function keepsParsedHeadersByteForByte(): void
    {
        $raw     = "From: Joe  SixPack\r\n <joe@football.example.com>\r\nTo: suzie@shopping.example.net\r\nSubject:  Folded\r\n  oddly\r\n\r\nHi.\r\n";
        $signed  = self::signer()->sign(Message::fromString($raw));
        $written = $signed->toString();

        static::assertSame([$raw, 'pass'], [
            substr($written, (int) strpos($written, needle: "\r\nFrom:") + 2),
            DkimVerifier::verify($written, DkimKeys::RFC8463_ED25519_RECORD),
        ]);
    }

    #[Test]
    public function signsAgainWithASecondKeySoBothSignaturesVerify(): void
    {
        $rsa     = new Signer(new DkimConfig('example.com', 'rsa', self::keyFile('test-only-rsa-2048.pem')));
        $ed25519 = new Signer(new DkimConfig('example.com', 'ed', self::keyFile('test-only-ed25519.pem')));
        $twice   = $ed25519->sign($rsa->sign(self::mimeMessage()));
        $signed  = $twice->toString();

        static::assertSame(
            ['pass', 'pass'],
            [
                DkimVerifier::verify($signed, DkimKeys::TEST_ED25519_RECORD, index: 0),
                DkimVerifier::verify($signed, DkimKeys::TEST_RSA_RECORD, index: 1),
            ],
        );
    }

    #[Test]
    public function leavesTheOriginalMessageUnsigned(): void
    {
        $message = self::message();
        self::signer()->sign($message);

        static::assertFalse($message->getHeaders()->has('DKIM-Signature'));
    }

    #[Test]
    public function addsHeaderOfItsOwnClass(): void
    {
        static::assertInstanceOf(
            SignatureHeader::class,
            self::signer()->sign(self::message())->getHeaders()->get('DKIM-Signature'),
        );
    }

    #[Test]
    public function refusesMessageWithoutFrom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A message without a From header cannot be signed with DKIM');

        self::signer()
            ->sign(
                (new Message())->setTo('suzie@shopping.example.net')
                    ->setBody('Hi.'),
            );
    }

    #[Test]
    public function refusesHeaderWithLineBreakThatIsNotFolding(): void
    {
        $this->expectException(TransportRuntimeException::class);
        $this->expectExceptionMessage('Header "X-Custom" contains a line break that is not folding; not sending it');

        self::signer()->sign(self::message()->addHeader(new InjectingHeader()));
    }

    #[Test]
    public function readsSettingsFromIterable(): void
    {
        $signer = new Signer([
            'domain'      => 'example.com',
            'selector'    => 'mail',
            'private_key' => DkimKeys::RFC8463_ED25519_SEED,
        ]);

        static::assertSame('example.com', $signer->getConfig()->domain);
    }

    #[Test]
    public function sendsOverSmtpABodyThatMatchesTheBodyHash(): void
    {
        $server    = new SmtpServer();
        $transport = new Smtp();
        $transport->setConnection(new SmtpProtocol(connection: $server));
        $message = self::message()
            ->addBcc('hidden@example.net')
            ->setBody(".Leading dot\r\n..Two dots\nbare LF\rbare CR\r\n.\r\n\r\n\r\n");
        $signed = self::signer()->sign($message);

        $transport->send($signed);

        $lines    = $server->sentLines();
        $data     = array_slice($lines, (int) array_search('DATA', $lines, strict: true) + 1, length: -1);
        $received = '';
        foreach ($data as $line) {
            $received .= (str_starts_with($line, '.') ? substr($line, offset: 1) : $line) . "\r\n";
        }

        static::assertSame(
            [
                'pass',
                DkimVerifier::signatureTags($received)['bh'] ?? '',
            ],
            [
                DkimVerifier::verify($received, DkimKeys::RFC8463_ED25519_RECORD),
                base64_encode(hash(
                    'sha256',
                    Canonicalization::Relaxed->body(".Leading dot\r\n..Two dots\r\nbare LF\r\nbare CR\r\n.\r\n"),
                    binary: true,
                )),
            ],
        );
    }

    #[Test]
    public function writesToFileAMessageWhoseSignatureVerifies(): void
    {
        $transport = new File(['path' => $this->directory]);
        $signer    = new Signer(new DkimConfig(
            'example.com',
            'mail',
            PrivateKey::fromFile(DkimKeys::path('test-only-rsa-2048.pem')),
            headerCanonicalization: Canonicalization::Simple,
            bodyCanonicalization: Canonicalization::Simple,
        ));

        $transport->send($signer->sign(
            self::mimeMessage()->attach(Attachment::fromString('report', 'report.txt', 'text/plain')),
        ));
        $written = (string) file_get_contents((string) $transport->getLastFile());

        static::assertSame('pass', DkimVerifier::verify($written, DkimKeys::TEST_RSA_RECORD));
    }

    private static function keyFile(string $name): PrivateKey
    {
        return PrivateKey::fromFile(DkimKeys::path($name));
    }

    private static function signer(): Signer
    {
        return new Signer(
            new DkimConfig(
                'football.example.com',
                'brisbane',
                PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
            ),
            new FixedClock(new DateTimeImmutable('@' . self::SIGNED_AT)),
        );
    }

    /**
     * The message of RFC 8463, Appendix A, without its Message-ID.
     */
    private static function message(): Message
    {
        return (new Message(new Headers(new Date(new DateTimeImmutable('Fri, 11 Jul 2003 21:00:37 -0700')))))->setFrom(
            'joe@football.example.com',
            'Joe SixPack',
        )
            ->setTo('suzie@shopping.example.net', 'Suzie Q')
            ->setSubject('Is dinner ready?')
            ->setBody("Hi.\r\n\r\nWe lost the game.  Are you hungry yet?\r\n\r\nJoe.\r\n");
    }

    private static function mimeMessage(): Message
    {
        return (new Message())->setFrom('joe@example.com', 'Joe SixPack')
            ->setTo('suzie@example.net', 'Suzie Q')
            ->setCc('team@example.net')
            ->setSubject('Is dinner ready? Ünïcödé subject, long enough to be folded over more than one line')
            ->setText("Hi.  \n\nWe lost the game.\t Are you hungry yet?\n.\nJoe.\n\n")
            ->setHtml('<p>Hi.</p>');
    }
}
