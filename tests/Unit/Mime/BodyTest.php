<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Body;
use Contenir\Mail\Mime\Exception\InvalidArgumentException;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Contenir\Mail\Mime\Multipart;
use Contenir\Mail\Mime\Part;
use Contenir\Mail\Mime\PartInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[CoversClass(Body::class)]
#[Group('unit')]
final class BodyTest extends TestCase
{
    #[Test]
    public function hasNoPartWhenEmpty(): void
    {
        static::assertNull((new Body())->toPart());
    }

    #[Test]
    public function isTheTextAloneWhenThereIsOnlyText(): void
    {
        $text = Part::text('Hello');

        static::assertSame(
            $text,
            (new Body())->withText($text)
                ->toPart(),
        );
    }

    #[Test]
    public function isTheHtmlAloneWhenThereIsOnlyHtml(): void
    {
        $html = Part::html('<p>Hello</p>');

        static::assertSame(
            $html,
            (new Body())->withHtml($html)
                ->toPart(),
        );
    }

    #[Test]
    public function offersTextAndHtmlAsAlternatives(): void
    {
        $text = Part::text('Hello');
        $html = Part::html('<p>Hello</p>');

        static::assertSame(
            ['multipart/alternative' => [$text, $html]],
            self::shape(
                (new Body())->withHtml($html)
                    ->withText($text)
                    ->toPart(),
            ),
        );
    }

    #[Test]
    public function relatesHtmlToItsEmbeddedResources(): void
    {
        $html = Part::html('<img src="cid:logo"><img src="cid:icon">');
        $logo = Attachment::inline('logo', id: 'logo', type: 'image/png');
        $icon = Attachment::inline('icon', id: 'icon', type: 'image/gif');

        static::assertSame(
            ['multipart/related' => [$html, $logo, $icon]],
            self::shape(
                (new Body())->withEmbedded($logo)
                    ->withHtml($html)
                    ->withEmbedded($icon)
                    ->toPart(),
            ),
        );
    }

    #[Test]
    public function offersTextAsAnAlternativeToHtmlWithItsResources(): void
    {
        $text = Part::text('Hello');
        $html = Part::html('<img src="cid:logo">');
        $logo = Attachment::inline('logo', id: 'logo', type: 'image/png');

        static::assertSame(
            ['multipart/alternative' => [$text, ['multipart/related' => [$html, $logo]]]],
            self::shape(
                (new Body())->withText($text)
                    ->withHtml($html)
                    ->withEmbedded($logo)
                    ->toPart(),
            ),
        );
    }

