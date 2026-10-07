<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Writable;

use Contenir\Mail\Storage\Exception\RuntimeException;
use Contenir\Mail\Storage\RawMessage;
use Contenir\Mail\Storage\Writable\MaildirDelivery;
use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Tests\Unit\Storage\TestAsset\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function fopen;
use function is_file;
use function mkdir;

#[CoversClass(MaildirDelivery::class)]
#[CoversClass(RawMessage::class)]
#[Group('unit')]
final class MaildirDeliveryTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $root;

    private string $directory;

    protected function setUp(): void
    {
        $this->root = $this->setUpTemporaryDirectory();
        mkdir("{$this->root}/box");
        $this->directory = Fixtures::maildir("{$this->root}/box");
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    #[Test]
    public function reportsTemporaryFileThatCannotBeCreated(): void
    {
        mkdir("{$this->directory}/tmp", permissions: 0o500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot create a temporary file in {$this->directory}/tmp");

        MaildirDelivery::writeTemporary("{$this->directory}/tmp", 'x', 0o600);
    }

    #[Test]
    public function refusesToDeliverOverExistingFile(): void
    {
        file_put_contents("{$this->root}/a", data: 'a');
        file_put_contents("{$this->root}/b", data: 'b');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot link the message file into the folder');

        MaildirDelivery::deliver("{$this->root}/a", "{$this->root}/b");
    }

    #[Test]
    public function removesTemporaryFileEvenWhenDeliveryFails(): void
    {
        file_put_contents("{$this->root}/a", data: 'a');
        file_put_contents("{$this->root}/b", data: 'b');
        try {
            MaildirDelivery::deliver("{$this->root}/a", "{$this->root}/b");
        } catch (RuntimeException) {
            static::assertFalse(is_file("{$this->root}/a"));

            return;
        }

        static::fail('Delivery onto an existing file must fail');
    }

    #[Test]
    public function reportsMessageThatCannotBeWritten(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write the message');

        RawMessage::write('x', fopen('php://memory', mode: 'r'));
    }
}
