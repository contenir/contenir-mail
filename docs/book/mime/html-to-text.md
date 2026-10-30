# Contenir\\Mail\\Mime\\HtmlToText

`Contenir\Mail\Mime\HtmlToText` reads an HTML body as plain text, for the
`text/plain` half of a `multipart/alternative` message. A message sent with an
empty or missing text part reads as nothing in mail clients that show text,
and some spam filters mark it down; deriving the text from the HTML gives
every reader the words.

```php
use Contenir\Mail\Mime\HtmlToText;

$text = (new HtmlToText())->convert($html);
```

`Multipart::alternative()` builds the text and HTML pair from the HTML alone,
and `Message` takes the text through `setText()`:

```php
use Contenir\Mail\Mime\Multipart;

$message->setBody(Multipart::alternative($html));

$message->setHtml($html);
$message->setText((new HtmlToText())->convert($html));
```

## What the text looks like

Rule | HTML | Text
---- | ---- | ----
Headings, paragraphs and other block elements are paragraphs of their own, a blank line between them | `<h1>Hello</h1><p>Dear Sam,</p>` | `Hello` ␤␤ `Dear Sam,`
`<br>` breaks the line | `One<br>Two` | `One` ␤ `Two`
List items are dashed lines | `<ul><li>One</li><li>Two</li></ul>` | `- One` ␤ `- Two`
Table cells sit side by side, two spaces apart, a row per line | `<tr><td>Total</td><td>$9</td></tr>` | `Total  $9`
A link's address follows its words in brackets | `<a href="https://x.test/b">Your booking</a>` | `Your booking (https://x.test/b)`
Unless the words are the address | `<a href="https://x.test">https://x.test</a>` | `https://x.test`
Or it is a mail link to the address shown | `<a href="mailto:jo@x.test">jo@x.test</a>` | `jo@x.test`
Or it points within the page | `<a href="#details">Details</a>` | `Details`
A link without words is its address | `<a href="https://x.test/r"></a>` | `https://x.test/r`
An image is its alt text | `<img src="logo.png" alt="Acme">` | `Acme`
Character references are decoded, non-breaking spaces made ordinary | `Tom &amp; Jerry&nbsp;&mdash;` | `Tom & Jerry —`

Inline markup such as `<strong>` and `<span>` is dropped, leaving its words.
Whitespace in the source is collapsed as a browser collapses it, each line is
trimmed, and runs of blank lines are squeezed to one.

The `<head>`, `<style>` and `<script>` elements and comments are dropped with
everything inside them. So is every element with the class `preheader`, the
hidden preview line that email layouts carry: shown in the inbox list, it
would read as a duplicate first line in the text. Name other classes to drop,
or none, when you build the converter:

```php
new HtmlToText(droppedClasses: ['preheader', 'print-only']);
new HtmlToText(droppedClasses: []);
```

A class matches as a whole word of the `class` attribute, so `preheader-text`
is not `preheader`. Tags are read with regular expressions rather than parsed,
as the HTML is your own: an element with a dropped class ends at the first
closing tag of its name, so put nothing of the same element inside it.

The text is UTF-8, as `html_entity_decode()` produces it; give the converter
UTF-8 HTML.

## Available methods

- `new HtmlToText(array $droppedClasses = ['preheader'])`: A converter that
  drops the elements of the given classes.
- `convert(string $html)`: The plain-text reading of the HTML.