    /**
     * @param list<string> $content
     */
    #[Test]
    #[DataProvider('bodyWithoutHtmlProvider')]
    public function rejectsEmbeddedResourcesWithoutHtml(array $content): void
    {
        $body = new Body();
        foreach ($content as $kind) {
            $body = match ($kind) {
                'text'  => $body->withText(Part::text('Hello')),
                default => $body->withAttachment(Attachment::fromString('a', filename: 'a.txt')),
            };
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Embedded resources need an HTML body to refer to them');

        $body->withEmbedded(Attachment::inline('logo', id: 'logo', type: 'image/png'))->toPart();
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function bodyWithoutHtmlProvider(): array
    {
        return [
            'nothing else'    => [[]],
            'text'            => [['text']],
            'text attachment' => [['text', 'attachment']],
        ];
    }

    #[Test]
    public function mixesAttachmentsInOrderWhenThereIsNoContent(): void
    {
        $first  = Attachment::fromString('a', filename: 'a.txt');
        $second = Attachment::fromString('b', filename: 'b.txt');

        static::assertSame(
            ['multipart/mixed' => [$first, $second]],
            self::shape(
                (new Body())->withAttachment($first)
                    ->withAttachment($second)
                    ->toPart(),
            ),
        );
    }

    #[Test]
    public function mixesTextBeforeItsAttachments(): void
    {
        $text       = Part::text('Hello');
        $attachment = Attachment::fromString('a', filename: 'a.txt');

        static::assertSame(
            ['multipart/mixed' => [$text, $attachment]],
            self::shape(
                (new Body())->withAttachment($attachment)
                    ->withText($text)
                    ->toPart(),
            ),
        );
    }

    #[Test]
    public function mixesTheWholeTreeBeforeItsAttachments(): void
    {
        $text   = Part::text('Hello');
        $html   = Part::html('<img src="cid:logo">');
        $logo   = Attachment::inline('logo', id: 'logo', type: 'image/png');
        $first  = Attachment::fromString('a', filename: 'a.txt');
        $second = Attachment::fromString('b', filename: 'b.txt');
        $body   = (new Body())->withAttachment($first)
            ->withEmbedded($logo)
            ->withHtml($html)
            ->withAttachment($second)
            ->withText($text);

        static::assertSame(
            [
                'multipart/mixed' => [
                    ['multipart/alternative' => [$text, ['multipart/related' => [$html, $logo]]]],
                    $first,
                    $second,
                ],
            ],
            self::shape($body->toPart()),
        );
    }

    #[Test]
    public function buildsTheSameTreeFromConstructorArguments(): void
    {
        $text       = Part::text('Hello');
        $html       = Part::html('<img src="cid:logo">');
        $logo       = Attachment::inline('logo', id: 'logo', type: 'image/png');
        $attachment = Attachment::fromString('a', filename: 'a.txt');

        static::assertSame(
            [
                'multipart/mixed' => [
                    ['multipart/alternative' => [$text, ['multipart/related' => [$html, $logo]]]],
                    $attachment,
                ],
            ],
            self::shape((new Body($text, $html, [$logo], [$attachment]))->toPart()),
        );
    }

    #[Test]
    public function replacesTheTextWhenGivenAgain(): void
    {
        $text = Part::text('Second');

        static::assertSame(
            $text,
            (new Body())->withText(Part::text('First'))
                ->withText($text)
                ->toPart(),
        );
    }

    #[Test]
    public function replacesTheHtmlWhenGivenAgain(): void
    {
        $html = Part::html('<p>Second</p>');

        static::assertSame(
            $html,
            (new Body())->withHtml(Part::html('<p>First</p>'))
                ->withHtml($html)
                ->toPart(),
        );
    }

    #[Test]
    public function leavesTheOriginalUnchanged(): void
    {
        $body = new Body();
        $body->withText(Part::text('Hello'));
        $body->withHtml(Part::html('<p>Hello</p>'));
        $body->withEmbedded(Attachment::inline('logo', id: 'logo', type: 'image/png'));
        $body->withAttachment(Attachment::fromString('a', filename: 'a.txt'));

        static::assertNull($body->toPart());
    }

    #[Test]
    public function generatesNewBoundariesEachTime(): void
    {
        $body = (new Body())->withText(Part::text('Hello'))
            ->withHtml(Part::html('<p>Hello</p>'));
        $first = self::boundaryOf($body->toPart());

        static::assertNotSame($first, self::boundaryOf($body->toPart()));
    }

    #[Test]
    public function rejectsAnEmbeddedResourceWithoutAContentId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An embedded part needs a Content-ID for the HTML to refer to');

        (new Body())->withEmbedded(Attachment::fromString('a', filename: 'a.png', type: 'image/png'));
    }

    /**
     * Leaves as themselves and multiparts as [content type => children], so a whole tree compares in one assertion.
     *
     * @return PartInterface|array<string, list<mixed>>|null
     */
    private static function shape(?PartInterface $part): PartInterface|array|null
    {
        if (! $part instanceof Multipart) {
            return $part;
        }

        return [$part->getType()->contentType() => array_map(self::shape(...), $part->getParts())];
    }

    private static function boundaryOf(?PartInterface $part): ?string
    {
        return $part instanceof Multipart ? $part->getBoundary() : null;
    }
}
