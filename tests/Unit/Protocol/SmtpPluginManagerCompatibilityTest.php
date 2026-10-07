<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Smtp;
use Contenir\Mail\Protocol\SmtpPluginManager;
use Laminas\ServiceManager\ServiceManager;
use Laminas\ServiceManager\Test\CommonPluginManagerTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SmtpPluginManagerCompatibilityTest extends TestCase
{
    use CommonPluginManagerTrait;

    protected static function getPluginManager(): SmtpPluginManager
    {
        return new SmtpPluginManager(new ServiceManager());
    }

    protected function getV2InvalidPluginException(): string
    {
        return InvalidArgumentException::class;
    }

    protected function getInstanceOf(): string
    {
        return Smtp::class;
    }

    /**
     * Redeclared from CommonPluginManagerTrait, whose doc-comment metadata
     * PHPUnit 12 no longer reads.
     *
     * @param string $alias
     * @param string $expected
     */
    #[DataProvider('aliasProvider')]
    public function testPluginAliasesResolve($alias, $expected): void
    {
        static::assertInstanceOf(
            $expected,
            $this->getPluginManager()->get($alias),
            "Alias '{$alias}' does not resolve'",
        );
    }
}
