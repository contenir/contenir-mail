<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Contenir\Mail\ConfigProvider;
use Contenir\Mail\Module;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Module::class)]
#[Group('unit')]
final class ModuleTest extends TestCase
{
    #[Test]
    public function registersConfigProviderDependenciesWithServiceManager(): void
    {
        static::assertSame(
            ['service_manager' => (new ConfigProvider())->getDependencies()],
            (new Module())->getConfig(),
        );
    }
}
