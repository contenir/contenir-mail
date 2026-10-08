<?php

declare(strict_types=1);

namespace Contenir\Mail\Mime;

use NoDiscard;

/**
 * The parts of a message body, arranged into a MIME tree on request.
 *
 * Text and HTML become alternatives, HTML with its embedded resources becomes
 * a related group, and attachments follow in a mixed multipart.
 */
final readonly class Body
{
    /**
     * @param list<Part> $embedded
     * @param list<Part> $attachments
     */
    public function __construct(
        private ?Part $text = null,
        private ?Part $html = null,
        private array $embedded = [],
        private array $attachments = [],
    ) {}

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withText(Part $text): self
    {
        return new self($text, $this->html, $this->embedded, $this->attachments);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withHtml(Part $html): self
    {
        return new self($this->text, $html, $this->embedded, $this->attachments);
    }

    /**
     * @throws Exception\InvalidArgumentException When the part has no Content-ID for the HTML to refer to.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withEmbedded(Part $resource): self
    {
        if (null === $resource->getId()) {
            throw new Exception\InvalidArgumentException(
                'An embedded part needs a Content-ID for the HTML to refer to',
            );
        }

        return new self($this->text, $this->html, [...$this->embedded, $resource], $this->attachments);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withAttachment(Part $attachment): self
    {
        return new self($this->text, $this->html, $this->embedded, [...$this->attachments, $attachment]);
    }

    /**
     * The MIME tree for these parts, with new boundaries each time; null when there are no parts.
     *
     * @throws Exception\RuntimeException When there are embedded resources but no HTML to refer to them.
     */
    public function toPart(): ?PartInterface
    {
        $content = $this->content();
        if ([] === $this->attachments) {
            return $content;
        }

        return new Multipart(
            MultipartType::Mixed,
            null === $content ? $this->attachments : [$content, ...$this->attachments],
        );
    }

    /**
     * @throws Exception\RuntimeException
     */
    private function content(): ?PartInterface
    {
        $html = $this->relatedHtml();
        if (null === $html) {
            return $this->text;
        }

        if (null === $this->text) {
            return $html;
        }

        return new Multipart(MultipartType::Alternative, [$this->text, $html]);
    }

    /**
     * @throws Exception\RuntimeException When there are embedded resources but no HTML to refer to them.
     */
    private function relatedHtml(): ?PartInterface
    {
        if ([] === $this->embedded) {
            return $this->html;
        }

        if (null === $this->html) {
            throw new Exception\RuntimeException('Embedded resources need an HTML body to refer to them');
        }

        return new Multipart(MultipartType::Related, [$this->html, ...$this->embedded]);
    }
}
