<?php

namespace Contenir\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Contenir\Mail\ConfigProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(\Contenir\Mail\ConfigProvider::class)]
class ConfigProviderTest extends TestCase
{
    public function testInvoke(): void
    {
        $configProvider = new ConfigProvider();
        $config         = $configProvider();
        $this->assertEquals(['dependencies'], array_keys($config));
    }
}
