<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Writable;

use Contenir\Mail\Exception\InvalidArgumentException as ConfigException;
use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Writable\MaildirConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaildirConfig::class)]
#[Group('unit')]
final class MaildirConfigTest extends TestCase
{
    #[Test]
    public function readsSettings(): void
    {
        $config = MaildirConfig::fromIterable([
            'dirname'        => '/mail',
            'delim'          => ':',
            'folder'         => 'Archive',
            'create'         => 'yes',
            'directory_mode' => 0o750,
            'file_mode'      => '416',
        ]);

        static::assertSame(
            ['/mail', ':', 'Archive', true, 0o750, 0o640],
            [
                $config->dirname,
                $config->delim,
                $config->folder,
                $config->create,
                $config->directoryMode,
                $config->fileMode,
            ],
        );
    }

    #[Test]
    public function readsDefaultSettings(): void
    {
        $config = MaildirConfig::fromIterable(['dirname' => '/mail']);

        static::assertSame(
            ['.', 'INBOX', false, 0o700, 0o600],
            [$config->delim, $config->folder, $config->create, $config->directoryMode, $config->fileMode],
        );
    }

    #[Test]
    public function hasPrivateModesByDefault(): void
    {
        $config = new MaildirConfig('/mail');

        static::assertSame([false, 0o700, 0o600], [$config->create, $config->directoryMode, $config->fileMode]);
    }

    #[Test]
    public function givesReadingSettings(): void
    {
        $folder = (new MaildirConfig('/mail', ':', 'Archive'))->folderConfig();

        static::assertSame(['/mail', ':', 'Archive'], [$folder->dirname, $folder->delim, $folder->folder]);
    }

    #[DataProvider('invalidModeProvider')]
    #[Test]
    public function refusesModeOutOfRange(string $setting, int $mode): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("{$setting} must be between 0 and 0777");

        MaildirConfig::fromIterable(['dirname' => '/mail', $setting => $mode]);
    }

    #[Test]
    public function acceptsModesAtTheLimits(): void
    {
        $config = new MaildirConfig('/mail', directoryMode: 0, fileMode: 0o777);

        static::assertSame([0, 0o777], [$config->directoryMode, $config->fileMode]);
    }

    #[Test]
    public function requiresDirname(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('Contenir\Mail\Storage\Writable\MaildirConfig: option "dirname" is required');

        MaildirConfig::fromIterable([]);
    }

    #[Test]
    public function refusesUnsafeDelimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('delim must be one character');

        new MaildirConfig('/mail', '/');
    }

    #[Test]
    public function refusesDirnameThatIsNotLocal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('dirname must be a local file system path');

        new MaildirConfig('php://memory');
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function invalidModeProvider(): array
    {
        return [
            'directory too large' => ['directory_mode', 0o1000],
            'directory negative'  => ['directory_mode', -1],
            'file too large'      => ['file_mode', 0o1000],
            'file negative'       => ['file_mode', -1],
        ];
    }
}
