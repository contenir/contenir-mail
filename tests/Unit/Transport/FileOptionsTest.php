<?php

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Exception;
use Contenir\Mail\Transport\FileOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;

#[CoversClass(\Contenir\Mail\Transport\FileOptions::class)]
class FileOptionsTest extends TestCase
{
    private FileOptions $options;

    public function setUp(): void
    {
        $this->options = new FileOptions();
    }

    #[Test]
    public function pathIsSysTempDirByDefault(): void
    {
        static::assertSame(sys_get_temp_dir(), $this->options->getPath());
    }

    #[Test]
    public function defaultCallbackIsSetByDefault(): void
    {
        $callback = $this->options->getCallback();
        static::assertIsCallable($callback);
        $test = $callback('');
        static::assertMatchesRegularExpression('#^ContenirMail_\d+_\d+\.eml$#', $test);
    }

    #[Test]
    public function pathIsMutable(): void
    {
        $original = $this->options->getPath();
        $this->options->setPath(__DIR__);
        $test = $this->options->getPath();
        static::assertNotEquals($original, $test);
        static::assertSame(__DIR__, $test);
    }

    #[Test]
    public function callbackIsMutable(): void
    {
        $original = $this->options->getCallback();
        $new      = static function ($transport): void {};

        $this->options->setCallback($new);
        $test = $this->options->getCallback();
        static::assertNotSame($original, $test);
        static::assertSame($new, $test);
    }

    #[Test]
    public function setCallbackThrowsWhenNotCallable(): void
    {
        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects a valid callback');
        $this->options->setCallback(null);
    }

    #[Test]
    public function setPathThrowsWhenPathNotWritable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            static::markTestSkipped('File permissions are not enforced for the root user');
        }

        $this->expectException(Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('expects a valid path in which to write mail files');
        $this->options->setPath('/');
    }
}
