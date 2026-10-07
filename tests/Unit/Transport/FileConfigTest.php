<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\FileConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function chmod;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function touch;
use function unlink;

#[CoversClass(FileConfig::class)]
#[Group('unit')]
final class FileConfigTest extends TestCase
{
    private string $dir;

    /** @var list<string> */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/contenir_file_config_' . bin2hex(random_bytes(6));
        mkdir($this->dir, permissions: 0o700);
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            unlink($path);
        }

        chmod($this->dir, permissions: 0o700);
        rmdir($this->dir);
    }

    #[Test]
    public function writesToSystemTemporaryDirectoryByDefault(): void
    {
        static::assertSame(sys_get_temp_dir(), (new FileConfig())->path);
    }

    #[Test]
    public function hasNoCallbackByDefault(): void
    {
        static::assertNull((new FileConfig())->callback);
    }

    #[Test]
    public function keepsDirectory(): void
    {
        static::assertSame($this->dir, (new FileConfig($this->dir))->path);
    }

    #[Test]
    public function keepsCallbackAsClosure(): void
    {
        $callback = (new FileConfig($this->dir, 'strtoupper'))->callback;

        static::assertSame('NAME', null === $callback ? null : $callback('name'));
    }

    #[Test]
    public function readsSettings(): void
    {
        $config = FileConfig::fromIterable([
            'path'     => $this->dir,
            'callback' => static fn(File $file): string => 'a.eml',
        ]);

        static::assertSame($this->dir, $config->path);
    }

    #[Test]
    public function readsPathGivenAfterCallback(): void
    {
        $config = FileConfig::fromIterable(['callback' => static fn(): string => 'a.eml', 'path' => $this->dir]);

        static::assertSame($this->dir, $config->path);
    }

    #[Test]
    public function acceptsLocalDirectoryWithColonInName(): void
    {
        mkdir("{$this->dir}/mail:out");

        try {
            static::assertSame("{$this->dir}/mail:out", (new FileConfig("{$this->dir}/mail:out"))->path);
        } finally {
            rmdir("{$this->dir}/mail:out");
        }
    }

    #[Test]
    public function readsCallbackFromSettings(): void
    {
        $config = FileConfig::fromIterable(['callback' => static fn(): string => 'a.eml']);

        static::assertSame('a.eml', null === $config->callback ? null : ($config->callback)());
    }

    #[Test]
    public function rejectsCallbackThatIsNotCallable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('option "callback" must be a Closure or an invokable object, got string');

        FileConfig::fromIterable(['callback' => 'not a function']);
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "directory"');

        FileConfig::fromIterable(['directory' => $this->dir]);
    }

    /**
     * Stream wrappers such as phar:// can run code or reach the network when written to.
     */
    #[DataProvider('wrapperProvider')]
    #[Test]
    public function rejectsStreamWrapperPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The mail file directory must be a local path, not a stream wrapper URL');

        new FileConfig($path);
    }

    /**
     * A symlink can be swapped to point mail files at another directory.
     */
    #[Test]
    public function rejectsSymlinkedDirectory(): void
    {
        $link = "{$this->dir}/link";
        mkdir("{$this->dir}/target");
        symlink("{$this->dir}/target", $link);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('must be a writable directory and not a symlink');

            new FileConfig($link);
        } finally {
            unlink($link);
            rmdir("{$this->dir}/target");
        }
    }

    #[Test]
    public function rejectsFile(): void
    {
        touch("{$this->dir}/file");
        $this->cleanup[] = "{$this->dir}/file";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a writable directory and not a symlink');

        new FileConfig("{$this->dir}/file");
    }

    #[Test]
    public function rejectsMissingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a writable directory and not a symlink');

        new FileConfig("{$this->dir}/missing");
    }

    #[Test]
    public function rejectsReadOnlyDirectory(): void
    {
        chmod($this->dir, permissions: 0o500);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a writable directory and not a symlink');

        new FileConfig($this->dir);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wrapperProvider(): array
    {
        return [
            'phar'        => ['phar:///tmp/x.phar/mail'],
            'file scheme' => ['file:///tmp'],
            'ftp'         => ['ftp://example.com/mail'],
            'data'        => ['data:text/plain,x'],
        ];
    }
}
