<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\ConfigProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(\Contenir\Mail\ConfigProvider::class)]
class ConfigProviderTest extends TestCase
{
    #[Test]
    public function invoke(): void
    {
        $configProvider = new ConfigProvider();
        $config         = $configProvider();
        static::assertSame(['dependencies'], array_keys($config));
    }
}
