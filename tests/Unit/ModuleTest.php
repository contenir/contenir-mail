<?php

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

#[CoversClass(\Contenir\Mail\Module::class)]
class ModuleTest extends TestCase
{
    #[Test]
    public function invoke(): void
    {
        $module = new Module();
        $config = $module->getConfig();
        static::assertEquals(['service_manager'], array_keys($config));
    }
}
