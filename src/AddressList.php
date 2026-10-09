<?php

declare(strict_types=1);

namespace Contenir\Mail;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use NoDiscard;
use Override;
use ReflectionClass;

use function array_key_exists;
use function array_key_first;
use function array_values;
use function count;
use function is_string;
use function strtolower;

/**
 * An ordered set of addresses, unique by e-mail address (compared case-insensitively).
 *
 * @mago-expect lint:too-many-methods A collection: construction, with/without, lookup and iteration.
 * @implements IteratorAggregate<int, Address>
 * @api
 */
final readonly class AddressList implements Countable, IteratorAggregate
{
    /** @var array<string, Address> keyed by lower-cased e-mail address */
    private array $addresses;

    /**
     * When an address appears more than once, the first occurrence is kept.
     */
    public function __construct(Address ...$addresses)
    {
        $unique = [];
        foreach ($addresses as $address) {
            $unique[strtolower($address->getEmail())] ??= $address;
        }

        $this->addresses = $unique;
    }

    /**
     * Build a list from addresses or address strings, such as `['a@example.com', 'Jo <jo@example.com>']`,
     * or from e-mail => display name pairs, such as `['jo@example.com' => 'Jo']`.
     *
     * @param iterable<int|string, Address|string|null> $addresses
     * @throws Exception\InvalidArgumentException When an entry is not a valid address.
     */
    public static function fromIterable(iterable $addresses): self
    {
        $list = [];
        foreach ($addresses as $key => $value) {
            $list[] = match (true) {
                $value instanceof Address => $value,
                is_string($key) => new Address($key, $value),
                null === $value => throw new Exception\InvalidArgumentException('An address list entry is empty'),
                default => Address::fromString($value),
            };
        }

        return new self(...$list);
    }

    /**
     * Add an address, or an e-mail address with an optional display name.
     *
     * An address already in the list is left as it is.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function with(Address|string $emailOrAddress, ?string $name = null): self
    {
        $address = $emailOrAddress instanceof Address ? $emailOrAddress : new Address($emailOrAddress, $name);

        return self::of($this->addresses + [strtolower($address->getEmail()) => $address]);
    }

    /**
     * Add every address in another list that is not already in this one.
     */
    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function withList(self $addressList): self
    {
        return self::of($this->addresses + $addressList->addresses);
    }

    #[NoDiscard('The object is immutable: this returns a changed copy and leaves it as it was')]
    public function without(string $email): self
    {
        $addresses = $this->addresses;
        unset($addresses[strtolower($email)]);

        return self::of($addresses);
    }

    public function has(string $email): bool
    {
        return array_key_exists(strtolower($email), $this->addresses);
    }

    public function get(string $email): ?Address
    {
        return $this->addresses[strtolower($email)] ?? null;
    }

    public function first(): ?Address
    {
        $key = array_key_first($this->addresses);

        return null === $key ? null : $this->addresses[$key] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->addresses;
    }

    /**
     * @return list<Address>
     */
    public function toArray(): array
    {
        return array_values($this->addresses);
    }

    #[Override]
    public function count(): int
    {
        return count($this->addresses);
    }

    /**
     * @return ArrayIterator<int, Address>
     */
    #[Override]
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->toArray());
    }

    /**
     * A list of addresses already keyed and unique, so adding to a list
     * does not go over the addresses it holds again.
     *
     * @param array<string, Address> $addresses
     *
     * @mago-expect analysis:invalid-property-write PHP lets the class initialise the readonly properties of an instance made without its constructor.
     * @mago-expect analysis:unhandled-thrown-type Reflection throws only for internal final classes, which this is not.
     */
    private static function of(array $addresses): self
    {
        $list            = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $list->addresses = $addresses;

        return $list;
    }
}
