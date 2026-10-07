<?php

namespace Contenir\Mail\Tests\Unit\Header;

use Contenir\Mail\Header\Exception;
use Contenir\Mail\Header\IdentificationField;
use Contenir\Mail\Header\InReplyTo;
use Contenir\Mail\Header\References;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_merge;

class IdentificationFieldTest extends TestCase
{
    public static function stringHeadersProvider(): array
    {
        return array_merge(
            [
                [
                    References::class,
                    'References: <1234@local.machine.example> <3456@example.net>',
                    ['1234@local.machine.example', '3456@example.net'],
                ],
            ],
            self::reversibleStringHeadersProvider(),
        );
    }

    public static function reversibleStringHeadersProvider(): array
    {
        return [
            [References::class, 'References: <1234@local.machine.example>', ['1234@local.machine.example']],
            [
                References::class,
                "References: <1234@local.machine.example>\r\n <3456@example.net>",
                ['1234@local.machine.example', '3456@example.net'],
            ],
            [InReplyTo::class, 'In-Reply-To: <3456@example.net>', ['3456@example.net']],
        ];
    }

    public static function invalidIds(): array
    {
        return [
            [References::class, ["1234@local.machine.example\r\n"]],
            [References::class, ['1234@local.machine.example', "3456@example.net\r\n"]],
            [InReplyTo::class, ["3456@example.net\r\n"]],
        ];
    }

    /**
     * @param string $className
     * @param string $headerString
     * @param string[] $ids
     */
    #[Test]
    #[DataProvider('stringHeadersProvider')]
    public function deserializationFromString($className, $headerString, $ids): void
    {
        /** @var IdentificationField $header */
        $header = $className::fromString($headerString);
        static::assertSame($ids, $header->getIds());
    }

    /**
     * @param string $className
     * @param string $headerString
     * @param string[] $ids
     */
    #[Test]
    #[DataProvider('reversibleStringHeadersProvider')]
    public function serializationToString($className, $headerString, $ids): void
    {
        /** @var IdentificationField $header */
        $header = new $className();
        $header->setIds($ids);
        static::assertSame($headerString, $header->toString());
    }

    /**
     * @param string $className
     * @param string $headerString
     * @param string[] $ids
     */
    #[Test]
    #[DataProvider('stringHeadersProvider')]
    public function defaultEncoding($className, $headerString, array $ids): void
    {
        /** @var IdentificationField $header */
        $header = $className::fromString($headerString);
        static::assertSame('ASCII', $header->getEncoding());
    }

    /**
     * @param string $className
     * @param string $headerString
     * @param string[] $ids
     */
    #[Test]
    #[DataProvider('stringHeadersProvider')]
    public function setEncodingHasNoEffect($className, $headerString, array $ids): void
    {
        /** @var IdentificationField $header */
        $header = $className::fromString($headerString);
        $header->setEncoding('UTF-8');
        static::assertSame('ASCII', $header->getEncoding());
    }

    /**
     * @param string $className
     * @param string[] $ids
     */
    #[Test]
    #[DataProvider('invalidIds')]
    public function setIdsThrowsOnInvalidInput($className, $ids): void
    {
        /** @var IdentificationField $header */
        $header = new $className();
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ID detected');
        $header->setIds($ids);
    }

    /**
     * @param string $className
     * @param string[] $ids
     */
    #[Test]
    #[DataProvider('invalidIds')]
    public function fromStringRaisesExceptionOnInvalidHeader($className, $ids): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid header line');
        /** @var IdentificationField $header */
        $header = $className::fromString('Foo: bar');
    }
}
