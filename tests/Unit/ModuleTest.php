<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Module;
use PHPUnit\Framework\TestCase;

use function array_keys;

/**
 * @group      Contenir_Mail
 * @covers \Contenir\Mail\Module<extended>
 */
class ModuleTest extends TestCase
{
    public function testInvoke(): void
    {
        $module = new Module();
        $config = $module->getConfig();
        $this->assertEquals(['service_manager'], array_keys($config));
    }
}
