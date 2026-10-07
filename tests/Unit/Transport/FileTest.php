<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Message;
use Contenir\Mail\Transport\File;
use Contenir\Mail\Transport\FileOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(File::class)]
class FileTest extends TestCase
{
    private string $tempDir;
    private File $transport;

    public function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mail_file_transport';
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir);
        } else {
            $this->cleanup($this->tempDir);
        }

        $fileOptions = new FileOptions([
            'path' => $this->tempDir,
        ]);
        $this->transport = new File($fileOptions);
    }

    public function tearDown(): void
    {
        $this->cleanup($this->tempDir);
        rmdir($this->tempDir);
    }

    protected function cleanup(string $dir): void
    {
        foreach (glob("{$dir}/*.*") as $file) {
            unlink($file);
        }
    }

    public function getMessage(): Message
    {
        $message = new Message();
        $message->addTo('test@example.com', 'Example Test')
            ->addCc('matthew@example.com')
            ->addBcc('list@example.com', 'Example List')
            ->addFrom([
                'test@example.com',
                'matthew@example.com' => 'Matthew',
            ])
            ->setSender('ralph@example.com', 'Ralph Schindler')
            ->setSubject('Testing Contenir\Mail\Transport\Sendmail')
            ->setBody('This is only a test.');
        $message->getHeaders()
            ->addHeaders([
                'X-Foo-Bar' => 'Matthew',
            ]);
        return $message;
    }

    #[Test]
    public function receivesMailArtifacts(): void
    {
        $message = $this->getMessage();
        $this->transport->send($message);

        static::assertNotNull($this->transport->getLastFile());
        $file = $this->transport->getLastFile();
        $test = file_get_contents($file);

        static::assertSame($message->toString(), $test);
    }

    #[Test]
    public function constructorNoOptions(): void
    {
        $transport = new File();
        static::assertSame(FileOptions::class, $transport->getOptions()::class);
    }
}
