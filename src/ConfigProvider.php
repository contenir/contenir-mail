<?php

namespace Contenir\Mail;

class ConfigProvider
{
    /**
     * Retrieve configuration for contenir-mail package.
     *
     * @return array
     */
    public function __invoke()
    {
        return [
            'dependencies' => $this->getDependencyConfig(),
        ];
    }

    /**
     * Retrieve dependency settings for contenir-mail package.
     *
     * @return array
     */
    public function getDependencyConfig()
    {
        return [
            'factories' => [
                Protocol\SmtpPluginManager::class => Protocol\SmtpPluginManagerFactory::class,
            ],
        ];
    }
}
