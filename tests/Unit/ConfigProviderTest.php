<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\ConfigProvider;
use Contenir\Mail\Container\TransportFactory;
use Contenir\Mail\Transport\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigProvider::class)]
#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function registersTransportFactory(): void
    {
        static::assertSame(
            ['factories' => [TransportInterface::class => TransportFactory::class]],
            (new ConfigProvider())->getDependencies(),
        );
    }

    #[Test]
    public function returnsDependenciesWhenInvoked(): void
    {
        $provider = new ConfigProvider();

        static::assertSame(['dependencies' => $provider->getDependencies()], $provider());
    }
}
