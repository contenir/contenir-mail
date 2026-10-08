<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage\SpecialUse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SpecialUse::class)]
#[Group('unit')]
final class SpecialUseTest extends TestCase
{
    #[Test]
    #[DataProvider('attributeProvider')]
    public function namesTheUseOfAnAttributeWithoutRegardToCase(string $attribute, ?SpecialUse $expected): void
    {
        static::assertSame($expected, SpecialUse::fromAttribute($attribute));
    }

    /**
     * @return array<string, array{string, SpecialUse|null}>
     */
    public static function attributeProvider(): array
    {
        return [
            'all'                   => ['\All', SpecialUse::All],
            'archive'               => ['\Archive', SpecialUse::Archive],
            'drafts'                => ['\Drafts', SpecialUse::Drafts],
            'flagged'               => ['\Flagged', SpecialUse::Flagged],
            'junk'                  => ['\Junk', SpecialUse::Junk],
            'sent in lower case'    => ['\sent', SpecialUse::Sent],
            'trash in upper case'   => ['\TRASH', SpecialUse::Trash],
            'without the backslash' => ['Sent', null],
            'another attribute'     => ['\HasNoChildren', null],
            'a longer name'         => ['\Sentinel', null],
        ];
    }

    /**
     * @param list<mixed> $attributes
     */
    #[Test]
    #[DataProvider('attributesProvider')]
    public function namesTheUseOfTheFirstSpecialUseAttribute(array $attributes, ?SpecialUse $expected): void
    {
        static::assertSame($expected, SpecialUse::fromAttributes($attributes));
    }

    /**
     * @return array<string, array{list<mixed>, SpecialUse|null}>
     */
    public static function attributesProvider(): array
    {
        return [
            'none'                    => [[], null],
            'among other attributes'  => [['\HasNoChildren', '\Junk', '\Marked'], SpecialUse::Junk],
            'the first of two'        => [['\Archive', '\All'], SpecialUse::Archive],
            'after an attribute list' => [[['\Sent'], '\Drafts'], SpecialUse::Drafts],
            'only other attributes'   => [['\Noselect', '\HasChildren'], null],
        ];
    }
}
