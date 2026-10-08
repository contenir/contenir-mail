<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use Closure;
use Contenir\Mail\Address;
use Contenir\Mail\AddressList;
use Contenir\Mail\Header\ContentDisposition;
use Contenir\Mail\Header\ContentType;
use Contenir\Mail\Header\HeaderLocator;
use Contenir\Mail\Header\Subject;
use Contenir\Mail\Header\To;
use Contenir\Mail\Headers;
use Contenir\Mail\Mime\Attachment;
use Contenir\Mail\Mime\Body;
use Contenir\Mail\Mime\Part;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_WARNING;

/**
 * Immutable objects mark their with*() and without*() methods #[\NoDiscard], so PHP 8.5 warns
 * when the copy they return is thrown away, as when they are mistaken for setters.
 */
#[CoversClass(Headers::class)]
#[CoversClass(AddressList::class)]
#[CoversClass(Body::class)]
#[CoversClass(ContentType::class)]
#[CoversClass(ContentDisposition::class)]
#[CoversClass(HeaderLocator::class)]
#[CoversClass(To::class)]
#[Group('unit')]
final class ImmutableCopyTest extends TestCase
{
    /**
     * @param Closure(): void $discard Calls the method and drops the copy it returns.
     */
    #[Test]
    #[DataProvider('copyProvider')]
    #[RequiresPhp('>= 8.5')]
    public function warnsWhenTheCopyIsDiscarded(Closure $discard, string $method): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $discard();
        } finally {
            restore_error_handler();
        }

        static::assertSame(
            [
                "The return value of method {$method}() should either be used or intentionally ignored by casting it as (void), The object is immutable: this returns a changed copy and leaves it as it was",
            ],
            $warnings,
        );
    }

    /**
     * @return array<string, array{Closure(): void, string}>
     */
    public static function copyProvider(): array
    {
        $headers     = new Headers();
        $addresses   = new AddressList(new Address('jo@example.org'));
        $body        = new Body();
        $part        = new Part('Hello');
        $type        = new ContentType('text/plain', ['charset' => 'utf-8']);
        $disposition = new ContentDisposition('attachment', ['filename' => 'a.txt']);
        $to          = new To();

        return [
            'Headers::with()'                        => [
                static function () use ($headers): void {
                    $headers->with(new Subject('Hello'));
                },
                'Contenir\Mail\Headers::with',
            ],
            'Headers::withAdded()'                   => [
                static function () use ($headers): void {
                    $headers->withAdded(new Subject('Hello'));
                },
                'Contenir\Mail\Headers::withAdded',
            ],
            'Headers::withFirst()'                   => [
                static function () use ($headers): void {
                    $headers->withFirst(new Subject('Hello'));
                },
                'Contenir\Mail\Headers::withFirst',
            ],
            'Headers::without()'                     => [
                static function () use ($headers): void {
                    $headers->without('Subject');
                },
                'Contenir\Mail\Headers::without',
            ],
            'AddressList::with()'                    => [
                static function () use ($addresses): void {
                    $addresses->with('sam@example.org');
                },
                'Contenir\Mail\AddressList::with',
            ],
            'AddressList::withList()'                => [
                static function () use ($addresses): void {
                    $addresses->withList(new AddressList());
                },
                'Contenir\Mail\AddressList::withList',
            ],
            'AddressList::without()'                 => [
                static function () use ($addresses): void {
                    $addresses->without('jo@example.org');
                },
                'Contenir\Mail\AddressList::without',
            ],
            'Body::withText()'                       => [
                static function () use ($body, $part): void {
                    $body->withText($part);
                },
                'Contenir\Mail\Mime\Body::withText',
            ],
            'Body::withHtml()'                       => [
                static function () use ($body, $part): void {
                    $body->withHtml($part);
                },
                'Contenir\Mail\Mime\Body::withHtml',
            ],
            'Body::withEmbedded()'                   => [
                static function () use ($body): void {
                    $body->withEmbedded(Attachment::inline('PNG', id: 'logo', type: 'image/png'));
                },
                'Contenir\Mail\Mime\Body::withEmbedded',
            ],
            'Body::withAttachment()'                 => [
                static function () use ($body, $part): void {
                    $body->withAttachment($part);
                },
                'Contenir\Mail\Mime\Body::withAttachment',
            ],
            'ContentType::withType()'                => [
                static function () use ($type): void {
                    $type->withType('text/html');
                },
                'Contenir\Mail\Header\ContentType::withType',
            ],
            'ContentType::withParameter()'           => [
                static function () use ($type): void {
                    $type->withParameter('format', 'flowed');
                },
                'Contenir\Mail\Header\ContentType::withParameter',
            ],
            'ContentType::withoutParameter()'        => [
                static function () use ($type): void {
                    $type->withoutParameter('charset');
                },
                'Contenir\Mail\Header\ContentType::withoutParameter',
            ],
            'ContentDisposition::withDisposition()'  => [
                static function () use ($disposition): void {
                    $disposition->withDisposition('inline');
                },
                'Contenir\Mail\Header\ContentDisposition::withDisposition',
            ],
            'ContentDisposition::withParameter()'    => [
                static function () use ($disposition): void {
                    $disposition->withParameter('size', '3');
                },
                'Contenir\Mail\Header\ContentDisposition::withParameter',
            ],
            'ContentDisposition::withoutParameter()' => [
                static function () use ($disposition): void {
                    $disposition->withoutParameter('filename');
                },
                'Contenir\Mail\Header\ContentDisposition::withoutParameter',
            ],
            'HeaderLocator::with()'                  => [
                static function (): void {
                    (new HeaderLocator())->with('X-Custom', Subject::class);
                },
                'Contenir\Mail\Header\HeaderLocator::with',
            ],
            'address list header withAddressList()'  => [
                static function () use ($to, $addresses): void {
                    $to->withAddressList($addresses);
                },
                'Contenir\Mail\Header\AbstractAddressList::withAddressList',
            ],
            'address list header withAdded()'        => [
                static function () use ($to): void {
                    $to->withAdded(new Address('jo@example.org'));
                },
                'Contenir\Mail\Header\AbstractAddressList::withAdded',
            ],
        ];
    }
}
