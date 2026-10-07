<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\AbstractAddressList;
use Contenir\Mail\Header\Exception\InvalidArgumentException;
use Contenir\Mail\Header\To;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function range;

/**
 * Checks that address-list headers fold and encode properly.
 */
#[CoversClass(To::class)]
#[CoversClass(AbstractAddressList::class)]
#[Group('unit')]
final class ToTest extends TestCase
{
    #[Test]
    public function foldsEachAddressOntoItsOwnLine(): void
    {
        $header = new To(new AddressList(...array_map(
            static fn(int $i): Address => new Address("{$i}@getlaminas.org"),
            range(0, end: 9),
        )));

        static::assertSame(
            "0@getlaminas.org,\r\n 1@getlaminas.org,\r\n 2@getlaminas.org,\r\n 3@getlaminas.org,\r\n"
                . " 4@getlaminas.org,\r\n 5@getlaminas.org,\r\n 6@getlaminas.org,\r\n 7@getlaminas.org,\r\n"
                . " 8@getlaminas.org,\r\n 9@getlaminas.org",
            $header->getEncodedFieldValue(),
        );
    }

    #[Test]
    public function writesFieldNameTo(): void
    {
        static::assertSame('To', (new To())->getFieldName());
    }

    #[Test]
    public function writesHeaderLine(): void
    {
        $header = new To(new AddressList(new Address('test@example.com', 'Example Test')));

        static::assertSame('To: Example Test <test@example.com>', $header->toString());
    }

    #[Test]
    public function acceptsLowerCaseFieldName(): void
    {
        static::assertTrue(To::fromString('to: test@example.com')->getAddressList()->has('test@example.com'));
    }

    #[DataProvider('crlfHeaderLineProvider')]
    #[Group('ZF2015-04')]
    #[Test]
    public function fromStringRaisesExceptionWhenCrlfInjectionIsDetected(string $headerLine): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header value detected');

        To::fromString($headerLine);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function crlfHeaderLineProvider(): array
    {
        return [
            'newline'   => ["To: xxx yyy\n"],
            'cr-lf'     => ["To: xxx yyy\r\n"],
            'cr-lf-wsp' => ["To: xxx yyy\r\n\r\n"],
            'multiline' => ["To: xxx\r\ny\r\nyy"],
        ];
    }
}
