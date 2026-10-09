<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Disposition;
use Contenir\Mail\Mime\Mime;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\TransferEncoding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function constant;

/**
 * The string constants the Mime enums replace keep their values, and PHP 8.4
 * and later report their use.
 */
#[CoversClass(Mime::class)]
#[Group('unit')]
final class MimeDeprecatedConstantsTest extends TestCase
{
    #[DataProvider('replacedProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function keepsTheValueOfItsReplacement(string $name, string $replacement): void
    {
        static::assertSame($replacement, constant(Mime::class . '::' . $name));
    }

    #[IgnoreDeprecations]
    #[Test]
    public function keepsTheMisnamedRelativeType(): void
    {
        static::assertSame('multipart/relative', constant(Mime::class . '::MULTIPART_RELATIVE'));
    }

    #[DataProvider('messageProvider')]
    #[IgnoreDeprecations]
    #[RequiresPhp('>= 8.4')]
    #[Test]
    public function reportsItsUse(string $name, string $message): void
    {
        $this->expectUserDeprecationMessage(
            'Constant Contenir\Mail\Mime\Mime::' . $name . ' is deprecated since 0.3.0, ' . $message,
        );

        constant(Mime::class . '::' . $name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function replacedProvider(): array
    {
        return [
            '7bit'             => ['ENCODING_7BIT', TransferEncoding::SevenBit->value],
            '8bit'             => ['ENCODING_8BIT', TransferEncoding::EightBit->value],
            'quoted-printable' => ['ENCODING_QUOTEDPRINTABLE', TransferEncoding::QuotedPrintable->value],
            'base64'           => ['ENCODING_BASE64', TransferEncoding::Base64->value],
            'attachment'       => ['DISPOSITION_ATTACHMENT', Disposition::Attachment->value],
            'inline'           => ['DISPOSITION_INLINE', Disposition::Inline->value],
            'alternative'      => ['MULTIPART_ALTERNATIVE', MultipartType::Alternative->contentType()],
            'mixed'            => ['MULTIPART_MIXED', MultipartType::Mixed->contentType()],
            'related'          => ['MULTIPART_RELATED', MultipartType::Related->contentType()],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function messageProvider(): array
    {
        return [
            'encoding'    => ['ENCODING_BASE64', 'use TransferEncoding::Base64->value'],
            'disposition' => ['DISPOSITION_INLINE', 'use Disposition::Inline->value'],
            'multipart'   => ['MULTIPART_MIXED', 'use MultipartType::Mixed->contentType()'],
            'relative'    => [
                'MULTIPART_RELATIVE',
                'use MultipartType::Related->contentType(); no RFC defines multipart/relative, '
                    . 'and RFC 2387 names multipart/related',
            ],
        ];
    }
}
