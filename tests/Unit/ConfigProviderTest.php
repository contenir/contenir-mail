<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\ConfigProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * @group      Contenir_Mail
 * @covers \Contenir\Mail\ConfigProvider<extended>
 */
class ConfigProviderTest extends TestCase
{
    public function testInvoke(): void
    {
        $configProvider = new ConfigProvider();
        $config         = $configProvider();
        $this->assertEquals(['dependencies'], array_keys($config));
    }
}
