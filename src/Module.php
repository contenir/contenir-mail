<?php

namespace Contenir\Mail;

class Module
{
    /**
     * Retrieve laminas-mail package configuration for laminas-mvc context.
     *
     * @return array
     */
    public function getConfig()
    {
        $provider = new ConfigProvider();
        return [
            'service_manager' => $provider->getDependencyConfig(),
        ];
    }
}
