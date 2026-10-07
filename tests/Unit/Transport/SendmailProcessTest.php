<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Tests\Trait\UsesTemporaryDirectoryTrait;
use Contenir\Mail\Transport\Exception\RuntimeException;
use Contenir\Mail\Transport\SendmailProcess;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function json_decode;
use function preg_quote;
use function sprintf;
use function str_repeat;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

#[CoversClass(SendmailProcess::class)]
#[Group('unit')]
final class SendmailProcessTest extends TestCase
{
    use UsesTemporaryDirectoryTrait;

    private string $record = '';

    #[Override]
    protected function setUp(): void
    {
        $this->record = $this->setUpTemporaryDirectory() . DIRECTORY_SEPARATOR . 'record.json';
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * No shell is involved, so quotes, spaces and "$(...)" reach the program as they are.
     */
    #[Test]
    public function passesEachArgumentUnchanged(): void
    {
        $arguments = ['a b', '$(id)', "'quoted'", '"double"', '`id`', ';', '-X/tmp/log'];

        SendmailProcess::run($this->command('ok', ...$arguments), 'x');

        static::assertSame($arguments, $this->recorded()['argv']);
    }

    #[DataProvider('inputProvider')]
    #[Test]
    public function writesInputToStandardInput(string $input): void
    {
        SendmailProcess::run($this->command('ok'), $input);

        static::assertSame($input, $this->recorded()['stdin']);
    }

    #[Test]
    public function acceptsOutputOnStandardErrorWhenProgramSucceeds(): void
    {
        SendmailProcess::run($this->command('warn'), 'x');

        static::assertSame('x', $this->recorded()['stdin']);
    }

    /**
     * The message is compared whole, so output that is not trimmed or comes from the wrong stream fails it.
     */
    #[DataProvider('failureProvider')]
    #[Test]
    public function reportsExitStatusAndOutputOfFailedProgram(string $mode, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches(sprintf(
            '/^Sendmail "%s"%s$/D',
            preg_quote(PHP_BINARY, delimiter: '/'),
            preg_quote($expected, delimiter: '/'),
        ));

        SendmailProcess::run($this->command($mode), 'x');
    }

    /**
     * Depending on the platform, the program fails to start or exits with status 127.
     */
    #[Test]
    public function reportsProgramThatCannotRun(): void
    {
        $missing = dirname($this->record) . DIRECTORY_SEPARATOR . 'missing-sendmail';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sendmail "' . preg_quote($missing, delimiter: '/') . '"/i');

        SendmailProcess::run([$missing, '-oi'], 'x');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function inputProvider(): array
    {
        return [
            'message'          => ["To: a@example.com\n\nLine 1\n.\nLine 3\n"],
            'empty'            => [''],
            'larger than pipe' => [str_repeat("A line of the message body.\n", times: 40_000)],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'standard error'  => ['fail', ' failed with exit status 75: sendmail: cannot write the queue file'],
            'standard output' => ['fail-stdout', ' failed with exit status 1: sendmail: recipient refused'],
            'no output'       => ['fail-silent', ' failed with exit status 1: no output'],
        ];
    }

    /**
     * @return non-empty-list<string>
     */
    private function command(string $mode, string ...$arguments): array
    {
        return [PHP_BINARY, dirname(__DIR__) . '/TestAsset/fake-sendmail.php', $this->record, $mode, ...$arguments];
    }

    /**
     * @return array{argv: list<string>, stdin: string}
     */
    private function recorded(): array
    {
        /** @var array{argv: list<string>, stdin: string} */
        return json_decode((string) file_get_contents($this->record), associative: true, flags: JSON_THROW_ON_ERROR);
    }
}
