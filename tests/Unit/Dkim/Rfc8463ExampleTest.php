<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Canonicalization;
use Contenir\Mail\Dkim\DkimConfig;
use Contenir\Mail\Dkim\PrivateKey;
use Contenir\Mail\Dkim\Signer;
use Contenir\Mail\Message;
use Contenir\Mail\Tests\Unit\TestAsset\DkimKeys;
use Contenir\Mail\Tests\Unit\TestAsset\DkimVerifier;
use Contenir\Mail\Tests\Unit\TestAsset\FixedClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function str_replace;
use function strpos;
use function substr;

/**
 * The signed example message of RFC 8463, Appendix A.3, with its RSA-SHA256 and Ed25519-SHA256 signatures.
 */
#[CoversClass(Canonicalization::class)]
#[CoversClass(PrivateKey::class)]
#[CoversClass(Signer::class)]
#[Group('unit')]
final class Rfc8463ExampleTest extends TestCase
{
    private const string SIGNED_MESSAGE = <<<'MESSAGE'
        DKIM-Signature: v=1; a=ed25519-sha256; c=relaxed/relaxed;
         d=football.example.com; i=@football.example.com;
         q=dns/txt; s=brisbane; t=1528637909; h=from : to :
         subject : date : message-id : from : subject : date;
         bh=2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=;
         b=/gCrinpcQOoIfuHNQIbq4pgh9kyIK3AQUdt9OdqQehSwhEIug4D11Bus
         Fa3bT3FY5OsU7ZbnKELq+eXdp1Q1Dw==
        DKIM-Signature: v=1; a=rsa-sha256; c=relaxed/relaxed;
         d=football.example.com; i=@football.example.com;
         q=dns/txt; s=test; t=1528637909; h=from : to : subject :
         date : message-id : from : subject : date;
         bh=2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=;
         b=F45dVWDfMbQDGHJFlXUNB2HKfbCeLRyhDXgFpEL8GwpsRe0IeIixNTe3
         DhCVlUrSjV4BwcVcOF6+FF3Zo9Rpo1tFOeS9mPYQTnGdaSGsgeefOsk2Jz
         dA+L10TeYt9BgDfQNZtKdN1WO//KgIqXP7OdEFE4LjFYNcUxZQ4FADY+8=
        From: Joe SixPack <joe@football.example.com>
        To: Suzie Q <suzie@shopping.example.net>
        Subject: Is dinner ready?
        Date: Fri, 11 Jul 2003 21:00:37 -0700 (PDT)
        Message-ID: <20030712040037.46341.5F8J@football.example.com>

        Hi.

        We lost the game.  Are you hungry yet?

        Joe.


        MESSAGE;

    private const string PUBLISHED_BODY_HASH = '2jUSOH9NhtVGCQWNr9BrIAPreKQjO6Sn7XIkfJVOzv8=';

    #[Test]
    #[DataProvider('signatures')]
    public function verifiesThePublishedSignature(int $index, string $record): void
    {
        static::assertSame('pass', DkimVerifier::verify(self::message(), $record, $index));
    }

    #[Test]
    #[DataProvider('signatures')]
    public function reproducesThePublishedSignatureFromItsKey(int $index, string $record, PrivateKey $key): void
    {
        $message = self::message();

        static::assertSame(
            DkimVerifier::signatureTags($message, $index)['b'] ?? '',
            base64_encode($key->sign(DkimVerifier::signedData($message, $index))),
        );
    }

    /**
     * @return array<string, array{int, string, PrivateKey}>
     */
    public static function signatures(): array
    {
        return [
            'Ed25519-SHA256' => [
                0,
                DkimKeys::RFC8463_ED25519_RECORD,
                PrivateKey::fromEd25519(DkimKeys::RFC8463_ED25519_SEED),
            ],
            'RSA-SHA256'     => [1, DkimKeys::RFC8463_RSA_RECORD, PrivateKey::fromPem(DkimKeys::RFC8463_RSA_PEM)],
        ];
    }

    #[Test]
    #[DataProvider('signatures')]
    public function signsTheExampleWithThePublishedBodyHashAndAVerifiableSignature(
        int $index,
        string $record,
        PrivateKey $key,
    ): void {
        $unsigned = self::message();
        $unsigned = substr($unsigned, (int) strpos($unsigned, needle: "\r\nFrom:") + 2);
        $signer   = new Signer(
            new DkimConfig(
                'football.example.com',
                'brisbane',
                $key,
                headers: ['From', 'To', 'Subject', 'Date', 'Message-ID'],
                identity: '@football.example.com',
            ),
            new FixedClock(new DateTimeImmutable('@1528637909')),
        );
        $signed = $signer->sign(Message::fromString($unsigned))->toString();

        static::assertSame(
            [self::PUBLISHED_BODY_HASH, 'pass', $unsigned],
            [
                DkimVerifier::signatureTags($signed)['bh'] ?? '',
                DkimVerifier::verify($signed, $record),
                substr($signed, (int) strpos($signed, needle: "\r\nFrom:") + 2),
            ],
        );
    }

    private static function message(): string
    {
        return str_replace(
            search: "\n",
            replace: "\r\n",
            subject: self::SIGNED_MESSAGE,
        );
    }
}
