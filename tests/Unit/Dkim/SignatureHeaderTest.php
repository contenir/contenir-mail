<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Dkim;

use Contenir\Mail\Dkim\Exception\InvalidArgumentException;
use Contenir\Mail\Dkim\SignatureHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SignatureHeader::class)]
#[Group('unit')]
final class SignatureHeaderTest extends TestCase
{
    private const string FOLDED = "v=1; a=rsa-sha256; d=example.com;\r\n s=mail; b=abc\r\n def";

    #[Test]
    public function writesTheValueFoldedAsGiven(): void
    {
        static::assertSame(
            'DKIM-Signature: ' . self::FOLDED,
            SignatureHeader::fromString('dkim-signature: ' . self::FOLDED)->toString(),
        );
    }

    #[Test]
    public function encodedValueIsTheFoldedValue(): void
    {
        static::assertSame(
            self::FOLDED,
            SignatureHeader::fromString('DKIM-Signature: ' . self::FOLDED)->getEncodedFieldValue(),
        );
    }

    #[Test]
    public function fieldValueIsUnfolded(): void
    {
        static::assertSame(
            'v=1; a=rsa-sha256; d=example.com; s=mail; b=abc def',
            SignatureHeader::fromString('DKIM-Signature: ' . self::FOLDED)->getFieldValue(),
        );
    }

    #[Test]
    public function namesItselfDkimSignature(): void
    {
        static::assertSame('DKIM-Signature', SignatureHeader::fromString('DKIM-SIGNATURE: v=1')->getFieldName());
    }

    #[Test]
    public function refusesAnotherHeader(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line for DKIM-Signature string');

        SignatureHeader::fromString('Subject: v=1');
    }

    #[Test]
    public function refusesValueOutsideUsAscii(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'A DKIM-Signature value must be printable US-ASCII, folded with CRLF and white space',
        );

        SignatureHeader::fromString('DKIM-Signature: v=1; d=exämple.com');
    }
}
