<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage\AbstractStorage;
use Contenir\Mail\Storage\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractStorage::class)]
#[Group('unit')]
final class AbstractStorageTest extends TestCase
{
    /**
     * @param array<string, bool|null> $capabilities
     */
    #[DataProvider('supportsProvider')]
    #[Test]
    public function answersWhetherItSupportsAFeatureFromItsCapabilities(
        array $capabilities,
        Capability $capability,
        ?bool $expected,
    ): void {
        $storage = $this->getMockBuilder(AbstractStorage::class)
            ->onlyMethods([
                'countMessages',
                'getSize',
                'getSizes',
                'getMessage',
                'getRawHeader',
                'getRawContent',
                'close',
                'noop',
                'removeMessage',
                'getUniqueId',
                'getUniqueIds',
                'getNumberByUniqueId',
                'getCapabilities',
            ])
            ->getMock();
        $storage->method('getCapabilities')->willReturn($capabilities);

        static::assertSame($expected, $storage->supports($capability));
    }

    /**
     * @return array<string, array{array<string, bool|null>, Capability, bool|null}>
     */
    public static function supportsProvider(): array
    {
        return [
            'supported'         => [['top' => true, 'flags' => false], Capability::Top, true],
            'not supported'     => [['top' => true, 'flags' => false], Capability::Flags, false],
            'not yet known'     => [['uniqueid' => null], Capability::UniqueId, null],
            'not listed at all' => [[], Capability::FetchPart, null],
        ];
    }
}
