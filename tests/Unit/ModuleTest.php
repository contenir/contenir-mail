<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(\Contenir\Mail\Module::class)]
class ModuleTest extends TestCase
{
    public function testInvoke(): void
    {
        $module = new Module();
        $config = $module->getConfig();
        $this->assertEquals(['service_manager'], array_keys($config));
    }
}
