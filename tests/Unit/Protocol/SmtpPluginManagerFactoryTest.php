<?php

namespace Contenir\Mail\Tests\Unit\Protocol;

// phpcs:ignore WebimpressCodingStandard.PHP.CorrectClassNameCase.Invalid
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\SmtpPluginManager;
use Contenir\Mail\Protocol\SmtpPluginManagerFactory;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\ServiceLocatorInterface;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function method_exists;

class SmtpPluginManagerFactoryTest extends TestCase
{
    #[Test]
    public function factoryReturnsPluginManager(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $factory   = new SmtpPluginManagerFactory();

        $plugins = $factory($container, SmtpPluginManager::class);
        static::assertInstanceOf(SmtpPluginManager::class, $plugins);

        if (method_exists($plugins, 'configure')) {
            $reflectionClass         = new ReflectionClass($plugins);
            $creationContextProperty = $reflectionClass->getProperty('creationContext');

            // laminas-servicemanager v3
            static::assertSame($container, $creationContextProperty->getValue($plugins));
        } else {
            // laminas-servicemanager v2
            static::assertSame($container, $plugins->getServiceLocator());
        }
    }

    #[Test]
    #[Depends('factoryReturnsPluginManager')]
    public function factoryConfiguresPluginManagerUnderContainerInterop(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $smtp      = $this->createMock(Smtp::class);

        $factory = new SmtpPluginManagerFactory();
        $plugins = $factory($container, SmtpPluginManager::class, [
            'services' => [
                'test' => $smtp,
            ],
        ]);
        static::assertSame($smtp, $plugins->get('test'));
    }

    #[Test]
    #[Depends('factoryReturnsPluginManager')]
    public function factoryConfiguresPluginManagerUnderServiceManagerV2(): void
    {
        $container = $this->createMock(ServiceLocatorInterface::class);

        $smtp = $this->createMock(Smtp::class);

        $factory = new SmtpPluginManagerFactory();
        $factory->setCreationOptions([
            'services' => [
                'test' => $smtp,
            ],
        ]);

        $plugins = $factory->createService($container);
        static::assertSame($smtp, $plugins->get('test'));
    }
}
