<?php

declare(strict_types=1);

namespace Contenir\Mail;

/**
 * The laminas-mvc module, registering the same services as ConfigProvider.
 */
final readonly class Module
{
    /**
     * @return array{service_manager: array{factories: array<class-string, class-string>}}
     */
    public function getConfig(): array
    {
        return [
            'service_manager' => (new ConfigProvider())->getDependencies(),
        ];
    }
}
