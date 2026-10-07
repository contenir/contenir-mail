<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Header\GenericHeader;
use Contenir\Mail\Message;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Tests\Unit\TestAsset\FixedClock;
use Contenir\Mail\Tests\Unit\TestAsset\InjectingHeader;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\FileConfig;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function basename;
use function bin2hex;
use function clearstatcache;
use function error_clear_last;
use function error_get_last;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function glob;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(File::class)]
#[Group('unit')]
final class FileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/contenir_file_transport_' . bin2hex(random_bytes(6));
        mkdir($this->dir, permissions: 0o700);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob("{$this->dir}/*") as $file) {
            unlink((string) $file);
        }

        rmdir($this->dir);
    }

    #[Test]
    public function writesMessage(): void
    {
        $transport = $this->transport();
        $message   = self::message();

        $transport->send($message);

        static::assertSame($message->toString(), file_get_contents((string) $transport->getLastFile()));
    }

    #[Test]
    public function writesCompleteMimeMessage(): void
    {
        $transport = $this->transport();
        $message   = self::message()
            ->setBody(null)
            ->setText('Hello')
            ->setHtml('<p>Hello</p>')
            ->attach(
                Attachment::fromString('abc', 'a.txt', 'text/plain'),
            );

        $transport->send($message);

        static::assertSame(
            "{$message->getHeaders()->toString()}\r\n{$message->getBodyText()}",
            file_get_contents((string) $transport->getLastFile()),
        );
    }

    #[Test]
    public function writesIntoConfiguredDirectory(): void
    {
        $transport = $this->transport();

        $transport->send(self::message());

        static::assertStringStartsWith("{$this->dir}/", (string) $transport->getLastFile());
    }

    #[Test]
    public function hasNoLastFileBeforeSending(): void
    {
        static::assertNull($this->transport()->getLastFile());
    }

    /**
     * Predictable names let another user of a shared directory plant a file or symlink first.
     */
    #[Test]
    public function namesFilesWithTimeAndRandomPart(): void
    {
        $transport = $this->transport();

        $transport->send(self::message());

        static::assertMatchesRegularExpression(
            '/^ContenirMail_1339351644_[0-9a-f]{16}\.eml$/',
            basename((string) $transport->getLastFile()),
        );
    }

    #[Test]
    public function namesEachFileDifferently(): void
    {
        $transport = $this->transport();
        $transport->send(self::message());
        $first = $transport->getLastFile();

        $transport->send(self::message());

        static::assertNotSame($first, $transport->getLastFile());
    }

    #[Test]
    public function writesFileReadableOnlyByOwner(): void
    {
        $transport = $this->transport();

        $transport->send(self::message());

        clearstatcache();
        static::assertSame(0o600, fileperms((string) $transport->getLastFile()) & 0o777);
    }

    #[Test]
    public function usesNameFromCallback(): void
    {
        $transport = $this->transport(static fn(File $file): string => 'mail.eml');

        $transport->send(self::message());

        static::assertSame("{$this->dir}/mail.eml", $transport->getLastFile());
    }

    #[Test]
    public function passesTransportToCallback(): void
    {
        $seen      = null;
        $transport = $this->transport(static function (File $file) use (&$seen): string {
            $seen = $file;
            return 'mail.eml';
        });

        $transport->send(self::message());

        static::assertSame($transport, $seen);
    }

    /**
     * Path traversal: a name from the callback must not leave the configured directory.
     */
    #[DataProvider('unsafeNameProvider')]
    #[Test]
    public function rejectsNameThatIsNotPlainFileName(mixed $name, string $shown): void
    {
        $transport = $this->transport(static fn(): mixed => $name);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "The file name callback must return a plain file name without directories; got {$shown}",
        );

        $transport->send(self::message());
    }

    /**
     * An existing file, or a symlink planted under the next name, is never written through.
     */
    #[Test]
    public function refusesToOverwriteExistingFile(): void
    {
        file_put_contents("{$this->dir}/mail.eml", data: 'original');
        $transport = $this->transport(static fn(): string => 'mail.eml');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unable to create mail file \"{$this->dir}/mail.eml\": ");

        $transport->send(self::message());
    }

    #[Test]
    public function leavesNoPhpErrorBehindWhenFileExists(): void
    {
        file_put_contents("{$this->dir}/mail.eml", data: 'original');
        $transport = $this->transport(static fn(): string => 'mail.eml');
        error_clear_last();

        try {
            $transport->send(self::message());
        } catch (RuntimeException) {
            static::assertNull(error_get_last());
            return;
        }

        static::fail('An existing file was overwritten');
    }

    #[Test]
    public function leavesExistingFileUnchanged(): void
    {
        file_put_contents("{$this->dir}/mail.eml", data: 'original');
        $transport = $this->transport(static fn(): string => 'mail.eml');

        try {
            $transport->send(self::message());
        } catch (RuntimeException) {
            static::assertSame('original', file_get_contents("{$this->dir}/mail.eml"));
            return;
        }

        static::fail('An existing file was overwritten');
    }

    #[Test]
    public function refusesToWriteThroughPlantedSymlink(): void
    {
        file_put_contents("{$this->dir}/target", data: 'original');
        symlink("{$this->dir}/target", "{$this->dir}/mail.eml");
        $transport = $this->transport(static fn(): string => 'mail.eml');

        try {
            $transport->send(self::message());
        } catch (RuntimeException) {
            static::assertSame([true, 'original'], [
                is_link("{$this->dir}/mail.eml"),
                file_get_contents("{$this->dir}/target"),
            ]);
            return;
        }

        static::fail('A planted symlink was written through');
    }

    /**
     * A custom header that writes its own line break could add headers the sender never set.
     */
    #[Test]
    public function refusesHeaderWithUnfoldedLineBreakAgainstHeaderInjection(): void
    {
        $transport = $this->transport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Header "X-Custom" contains a line break that is not folding');

        $transport->send(self::message()->addHeader(new InjectingHeader()));
    }

    #[Test]
    public function readsConfigFromArray(): void
    {
        static::assertSame($this->dir, (new File(['path' => $this->dir]))->getConfig()->path);
    }

    #[Test]
    public function usesDefaultConfigWithoutSettings(): void
    {
        static::assertEquals(new FileConfig(), (new File())->getConfig());
    }

    #[Test]
    public function keepsGivenConfig(): void
    {
        $config = new FileConfig($this->dir);

        static::assertSame($config, (new File($config))->getConfig());
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unsafeNameProvider(): array
    {
        return [
            'parent directory' => ['../escape.eml', '"../escape.eml"'],
            'absolute path'    => ['/etc/passwd', '"/etc/passwd"'],
            'subdirectory'     => ['sub/mail.eml', '"sub/mail.eml"'],
            'backslash'        => ['..\\escape.eml', '"..\\escape.eml"'],
            'drive colon'      => ['C:mail.eml', '"C:mail.eml"'],
            'NUL'              => ["mail.eml\0.txt", "\"mail.eml\0.txt\""],
            'line break'       => ["mail\n.eml", "\"mail\n.eml\""],
            'dot'              => ['.', '"."'],
            'dot dot'          => ['..', '".."'],
            'empty'            => ['', '""'],
            'not a string'     => [42, 'int'],
        ];
    }

    /**
     * @param (callable(File): mixed)|null $callback
     */
    private function transport(?callable $callback = null): File
    {
        return new File(
            new FileConfig($this->dir, $callback),
            new FixedClock(new DateTimeImmutable('Sun, 10 Jun 2012 20:07:24 +0200')),
        );
    }

    private static function message(): Message
    {
        return (new Message())->addTo('test@example.com', 'Example Test')
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing File')
            ->setBody('This is only a test.')
            ->addHeader(new GenericHeader('X-Foo-Bar', 'Matthew'));
    }
}
