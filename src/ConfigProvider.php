<?php

declare(strict_types=1);

namespace Contenir\Mail;

/**
 * Registers the optional PSR-11 factories with Mezzio, laminas-servicemanager or any
 * container that reads the "dependencies" key.
 *
 * @api
 */
final readonly class ConfigProvider
{
    /**
     * @return array{dependencies: array{factories: array<class-string, class-string>}}
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
        ];
    }

    /**
     * @return array{factories: array<class-string, class-string>}
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                Transport\TransportInterface::class => Container\TransportFactory::class,
            ],
        ];
    }
}
