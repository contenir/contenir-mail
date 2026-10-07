<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Storage;

use Contenir\Mail\Storage\Exception\InvalidArgumentException;
use Contenir\Mail\Storage\Folder;
use Contenir\Mail\Storage\TreeIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;

use function array_keys;
use function array_map;
use function iterator_to_array;

#[CoversClass(Folder::class)]
#[CoversClass(TreeIterator::class)]
#[Group('unit')]
final class FolderTest extends TestCase
{
    private static function tree(): Folder
    {
        return new Folder('/', '/', selectable: false, folders: [
            new Folder('INBOX'),
            new Folder('Archive', 'Archive', selectable: false, folders: [new Folder('2024', 'Archive.2024')]),
        ]);
    }

    #[Test]
    public function usesLocalNameAsGlobalNameByDefault(): void
    {
        static::assertSame('INBOX', (new Folder('INBOX'))->getGlobalName());
    }

    #[Test]
    public function keepsLocalName(): void
    {
        static::assertSame('2024', self::tree()->getFolder('Archive')->getFolder('2024')->getLocalName());
    }

    #[Test]
    public function keepsGlobalName(): void
    {
        static::assertSame('Archive.2024', self::tree()->getFolder('Archive')->getFolder('2024')->getGlobalName());
    }

    #[Test]
    public function writesGlobalNameAsString(): void
    {
        static::assertSame('Archive.2024', (string) self::tree()->getFolder('Archive')->getFolder('2024'));
    }

    #[Test]
    public function isSelectableByDefault(): void
    {
        static::assertTrue((new Folder('INBOX'))->isSelectable());
    }

    #[Test]
    public function knowsWhenItCannotBeSelected(): void
    {
        static::assertFalse(self::tree()->getFolder('Archive')->isSelectable());
    }

    #[Test]
    public function isLeafWithoutSubfolders(): void
    {
        static::assertTrue((new Folder('INBOX'))->isLeaf());
    }

    #[Test]
    public function isNotLeafWithSubfolders(): void
    {
        static::assertFalse(self::tree()->isLeaf());
    }

    #[Test]
    public function knowsItsSubfolders(): void
    {
        static::assertTrue(self::tree()->hasFolder('Archive'));
    }

    #[Test]
    public function knowsWhatIsNotASubfolder(): void
    {
        static::assertFalse(self::tree()->hasFolder('Spam'));
    }

    #[Test]
    public function refusesMissingSubfolder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No subfolder named Spam');

        self::tree()->getFolder('Spam');
    }

    #[Test]
    public function listsSubfoldersByLocalName(): void
    {
        static::assertSame(['INBOX', 'Archive'], array_keys(self::tree()->getFolders()));
    }

    #[Test]
    public function replacesSubfolderOfTheSameName(): void
    {
        $tree = self::tree();
        $tree->addFolder(new Folder('INBOX', 'INBOX', selectable: false));

        static::assertFalse($tree->getFolder('INBOX')->isSelectable());
    }

    #[Test]
    public function removesSubfolder(): void
    {
        $tree = self::tree();
        $tree->removeFolder('INBOX');

        static::assertSame(['Archive'], array_keys($tree->getFolders()));
    }

    #[Test]
    public function walksTheTreeParentFirst(): void
    {
        $walk = new RecursiveIteratorIterator(self::tree(), RecursiveIteratorIterator::SELF_FIRST);

        static::assertSame(
            ['INBOX', 'Archive', 'Archive.2024'],
            array_map(
                static fn(Folder $folder): string => $folder->getGlobalName(),
                iterator_to_array($walk, preserve_keys: false),
            ),
        );
    }
}
