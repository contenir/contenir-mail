<?php

namespace Contenir\Mail\Storage;

use ArrayIterator;
use Contenir\Mail\Header\HeaderInterface;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime;
use Contenir\Mail\Mime\Exception\RuntimeException;
use Override;
use RecursiveIterator;
use ReturnTypeWillChange;
use Stringable;

use function array_map;
use function count;
use function current;
use function implode;
use function is_array;
use function preg_replace;
use function stripos;
use function strlen;
use function strtolower;
use function trim;

class Part implements RecursiveIterator, Part\PartInterface, Stringable
{
    /**
     * Headers of the part
     *
     * @var Headers|null
     */
    protected $headers;

    /**
     * raw part body
     *
     * @var null|string
     */
    protected $content;

    /**
     * toplines as fetched with headers
     *
     * @var string
     */
    protected $topLines = '';

    /**
     * parts of multipart message
     *
     * @var array
     */
    protected $parts = [];

    /**
     * count of parts of a multipart message
     *
     * @var null|int
     */
    protected $countParts;

    /**
     * current position of iterator
     *
     * @var int
     */
    protected $iterationPos = 1;

    /**
     * mail handler, if late fetch is active
     *
     * @var null|AbstractStorage
     */
    protected $mail;

    /**
     * message number for mail handler
     *
     * @var int
     */
    protected $messageNum = 0;

    /**
     * Public constructor
     *
     * Part supports different sources for content. The possible params are:
     * - handler    an instance of AbstractStorage for late fetch
     * - id         number of message for handler
     * - raw        raw content with header and body as string
     * - headers    headers as array (name => value) or string, if a content part is found it's used as toplines
     * - noToplines ignore content found after headers in param 'headers'
     * - content    content as string
     * - strict     strictly parse raw content
     *
     * @param   array $params  full message with or without headers
     * @throws Exception\InvalidArgumentException
     */
    public function __construct(array $params)
    {
        if (isset($params['handler'])) {
            if (! $params['handler'] instanceof AbstractStorage) {
                throw new Exception\InvalidArgumentException('handler is not a valid mail handler');
            }
            if (! isset($params['id'])) {
                throw new Exception\InvalidArgumentException('need a message id with a handler');
            }

            $this->mail       = $params['handler'];
            $this->messageNum = $params['id'];
        }

        $params['strict'] ??= false;

        if (isset($params['raw'])) {
            Mime\Decode::splitMessage(
                $params['raw'],
                $this->headers,
                $this->content,
                Mime\Mime::LINEEND,
                $params['strict'],
            );
        } elseif (isset($params['headers'])) {
            if (is_array($params['headers'])) {
                /** @var array<int|string, string|array{string, string}> $headerList */
                $headerList    = $params['headers'];
                $this->headers = Headers::fromIterable($headerList);
            } else {
                if (empty($params['noToplines'])) {
                    Mime\Decode::splitMessage($params['headers'], $this->headers, $this->topLines);
                } else {
                    $this->headers = Headers::fromString($params['headers']);
                }
            }

            if (isset($params['content'])) {
                $this->content = $params['content'];
            }
        }
    }

    /**
     * Check if part is a multipart message
     *
     * @return bool if part is multipart
     */
    #[Override]
    public function isMultipart()
    {
        try {
            return stripos($this->contentType, 'multipart/') === 0;
        } catch (Exception\ExceptionInterface) {
            return false;
        }
    }

    /**
     * Body of part
     *
     * If part is multipart the raw content of this part with all sub parts is returned
     *
     * @throws Exception\RuntimeException
     * @return string body
     */
    #[Override]
    public function getContent()
    {
        if (null !== $this->content) {
            return $this->content;
        }

        if ($this->mail) {
            return $this->mail->getRawContent($this->messageNum);
        }

        throw new Exception\RuntimeException('no content');
    }

    /**
     * Return size of part
     *
     * Quite simple implemented currently (not decoding). Handle with care.
     *
     * @return int size
     */
    #[Override]
    public function getSize()
    {
        return strlen($this->getContent());
    }

    /**
     * Cache content and split in parts if multipart
     *
     * @throws Exception\RuntimeException
     * @return void
     */
    protected function cacheContent()
    {
        // caching content if we can't fetch parts
        if (null === $this->content && $this->mail) {
            $this->content = $this->mail->getRawContent($this->messageNum);
        }

        if (! $this->isMultipart()) {
            return;
        }

        // split content in parts
        $boundary = $this->getHeaderField('content-type', 'boundary');
        if (! $boundary) {
            throw new Exception\RuntimeException('no boundary found in content type to split message');
        }
        $parts = Mime\Decode::splitMessageStruct($this->content, $boundary);
        if (null === $parts) {
            return;
        }
        $counter = 1;
        foreach ($parts as $part) {
            $this->parts[$counter++] = new static(['headers' => $part['header'], 'content' => $part['body']]);
        }
    }

    /**
     * Get part of multipart message
     *
     * @param  int $num number of part starting with 1 for first part
     * @throws Exception\RuntimeException
     * @return Part wanted part
     */
    #[Override]
    public function getPart($num)
    {
        if (isset($this->parts[$num])) {
            return $this->parts[$num];
        }

        if (! $this->mail && null === $this->content) {
            throw new Exception\RuntimeException('part not found');
        }

        // if ($this->mail && $this->mail->hasFetchPart) {
        // TODO: fetch part
        // return
        // }

        $this->cacheContent();

        if (! isset($this->parts[$num])) {
            throw new Exception\RuntimeException('part not found');
        }

        return $this->parts[$num];
    }

