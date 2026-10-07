<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\MultipartType;
use Contenir\Mail\Mime\Part;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(Multipart::class)]
#[CoversClass(MultipartType::class)]
#[Group('unit')]
final class MultipartTest extends TestCase
{
    #[DataProvider('typeProvider')]
    #[Test]
    public function writesTheContentTypeWithItsBoundary(MultipartType $type, string $expected): void
    {
        static::assertSame(
            $expected,
            (new Multipart($type, [new Part('a')], boundary: 'frontier'))->getHeaders()->toString(),
        );
    }

    /**
     * @return array<string, array{MultipartType, string}>
     */
    public static function typeProvider(): array
    {
        return [
            'mixed'       => [MultipartType::Mixed, "Content-Type: multipart/mixed;\r\n boundary=\"frontier\"\r\n"],
            'alternative' => [
                MultipartType::Alternative,
                "Content-Type: multipart/alternative;\r\n boundary=\"frontier\"\r\n",
            ],
            'related'     => [MultipartType::Related, "Content-Type: multipart/related;\r\n boundary=\"frontier\"\r\n"],
        ];
    }

    #[Test]
    public function exposesItsType(): void
    {
        static::assertSame(
            MultipartType::Related,
            (new Multipart(MultipartType::Related, [new Part('a')]))->getType(),
        );
    }

    #[Test]
    public function keepsTheBoundaryGiven(): void
    {
        static::assertSame(
            'frontier',
            (new Multipart(MultipartType::Mixed, [new Part('a')], boundary: 'frontier'))->getBoundary(),
        );
    }

    #[Test]
    public function keepsItsPartsInOrder(): void
    {
        $first  = new Part('a');
        $second = new Part('b');

        static::assertSame(
            [$first, $second],
            (new Multipart(MultipartType::Mixed, [$first, $second]))->getParts(),
        );
    }

    #[Test]
    public function renumbersItsPartsAsAList(): void
    {
        $first  = new Part('a');
        $second = new Part('b');

        $multipart = new Multipart(MultipartType::Mixed, [3 => $first, 7 => $second]);

        static::assertSame([0 => $first, 1 => $second], $multipart->getParts());
    }

    #[Test]
    public function isAMultipartWithNoContentOfItsOwn(): void
    {
        $multipart = new Multipart(MultipartType::Mixed, [new Part('a')]);

        static::assertSame(
            [true, '', ''],
            [$multipart->isMultipart(), $multipart->getContent(), $multipart->getEncodedContent()],
        );
    }

    #[Test]
    public function generatesABoundaryThatCannotAppearInEncodedContent(): void
    {
        static::assertMatchesRegularExpression(
            '/^=_[0-9a-f]{32}$/D',
            (new Multipart(MultipartType::Mixed, [new Part('a')]))->getBoundary(),
        );
    }

    #[Test]
    public function generatesADifferentBoundaryEachTime(): void
    {
        $first = new Multipart(MultipartType::Mixed, [new Part('a')]);

        static::assertNotSame(
            $first->getBoundary(),
            (new Multipart(MultipartType::Mixed, [new Part('a')]))->getBoundary(),
        );
    }

    #[Test]
    public function rejectsNoParts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A multipart needs at least one part');

        new Multipart(MultipartType::Mixed, []);
    }

    #[DataProvider('validBoundaryProvider')]
    #[Test]
    public function acceptsBoundary(string $boundary): void
    {
        static::assertSame(
            $boundary,
            (new Multipart(MultipartType::Mixed, [new Part('a')], $boundary))->getBoundary(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validBoundaryProvider(): array
    {
        return [
            'one character'               => ['a'],
            'one digit'                   => ['0'],
            'seventy characters'          => [str_repeat('b', times: 70)],
            'every special character'     => ["'()+_,-./:=?"],
            'apostrophe last'             => ["a'"],
            'question mark last'          => ['a?'],
            'upper and lower case'        => ['AZaz09'],
            'inner space'                 => ['a b'],
            'leading space'               => [' a'],
            'seventy with an inner space' => [str_repeat('c', times: 34) . ' ' . str_repeat('c', times: 35)],
        ];
    }

    #[DataProvider('invalidBoundaryProvider')]
    #[Test]
    public function rejectsBoundary(string $boundary): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid MIME boundary \"{$boundary}\"");

        new Multipart(MultipartType::Mixed, [new Part('a')], $boundary);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidBoundaryProvider(): array
    {
        return [
            'empty'                  => [''],
            'seventy-one characters' => [str_repeat('b', times: 71)],
            'trailing space'         => ['a '],
            'only a space'           => [' '],
            'double quote'           => ['a"b'],
            'semicolon'              => ['a;b'],
            'non-ascii'              => ['grüße'],
            'carriage return'        => ["a\rb"],
            'inner line feed'        => ["a\nb"],
            'trailing line feed'     => ["abc\n"],
        ];
    }
}
