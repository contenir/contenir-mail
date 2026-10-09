<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function dirname;
use function preg_match_all;
use function strlen;
use function strtr;
use function substr;

/**
 * Every class, interface, enum and trait says whether it is public API
 * covered by semantic versioning (`@api`) or not for users (`@internal`).
 */
#[CoversNothing]
#[Group('unit')]
final class ApiMarkerTest extends TestCase
{
    #[Test]
    #[DataProvider('symbolProvider')]
    public function isMarkedEitherApiOrInternal(string $symbol): void
    {
        /** @var class-string $symbol */
        $docComment = (string) (new ReflectionClass($symbol))->getDocComment();
        $markers    = preg_match_all('/^\s*\*\s*@(?:api|internal)\b/m', $docComment);

        static::assertSame(1, $markers, "{$symbol} must carry exactly one of @api and @internal");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function symbolProvider(): iterable
    {
        $source = dirname(__DIR__, levels: 2) . '/src/';
        $files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $path   = substr($file->getPathname(), strlen($source), -4);
            $symbol = 'Contenir\\Mail\\' . strtr($path, ['/' => '\\']);

            yield $symbol => [$symbol];
        }
    }
}
