<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage\Flag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Flag::class)]
#[Group('unit')]
final class FlagTest extends TestCase
{
    #[DataProvider('imapProvider')]
    #[Test]
    public function readsImapFlag(string $imap, Flag|string $expected): void
    {
        static::assertSame($expected, Flag::fromImap($imap));
    }

    #[Test]
    public function keepsCaseAsItIs(): void
    {
        static::assertSame(Flag::Draft, Flag::normalize(Flag::Draft));
    }

    #[Test]
    public function normalizesImapName(): void
    {
        static::assertSame(Flag::Draft, Flag::normalize('\draft'));
    }

    #[DataProvider('normalizeProvider')]
    #[IgnoreDeprecations]
    #[Test]
    public function normalizesAlikeUnderTheBritishSpelling(Flag|string $flag, Flag|string $expected): void
    {
        static::assertSame($expected, Flag::normalise($flag));
    }

    #[IgnoreDeprecations]
    #[RequiresPhp('>= 8.4')]
    #[Test]
    public function deprecatesTheBritishSpelling(): void
    {
        $this->expectUserDeprecationMessage(
            'Method Contenir\Mail\Storage\Flag::normalise() is deprecated since 0.3.0, use Flag::normalize()',
        );

        Flag::normalise('\Seen');
    }

    #[Test]
    public function namesPassedByItsImapKeyword(): void
    {
        static::assertSame(Flag::Passed, Flag::Forwarded);
    }

    /**
     * @return array<string, array{Flag|string, Flag|string}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'case'      => [Flag::Draft, Flag::Draft],
            'imap name' => ['\draft', Flag::Draft],
            'keyword'   => ['$Junk', '$Junk'],
        ];
    }

    #[DataProvider('maildirProvider')]
    #[Test]
    public function readsMaildirLetter(string $letter, Flag|string $expected): void
    {
        static::assertSame($expected, Flag::fromMaildir($letter));
    }

    #[DataProvider('maildirProvider')]
    #[Test]
    public function writesMaildirLetter(string $letter, Flag|string $flag): void
    {
        static::assertSame(
            $flag instanceof Flag ? $letter : null,
            $flag instanceof Flag ? $flag->maildirLetter() : null,
        );
    }

    #[Test]
    public function hasNoMaildirLetterForRecent(): void
    {
        static::assertNull(Flag::Recent->maildirLetter());
    }

    /**
     * @return array<string, array{string, Flag|string}>
     */
    public static function imapProvider(): array
    {
        return [
            'seen'                => ['\Seen', Flag::Seen],
            'lower case'          => ['\seen', Flag::Seen],
            'answered'            => ['\Answered', Flag::Answered],
            'flagged'             => ['\Flagged', Flag::Flagged],
            'deleted'             => ['\Deleted', Flag::Deleted],
            'draft'               => ['\Draft', Flag::Draft],
            'recent'              => ['\Recent', Flag::Recent],
            'forwarded keyword'   => ['$Forwarded', Flag::Passed],
            'laminas passed'      => ['Passed', Flag::Passed],
            'laminas imap passed' => ['\Passed', Flag::Passed],
            'keyword'             => ['$Junk', '$Junk'],
            'unknown system'      => ['\Unseen', '\Unseen'],
        ];
    }

    /**
     * @return array<string, array{string, Flag|string}>
     */
    public static function maildirProvider(): array
    {
        return [
            'draft'   => ['D', Flag::Draft],
            'flagged' => ['F', Flag::Flagged],
            'passed'  => ['P', Flag::Passed],
            'replied' => ['R', Flag::Answered],
            'seen'    => ['S', Flag::Seen],
            'trashed' => ['T', Flag::Deleted],
            'keyword' => ['a', 'a'],
        ];
    }
}
