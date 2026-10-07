<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Transport;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Transport\SendmailConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendmailConfig::class)]
#[Group('unit')]
final class SendmailConfigTest extends TestCase
{
    #[Test]
    public function hasNoParametersByDefault(): void
    {
        static::assertSame('', (new SendmailConfig())->toString());
    }

    /**
     * @param string|list<string> $parameters
     */
    #[DataProvider('parameterProvider')]
    #[Test]
    public function splitsParametersIntoWords(string|array $parameters, string $expected): void
    {
        static::assertSame($expected, (new SendmailConfig($parameters))->toString());
    }

    #[Test]
    public function usesMailFunctionByDefault(): void
    {
        static::assertNull((new SendmailConfig())->path);
    }

    #[Test]
    public function readsPathFromSettings(): void
    {
        static::assertSame(
            '/usr/sbin/sendmail',
            SendmailConfig::fromIterable(['path' => '/usr/sbin/sendmail', 'parameters' => '-R hdrs'])->path,
        );
    }

    #[DataProvider('invalidPathProvider')]
    #[Test]
    public function rejectsPathThatIsNotProgramPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The sendmail path must be a program path, not empty and without control characters',
        );

        new SendmailConfig(path: $path);
    }

    #[Test]
    public function readsParameterStringFromSettings(): void
    {
        static::assertSame(['-R', 'hdrs'], SendmailConfig::fromIterable(['parameters' => '-R hdrs'])->parameters);
    }

    #[Test]
    public function readsParameterListFromSettings(): void
    {
        static::assertSame(['-R', 'hdrs'], SendmailConfig::fromIterable(['parameters' => ['-R', 'hdrs']])->parameters);
    }

    #[Test]
    public function rejectsParameterListOfNonStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('option "parameters" must be a string or a list of strings, got int');

        SendmailConfig::fromIterable(['parameters' => [1]]);
    }

    #[Test]
    public function rejectsUnknownSetting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown option "sendmail_path"');

        SendmailConfig::fromIterable(['sendmail_path' => '/usr/sbin/sendmail']);
    }

    /**
     * mail() passes the parameters through a shell, so only plain words are accepted.
     */
    #[DataProvider('unsafeParameterProvider')]
    #[Test]
    public function rejectsParameterUnsafeForShell(string $parameter, string $word): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Sendmail parameter \"{$word}\" may only contain letters, digits and");

        new SendmailConfig($parameter);
    }

    /**
     * @return array<string, array{string|list<string>, string}>
     */
    public static function parameterProvider(): array
    {
        return [
            'string'             => ['-R hdrs', '-R hdrs'],
            'extra spaces'       => ['  -R   hdrs ', '-R hdrs'],
            'tabs'               => ["-R\thdrs", '-R hdrs'],
            'list'               => [[' -R', 'hdrs '], '-R hdrs'],
            'sender and options' => [
                '-oi -fbounces+x=y@example.com -X/var/log/mail.log',
                '-oi -fbounces+x=y@example.com -X/var/log/mail.log',
            ],
            'percent and colon'  => ['-O DeliveryMode=b -N%s:x,y', '-O DeliveryMode=b -N%s:x,y'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPathProvider(): array
    {
        return [
            'empty'           => [''],
            'NUL'             => ["/usr/sbin/sendmail\0x"],
            'line break'      => ["/usr/sbin/sendmail\n"],
            'DEL'             => ["/usr/sbin/send\x7Fmail"],
            'leading control' => ["\x01/usr/sbin/sendmail"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unsafeParameterProvider(): array
    {
        return [
            'single quotes' => ["-f'x@example.com'", "-f'x@example.com'"],
            'double quotes' => ['-f"x@example.com"', '-f"x@example.com"'],
            'command subst' => ['-f$(id)@example.com', '-f$(id)@example.com'],
            'backticks'     => ['-f`id`', '-f`id`'],
            'semicolon'     => ['-oi;id', '-oi;id'],
            'line break'    => ["-oi\nid", "-oi\nid"],
            'redirect'      => ['-oi>x', '-oi>x'],
            'backslash'     => ['-oi\\x', '-oi\\x'],
        ];
    }
}
