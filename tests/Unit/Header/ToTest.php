<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header;
use Contenir\Mail\Header\Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function count;
use function explode;

/**
 * This test is primarily to test that AbstractAddressList headers perform
 * header folding and MIME encoding properly.
 */
#[CoversClass(\Contenir\Mail\Header\To::class)]
class ToTest extends TestCase
{
    public function testHeaderFoldingOccursProperly(): void
    {
        $header = new Header\To();
        $list   = $header->getAddressList();
        for ($i = 0; $i < 10; $i++) {
            $list->add($i . '@getlaminas.org');
        }
        $string = $header->getFieldValue();
        $emails = explode("\r\n ", $string);
        $this->assertEquals(10, count($emails));
    }

    public static function headerLines(): array
    {
        return [
            'newline'   => ["To: xxx yyy\n"],
            'cr-lf'     => ["To: xxx yyy\r\n"],
            'cr-lf-wsp' => ["To: xxx yyy\r\n\r\n"],
            'multiline' => ["To: xxx\r\ny\r\nyy"],
        ];
    }

    #[DataProvider('headerLines')]
    #[Group('ZF2015-04')]
    public function testFromStringRaisesExceptionWhenCrlfInjectionIsDetected(string $header): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        Header\To::fromString($header);
    }
}
