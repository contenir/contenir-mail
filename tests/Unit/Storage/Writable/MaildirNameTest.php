<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage\Writable;

use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Flag;
use Contenir\Mail\Storage\Writable\Maildir;
use Contenir\Mail\Storage\Writable\MaildirName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function explode;
use function time;

#[CoversClass(MaildirName::class)]
#[Group('unit')]
final class MaildirNameTest extends TestCase
{
    /**
     * Maildir unique names: the host cannot add a path or an info or size field.
     */
    #[Test]
    public function escapesHostInUniqueName(): void
    {
        static::assertMatchesRegularExpression(
            '/^\d+\.M\d{6}P\d+Q[0-9a-f]{16}\.a\\\\057b\\\\072c\\\\054d\\\\134e$/D',
            MaildirName::unique('a/b:c,d\\e'),
        );
    }

    #[Test]
    public function startsUniqueNameWithTheTime(): void
    {
        $before  = time();
        $seconds = (int) explode('.', MaildirName::unique())[0];

        static::assertTrue($seconds >= $before && $seconds <= time());
    }

    #[Test]
    public function refusesKeywordWithTrailingLineBreak(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown flag');

        MaildirName::info(["a\n"]);
    }

    #[Test]
    public function refusesLongerKeywordEndingInALetter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown flag');

        MaildirName::info(['Xa']);
    }

    #[Test]
    public function makesUniqueNames(): void
    {
        static::assertNotSame(MaildirName::unique(), MaildirName::unique());
    }

    #[Test]
    public function writesInfoInAsciiOrder(): void
    {
        static::assertSame(
            '2,DFPRSTab',
            MaildirName::info([
                'b',
                Flag::Deleted,
                Flag::Seen,
                'a',
                Flag::Answered,
                Flag::Passed,
                Flag::Flagged,
                Flag::Draft,
            ])[0],
        );
    }

    #[Test]
    public function writesEachFlagOnce(): void
    {
        static::assertSame([Flag::Seen], MaildirName::info([Flag::Seen, '\Seen'])[1]);
    }

    #[Test]
    public function writesEmptyInfoWithoutFlags(): void
    {
        static::assertSame('2,', MaildirName::info([])[0]);
    }
}
