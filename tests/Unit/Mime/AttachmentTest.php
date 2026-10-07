<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function chmod;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_readable;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

#[CoversClass(Attachment::class)]
#[Group('unit')]
final class AttachmentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/contenir-mail-attachment-' . bin2hex(random_bytes(8));
        if (! mkdir($this->directory, permissions: 0o700)) {
            throw new RuntimeException("Cannot create {$this->directory}");
        }
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }

        $paths = glob("{$this->directory}/*");
        foreach (false === $paths ? [] : $paths as $path) {
            chmod($path, permissions: 0o600);
            unlink($path);
        }

        rmdir($this->directory);
    }

    #[Test]
    public function attachesAFileWithItsNameAndDetectedType(): void
    {
        $path = $this->file('report.txt', 'Quarterly report');

        static::assertSame(
            "Content-Type: text/plain\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"report.txt\"\r\n",
            Attachment::fromPath($path)->getHeaders()->toString(),
        );
    }

    #[Test]
    public function attachesAFileUnderAnotherNameAndType(): void
    {
        $path = $this->file('report.txt', 'Quarterly report');

        static::assertSame(
            "Content-Type: application/x-report\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"q3.rpt\"\r\n",
            Attachment::fromPath($path, filename: 'q3.rpt', type: 'application/x-report')->getHeaders()->toString(),
        );
    }

    #[DataProvider('detectedTypeProvider')]
    #[Test]
    public function detectsTheTypeFromTheFileContents(string $content, string $expected): void
    {
        $path = $this->file('unnamed', $content);

        static::assertSame($expected, Attachment::fromPath($path)->getType());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function detectedTypeProvider(): array
    {
        return [
            'plain text' => ['Hello, world', 'text/plain'],
            'png'        => [
                "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00",
                'image/png',
            ],
            'empty'      => ['', 'application/x-empty'],
        ];
    }

    #[Test]
    public function readsTheFileAsAStream(): void
    {
        $path = $this->file('data.bin', str_repeat('abc', times: 37));

        static::assertSame(
            str_repeat('YWJj', times: 18) . "\r\n" . str_repeat('YWJj', times: 18) . "\r\n" . 'YWJj',
            Attachment::fromPath($path)->getEncodedContent(),
        );
    }

    #[Test]
    public function readsTheFileOnlyWhenTheContentIsWanted(): void
    {
        $path = $this->file('data.txt', 'before');
        $part = Attachment::fromPath($path);
        file_put_contents($path, data: 'after');

        static::assertSame('after', $part->getContent());
    }

    #[Test]
    public function rejectsAMissingFile(): void
    {
        $path = "{$this->directory}/missing.txt";

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot read the file \"{$path}\"");

        Attachment::fromPath($path);
    }

    #[Test]
    public function rejectsADirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot read the file \"{$this->directory}\"");

        Attachment::fromPath($this->directory);
    }

    #[Test]
    public function rejectsAnUnreadableFile(): void
    {
        $path = $this->file('secret.txt', 'secret');
        chmod($path, permissions: 0o200);
        if (is_readable($path)) {
            static::markTestSkipped('File permissions are not enforced for this user');
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Cannot read the file \"{$path}\"");

        Attachment::fromPath($path);
    }

    #[Test]
    public function attachesAStringAsABase64File(): void
    {
        static::assertSame(
            "Content-Type: application/pdf\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"invoice.pdf\"\r\n",
            Attachment::fromString('%PDF-1.4', filename: 'invoice.pdf', type: 'application/pdf')
                ->getHeaders()
                ->toString(),
        );
    }

    #[Test]
    public function attachesAStringAsAnOctetStreamByDefault(): void
    {
        static::assertSame('application/octet-stream', Attachment::fromString('data', filename: 'data.bin')->getType());
    }

    #[Test]
    public function encodesAStringAttachmentAsBase64(): void
    {
        static::assertSame('SGVsbG8=', Attachment::fromString('Hello', filename: 'hello.txt')->getEncodedContent());
    }

    #[Test]
    public function embedsAnInlineResourceWithAContentId(): void
    {
        static::assertSame(
            "Content-Type: image/png\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-ID: <logo@example.com>\r\n"
                . "Content-Disposition: inline\r\n",
            Attachment::inline('png', id: 'logo@example.com', type: 'image/png')->getHeaders()->toString(),
        );
    }

    #[Test]
    public function embedsAnInlineResourceWithAFilename(): void
    {
        static::assertSame(
            "Content-Type: image/png\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-ID: <logo@example.com>\r\n"
                . "Content-Disposition: inline; filename=\"logo.png\"\r\n",
            Attachment::inline('png', id: 'logo@example.com', type: 'image/png', filename: 'logo.png')
                ->getHeaders()
                ->toString(),
        );
    }

    #[Test]
    public function keepsInlineContentAsGiven(): void
    {
        static::assertSame('png', Attachment::inline('png', id: 'logo', type: 'image/png')->getContent());
    }

    private function file(string $name, string $content): string
    {
        $path = "{$this->directory}/{$name}";
        if (false === file_put_contents($path, $content)) {
            throw new RuntimeException("Cannot write {$path}");
        }

        return $path;
    }
}
