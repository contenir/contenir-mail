<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Mime;

use Contenir\Mail\Mime\HtmlToText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HtmlToText::class)]
#[Group('unit')]
final class HtmlToTextTest extends TestCase
{
    #[DataProvider('htmlProvider')]
    #[Test]
    public function readsTheHtmlAsText(string $html, string $expected): void
    {
        static::assertSame($expected, (new HtmlToText())->convert($html));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'headings and paragraphs on lines of their own'              => [
                '<h1>Reset your password</h1><p>Dear Sam,</p><p>Visit the link below.</p>',
                "Reset your password\n\nDear Sam,\n\nVisit the link below.",
            ],
            'every block element separates paragraphs'                   => [
                '<div>a</div><h2>b</h2><h6>c</h6><blockquote>d</blockquote><pre>e</pre><section>f</section>'
                    . '<article>g</article><header>h</header><footer>i</footer><main>j</main><nav>k</nav>'
                    . '<aside>l</aside><address>m</address><figure>n<figcaption>o</figcaption></figure>'
                    . '<center>p</center><dl><dt>q</dt><dd>r</dd></dl><table><caption>s</caption></table>t<hr>u',
                "a\n\nb\n\nc\n\nd\n\ne\n\nf\n\ng\n\nh\n\ni\n\nj\n\nk\n\nl\n\nm\n\nn\n\no\n\np\n\nq\n\nr\n\ns\n\nt\n\nu",
            ],
            'inline markup is dropped from the words'                    => [
                '<p>A <strong>bold</strong>, <em>sure</em> <span style="color: red">claim</span></p>',
                'A bold, sure claim',
            ],
            'a link keeps its words and gains its address'               => [
                '<p>See <a href="https://x.test/booking/1">your booking</a>.</p>',
                'See your booking (https://x.test/booking/1).',
            ],
            'a link that is its own address appears once'                => [
                '<p><a href="https://x.test/reset">https://x.test/reset</a></p>',
                'https://x.test/reset',
            ],
            'a link that is its own address appears once however spaced' => [
                '<p><a href=" https://x.test/reset "> https://x.test/reset </a></p>',
                'https://x.test/reset',
            ],
            'a mail link to the address shown appears once'              => [
                '<p>Write to <a href="mailto:events@x.test">events@x.test</a></p>',
                'Write to events@x.test',
            ],
            'a mail link to another address gains it'                    => [
                '<p>Write to <a href="mailto:events@x.test">the events team</a></p>',
                'Write to the events team (mailto:events@x.test)',
            ],
            'a link within the page is its words'                        => [
                '<p><a href="#details">See the details</a></p>',
                'See the details',
            ],
            'a link without an address is its words'                     => [
                '<p><a name="details">Details</a></p>',
                'Details',
            ],
            'a link without words is its address'                        => [
                '<p><a href="https://x.test/r"></a></p>',
                'https://x.test/r',
            ],
            'a link without words or address is nothing'                 => [
                '<p>before <a href=""></a> after</p>',
                'before after',
            ],
            'an unquoted address is read'                                => [
                '<p><a href=https://x.test/u>Go</a></p>',
                'Go (https://x.test/u)',
            ],
            'a single-quoted address is read'                            => [
                "<p><a href='https://x.test/s'>Go</a></p>",
                'Go (https://x.test/s)',
            ],
            'an address has its entities decoded'                        => [
                '<p><a href="https://x.test/?a=1&amp;b=2">Go</a></p>',
                'Go (https://x.test/?a=1&b=2)',
            ],
            'an address is read among other attributes'                  => [
                '<p><a class="button" href="https://x.test/b" target="_blank">Go</a></p>',
                'Go (https://x.test/b)',
            ],
            'a link around an image reads as the alt text'               => [
                '<p><a href="https://x.test"><img src="logo.png" alt="Logo"></a></p>',
                'Logo (https://x.test)',
            ],
            'markup inside a link is dropped from its words'             => [
                '<p><a href="https://x.test/b"><strong>Book</strong> now</a></p>',
                'Book now (https://x.test/b)',
            ],
            'an image reads as its alt text'                             => [
                '<p><img src="logo.png" alt="Two de Force"> and <img src="pixel.gif"></p>',
                'Two de Force and',
            ],
            'an alt text has its entities decoded and is trimmed'        => [
                '<p><img src="logo.png" alt=" Tom &amp; Jerry "></p>',
                'Tom & Jerry',
            ],
            'list items are dashed lines'                                => [
                '<ul><li>One</li><li>Two</li></ul><ol><li>Three</li></ol><p>After</p>',
                "- One\n- Two\n\n- Three\n\nAfter",
            ],
            'table cells sit side by side, a row per line'               => [
                '<table><tr><td>Subtotal</td><td>$155.00</td></tr><tr><th>Total</th><td>$155.00</td></tr></table>',
                "Subtotal  \$155.00\nTotal  \$155.00",
            ],
            'an empty cell leaves one gap'                               => [
                '<table><tr><td>a</td><td></td><td>b</td></tr></table>',
                'a  b',
            ],
            'a cell that holds a paragraph breaks the row'               => [
                '<table><tr><td><p>Hello</p></td></tr></table>',
                'Hello',
            ],
            'line breaks break lines'                                    => [
                '<p>Line one<br>Line two<br />Line three<br class="x">Line four</p>',
                "Line one\nLine two\nLine three\nLine four",
            ],
            'the head, styles, scripts and comments are dropped'         => [
                "<html><HEAD><title>x</title><meta charset=\"utf-8\"></HEAD><body>\n"
                    . "<style type=\"text/css\">\np { color: red }\n</style>\n"
                    . "<script>\nvar a = 1;\n</script>\n"
                    . "<p>Hello</p><!-- a\ncomment --></body></html>",
                'Hello',
            ],
            'the preheader is dropped'                                   => [
                '<span class="preheader">Hidden summary</span><p>Hello</p>',
                'Hello',
            ],
            'the preheader is dropped whatever its element and case'     => [
                '<DIV CLASS="hide preheader mobile">Hidden summary</DIV><p>Hello</p>',
                'Hello',
            ],
            'a preheader that spans lines is dropped'                    => [
                "<span class = 'preheader'>Hidden\nsummary</span><p>Hello</p>",
                'Hello',
            ],
            'a class that only contains the word is kept'                => [
                '<p class="preheader-text">Kept</p>',
                'Kept',
            ],
            'the word in another attribute is kept'                      => [
                '<p data-class="preheader">Kept</p>',
                'Kept',
            ],
            'tags are read whatever their case'                          => [
                '<P><IMG ALT="Logo"><BR><A HREF="https://x.test/u">Go</A></P><UL><LI>One</LI></UL>'
                    . '<TABLE><TR><TD>a</TD><TD>b</TD></TR></TABLE>',
                "Logo\nGo (https://x.test/u)\n\n- One\n\na  b",
            ],
            'entities are decoded and source whitespace collapsed'       => [
                "<p>Tom &amp; Jerry&nbsp;&mdash; &ldquo;quoted&rdquo; &quot;it&apos;s&quot;</p>\n"
                    . "    <p>   spaced\n   out </p>",
                "Tom & Jerry — “quoted” \"it's\"\n\nspaced out",
            ],
            'non-breaking spaces become spaces'                          => [
                "<p>a&nbsp;&nbsp;b \u{00A0} c</p>",
                'a b c',
            ],
            'runs of blank lines are squeezed to one and lines trimmed'  => [
                "<div><div> <p>\tOne\t</p> </div></div>\n<div> </div>\n<p>Two</p>",
                "One\n\nTwo",
            ],
            'a bracket in the words does not start a tag'                => [
                '<p>1 &lt; 2 &amp;&amp; 3 &gt; 2</p>',
                '1 < 2 && 3 > 2',
            ],
            'plain words pass through'                                   => [
                'Just words',
                'Just words',
            ],
            'nothing reads as nothing'                                   => [
                '',
                '',
            ],
        ];
    }

    #[Test]
    public function keepsEveryElementWhenNoClassIsDropped(): void
    {
        static::assertSame(
            "Shown summary\n\nHello",
            (new HtmlToText(droppedClasses: []))->convert('<span class="preheader">Shown summary</span><p>Hello</p>'),
        );
    }

    #[Test]
    public function dropsTheElementsOfTheClassesGiven(): void
    {
        $converter = new HtmlToText(droppedClasses: ['legal.note', 'hidden']);

        static::assertSame(
            "Shown\n\nKept\n\nHello",
            $converter->convert(
                '<span class="preheader">Shown</span>'
                    . '<p class="legalXnote">Kept</p>'
                    . '<p class="legal.note">Dropped</p>'
                    . '<div class="hidden">Dropped too</div>'
                    . '<p>Hello</p>',
            ),
        );
    }
}
