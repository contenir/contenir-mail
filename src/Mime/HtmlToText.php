<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use function html_entity_decode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strip_tags;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * The plain-text reading of an HTML body, for the text/plain half of a multipart/alternative message.
 *
 * Headings, paragraphs and list items go on lines of their own, table cells
 * side by side, a link's address in brackets after its words unless the words
 * are the address, and an image's alt text in its place. The head, styles,
 * scripts and comments are dropped, as is every element with one of the
 * dropped classes: by default "preheader", the hidden preview line that
 * email layouts carry.
 *
 * Tags are read with regular expressions rather than parsed, as the HTML is
 * the application's own, so an element with a dropped class ends at the first
 * closing tag of its name.
 *
 * @api
 */
final readonly class HtmlToText
{
    /** Elements whose content is not shown, with everything inside them */
    private const string HIDDEN_ELEMENTS = '~<(head|style|script)\b[^>]*>.*?</\1\s*>~is';

    private const string COMMENT = '~<!--.*?-->~s';

    /**
     * An element whose class attribute has the class given, as a whole word, with
     * everything inside it up to the first closing tag of its name.
     */
    private const string DROPPED_ELEMENT =
        '~<([a-z][a-z0-9]*)\b[^>]*\sclass\s*=\s*(["\'])[^"\'>]*'
            . '(?<=[\s"\'])%s(?=[\s"\'])[^"\'>]*\2[^>]*>.*?</\1\s*>~is';

    /** Elements that start and end a paragraph of their own */
    private const string BLOCK_ELEMENTS =
        '~</?(?:p|div|h[1-6]|blockquote|pre|ul|ol|dl|dd|dt|table|caption|section'
            . '|article|header|footer|main|nav|aside|address|figure|figcaption|center|hr)\b[^>]*>~i';

    /** Marks the end of a table cell until the line is laid out, as source tabs are collapsed before it is used */
    private const string CELL_END = "\t";

    private const string CELL_GAP = '  ';

    /**
     * @param list<string> $droppedClasses Classes whose elements are left out, with everything inside them
     */
    public function __construct(
        private array $droppedClasses = ['preheader'],
    ) {}

    public function convert(string $html): string
    {
        $text = self::replace(self::HIDDEN_ELEMENTS, '', $html);
        $text = $this->withoutDroppedElements($text);
        $text = self::replace(self::COMMENT, '', $text);
        $text = self::replace('~\s+~', ' ', $text);
        $text = self::replaceWith('~<img\b[^>]*>~i', self::altText(...), $text);
        $text = self::replaceWith('~<a\b(?<attributes>[^>]*)>(?<words>.*?)</a\s*>~i', self::linkText(...), $text);
        $text = self::replace('~<br\b[^>]*>~i', "\n", $text);
        $text = self::replace('~<li\b[^>]*>~i', '- ', $text);
        $text = self::replace('~</(?:li|tr)\s*>~i', "\n", $text);
        $text = self::replace('~</t[dh]\s*>~i', self::CELL_END, $text);
        $text = self::replace(self::BLOCK_ELEMENTS, "\n\n", $text);
        $text = self::decode(strip_tags($text));
        $text = self::replace('~ +~', ' ', $text);
        $text = self::replace('~^[ \t]+|[ \t]+$~m', '', $text);
        $text = self::replace('~ ?\t+ ?~', self::CELL_GAP, $text);

        return trim(self::replace('~\n{3,}~', "\n\n", $text));
    }

    /**
     * The HTML without the elements that carry a dropped class, each up to the first closing tag of its name.
     */
    private function withoutDroppedElements(string $html): string
    {
        foreach ($this->droppedClasses as $class) {
            $html = self::replace(sprintf(self::DROPPED_ELEMENT, preg_quote($class, delimiter: '~')), '', $html);
        }

        return $html;
    }

    /**
     * An image's alt text in its place.
     *
     * @param array<array-key, string> $match
     */
    private static function altText(array $match): string
    {
        return self::attribute($match[0] ?? '', 'alt');
    }

    /**
     * A link's words with its address in brackets after them, unless the words are the address,
     * it is a mail link to the address shown, or it points within the page.
     *
     * @param array<array-key, string> $match
     */
    private static function linkText(array $match): string
    {
        $attributes = $match['attributes'] ?? '';
        $href       = self::attribute("<a{$attributes}>", 'href');
        $words      = trim(strip_tags($match['words'] ?? ''));

        if ('' === $words) {
            return $href;
        }

        if ('' === $href || $words === $href || "mailto:{$words}" === $href || str_starts_with($href, '#')) {
            return $words;
        }

        return "{$words} ({$href})";
    }

    /**
     * A tag's attribute, quoted or not, with its entities decoded; nothing when the tag has none.
     */
    private static function attribute(string $tag, string $name): string
    {
        $match = [];
        preg_match("~\\s{$name}\\s*=\\s*(?:([\"'])(?<quoted>.*?)\\1|(?<bare>[^\\s>\"']+))~i", $tag, $match);

        return trim(self::decode($match['bare'] ?? $match['quoted'] ?? ''));
    }

    /**
     * Character references decoded, and the non-breaking spaces layouts pad with made ordinary.
     */
    private static function decode(string $text): string
    {
        return str_replace(
            search: "\u{00A0}",
            replace: ' ',
            subject: html_entity_decode($text, ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8'),
        );
    }

    private static function replace(string $pattern, string $replacement, string $text): string
    {
        return preg_replace($pattern, $replacement, $text) ?? $text;
    }

    /**
     * @param callable(array<array-key, string>): string $callback
     */
    private static function replaceWith(string $pattern, callable $callback, string $text): string
    {
        return preg_replace_callback($pattern, $callback, $text) ?? $text;
    }
}