    /**
     * Count parts of a multipart part
     *
     * @return int number of sub-parts
     */
    #[Override]
    public function countParts()
    {
        if ($this->countParts) {
            return $this->countParts;
        }

        $this->countParts = count($this->parts);
        if ($this->countParts) {
            return $this->countParts;
        }

        // if ($this->mail && $this->mail->hasFetchPart) {
        // TODO: fetch part
        // return
        // }

        $this->cacheContent();

        $this->countParts = count($this->parts);
        return $this->countParts;
    }

    /**
     * Access headers collection
     *
     * Lazy-loads if not already attached.
     *
     * @return Headers
     * @throws Exception\RuntimeException
     */
    #[Override]
    public function getHeaders()
    {
        if (null === $this->headers) {
            if ($this->mail) {
                $part          = $this->mail->getRawHeader($this->messageNum);
                $this->headers = Headers::fromString($part);
            } else {
                $this->headers = new Headers();
            }
        }
        if (! $this->headers instanceof Headers) {
            throw new Exception\RuntimeException(
                '$this->headers must be an instance of Headers',
            );
        }

        return $this->headers;
    }

    /**
     * Get a header in specified format
     *
     * Internally headers that occur more than once are saved as array, all other as string. If $format
     * is set to string implode is used to concat the values (with Mime::LINEEND as delim).
     *
     * @param  string $name   name of header, matches case-insensitive, but camel-case is replaced with dashes
     * @param  string $format change type of return value to 'string' or 'array'
     * @throws Exception\InvalidArgumentException
     * @return string|array|HeaderInterface|ArrayIterator value of header in wanted or internal format
     */
    #[Override]
    public function getHeader($name, $format = null)
    {
        $headers = $this->getHeaders()->all($name);
        if ([] === $headers) {
            $lowerName = strtolower((string) preg_replace('%([a-z])([A-Z])%', '\1-\2', $name));
            $headers   = $this->getHeaders()->all($lowerName);
            if ([] === $headers) {
                throw new Exception\InvalidArgumentException(
                    "Header with Name {$name} or {$lowerName} not found",
                );
            }
        }

        $values = array_map(static fn(HeaderInterface $header): string => $header->getFieldValue(), $headers);

        return match ($format) {
            'string' => trim(implode(Mime\Mime::LINEEND, $values), Mime\Mime::LINEEND),
            'array'  => $values,
            default  => 1 === count($headers) ? $headers[0] : new ArrayIterator($headers),
        };
    }

    /**
     * Get a specific field from a header like content type or all fields as array
     *
     * If the header occurs more than once, only the value from the first header
     * is returned.
     *
     * Throws an Exception if the requested header does not exist. If
     * the specific header field does not exist, returns null.
     *
     * @param  string $name       name of header, like in getHeader()
     * @param  string $wantedPart the wanted part, default is first, if null an array with all parts is returned
     * @param  string $firstName  key name for the first part
     * @return string|array wanted part or all parts as array($firstName => firstPart, partname => value)
     * @throws RuntimeException
     */
    #[Override]
    public function getHeaderField($name, $wantedPart = '0', $firstName = '0')
    {
        return Mime\Decode::splitHeaderField(current($this->getHeader($name, 'array')), $wantedPart, $firstName);
    }

    /**
     * Getter for mail headers - name is matched in lowercase
     *
     * This getter is short for Part::getHeader($name, 'string')
     *
     * @see Part::getHeader()
     *
     * @param  string $name header name
     * @return string value of header
     * @throws Exception\ExceptionInterface
     */
    #[Override]
    public function __get($name)
    {
        return $this->getHeader($name, 'string');
    }

    /**
     * Isset magic method proxy to hasHeader
     *
     * This method is short syntax for Part::hasHeader($name);
     *
     * @see Part::hasHeader
     *
     * @param  string $name
     * @return bool
     */
    public function __isset($name)
    {
        return $this->getHeaders()->has($name);
    }

    /**
     * magic method to get content of part
     *
     * @return string content
     */
    #[Override]
    public function __toString(): string
    {
        return $this->getContent();
    }

    /**
     * implements RecursiveIterator::hasChildren()
     *
     * @return bool current element has children/is multipart
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function hasChildren()
    {
        $current = $this->current();
        return $current && $current instanceof self && $current->isMultipart();
    }

    /**
     * implements RecursiveIterator::getChildren()
     *
     * @return Part same as self::current()
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function getChildren()
    {
        return $this->current();
    }

    /**
     * implements Iterator::valid()
     *
     * @return bool check if there's a current element
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function valid()
    {
        if (null === $this->countParts) {
            $this->countParts();
        }
        return $this->iterationPos && $this->iterationPos <= $this->countParts;
    }

    /**
     * implements Iterator::next()
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function next()
    {
        ++$this->iterationPos;
    }

    /**
     * implements Iterator::key()
     *
     * @return string key/number of current part
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function key()
    {
        return $this->iterationPos;
    }

    /**
     * implements Iterator::current()
     *
     * @return Part current part
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function current()
    {
        return $this->getPart($this->iterationPos);
    }

    /**
     * implements Iterator::rewind()
     */
    #[Override]
    #[ReturnTypeWillChange]
    public function rewind()
    {
        $this->countParts();
        $this->iterationPos = 1;
    }
}
