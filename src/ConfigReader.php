<?php

declare(strict_types=1);

namespace Contenir\Mail;

use BackedEnum;
use Closure;

use function array_key_exists;
use function array_keys;
use function get_debug_type;
use function implode;
use function in_array;
use function is_bool;
use function is_int;
use function is_iterable;
use function is_object;
use function is_string;
use function method_exists;
use function preg_match;
use function preg_replace;
use function preg_split;
use function sprintf;
use function str_replace;
use function strtolower;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Reads an array or iterable of settings into typed values for a *Config object.
 *
 * Keys may be snake_case, camelCase or kebab-case. Unknown keys and values of
 * the wrong type are rejected, naming the key. Strings that come from
 * environment variables are accepted where they are unambiguous: "587" for an
 * integer, "true", "false", "1", "0", "yes", "no", "on" and "off" for a bool,
 * and an enum's backing value.
 *
 * A key given as null reads as absent, so the default applies.
 *
 * @internal
 *
 * @mago-expect lint:too-many-methods One typed reader per kind of setting.
 * @mago-expect lint:cyclomatic-complexity Each reader checks the types a setting may arrive as.
 * @mago-expect lint:kan-defect Each reader checks the types a setting may arrive as.
 * @mago-expect analysis:mixed-assignment Settings arrive untyped; reading them into types is this class's job.
 */
final readonly class ConfigReader
{
    private const array TRUE_STRINGS = ['1', 'true', 'yes', 'on'];

    private const array FALSE_STRINGS = ['0', 'false', 'no', 'off'];

    /**
     * @param array<string, mixed> $values keyed by snake_case name
     */
    private function __construct(
        private string $context,
        private array $values,
    ) {}

    /**
     * @param string $context Named in error messages, such as the Config class.
     * @param iterable<mixed, mixed> $config
     * @param list<string> $keys The accepted keys, in snake_case.
     * @throws Exception\InvalidArgumentException When a key is unknown, not a string, or given twice.
     */
    public static function read(string $context, iterable $config, array $keys): self
    {
        $values = [];
        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    '%s: option names must be strings, got %s',
                    $context,
                    get_debug_type($key),
                ));
            }

            $name = self::normalise($key);
            if (! in_array($name, $keys, strict: true)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    '%s: unknown option "%s"; expected one of %s',
                    $context,
                    $key,
                    implode(', ', $keys),
                ));
            }

            if (array_key_exists($name, $values)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    '%s: option "%s" is given more than once',
                    $context,
                    $name,
                ));
            }

            $values[$name] = $value;
        }

        return new self($context, $values);
    }

    /**
     * Whether the key was given with a value other than null.
     */
    public function has(string $key): bool
    {
        return null !== ($this->values[$key] ?? null);
    }

    /**
     * The snake_case keys that were given.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    /**
     * @throws Exception\InvalidArgumentException When the value is not a string.
     */
    public function string(string $key, string $default): string
    {
        return $this->nullableString($key) ?? $default;
    }

    /**
     * @throws Exception\InvalidArgumentException When the value is not a string.
     */
    public function nullableString(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || is_string($value)) {
            return $value;
        }

        throw $this->invalid($key, 'a string', $value);
    }

    /**
     * A setting that cannot be left out.
     *
     * @throws Exception\InvalidArgumentException When the value is missing or not a string.
     */
    public function requiredString(string $key): string
    {
        return $this->nullableString($key) ?? throw $this->missing($key);
    }

    /**
     * @throws Exception\InvalidArgumentException When the value is not an integer or a string of digits.
     */
    public function int(string $key, int $default): int
    {
        return $this->nullableInt($key) ?? $default;
    }

    /**
     * @throws Exception\InvalidArgumentException When the value is not an integer or a string of digits.
     */
    public function nullableInt(string $key): ?int
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || is_int($value)) {
            return $value;
        }

        if (is_string($value) && 1 === preg_match('/^-?\d+$/D', $value)) {
            return (int) $value;
        }

        throw $this->invalid($key, 'an integer', $value);
    }

    /**
     * A setting that cannot be left out.
     *
     * @throws Exception\InvalidArgumentException When the value is missing, or not an integer or a string of digits.
     */
    public function requiredInt(string $key): int
    {
        return $this->nullableInt($key) ?? throw $this->missing($key);
    }

    /**
     * @throws Exception\InvalidArgumentException When the value is not a bool or a recognised bool string.
     */
    public function bool(string $key, bool $default): bool
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default;
        }

        return self::toBool($value) ?? throw $this->invalid($key, 'a bool', $value);
    }

    /**
     * A setting that is either a string or a bool, such as the laminas-mail "ssl" setting.
     *
     * A string is returned as it is; anything else is read as bool() reads it.
     *
     * @throws Exception\InvalidArgumentException When the value is neither a string nor a bool.
     */
    public function stringOrBool(string $key): string|bool|null
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || is_string($value)) {
            return $value;
        }

        return self::toBool($value) ?? throw $this->invalid($key, 'a string or a bool', $value);
    }

    /**
     * A string, or a Closure or invokable object, such as a secret or a function that returns it.
     *
     * A string is always read as a string, never as the name of a function to call.
     *
     * @throws Exception\InvalidArgumentException When the value is neither a string, a Closure nor an invokable object.
     */
    public function stringOrCallable(string $key): string|Closure|null
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || is_string($value)) {
            return $value;
        }

        return (
            self::closure($value) ?? throw $this->invalid($key, 'a string, a Closure or an invokable object', $value)
        );
    }

    /**
     * A Closure or an invokable object, as a Closure.
     *
     * Function names and [class, method] arrays are refused, so settings
     * stored as data can never name a function for the library to call
     * (the class of PHPMailer's CVE-2021-3603).
     *
     * @throws Exception\InvalidArgumentException When the value is neither a Closure nor an invokable object.
     */
    public function callable(string $key): ?Closure
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return null;
        }

        return self::closure($value) ?? throw $this->invalid($key, 'a Closure or an invokable object', $value);
    }

    /**
     * An enum case, given as the case itself or as its backing value.
     *
     * String values are compared in lower case, so the enum's values must be lower case.
     * An integer-backed enum also accepts its value as a string of digits.
     *
     * @template T of BackedEnum
     * @param T $default A case of the enum to read, used when the key is absent.
     * @return T
     * @throws Exception\InvalidArgumentException When the value is not a case of the enum.
     *
     * @mago-expect analysis:invalid-return-statement A case of $default's own class is a T, which the analyser cannot narrow.
     */
    public function enum(string $key, BackedEnum $default): BackedEnum
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default;
        }

        if ($value instanceof $default) {
            return $value;
        }

        $case = match (true) {
            is_string($default->value) && is_string($value) => $default::tryFrom(strtolower($value)),
            is_int($default->value) && is_int($value) => $default::tryFrom($value),
            is_int($default->value) && is_string($value) && 1 === preg_match('/^-?\d+$/D', $value) => $default::tryFrom(
                (int) $value,
            ),
            default => null,
        };
        if (null === $case) {
            throw $this->invalid($key, sprintf('a %s case or its value', $default::class), $value);
        }

        return $case;
    }

    /**
     * A nested section, given as an object of the expected class or as settings to build one from.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param callable(iterable<mixed, mixed>): T $fromIterable
     * @return T|null
     * @throws Exception\InvalidArgumentException When the value is neither.
     */
    public function section(string $key, string $class, callable $fromIterable): ?object
    {
        $value = $this->values[$key] ?? null;
        if (null === $value || $value instanceof $class) {
            return $value;
        }

        if (is_iterable($value)) {
            return $fromIterable($value);
        }

        throw $this->invalid($key, sprintf('a %s or an array of its settings', $class), $value);
    }

    /**
     * @param list<string> $default
     * @return list<string>
     * @throws Exception\InvalidArgumentException When the value is not an iterable of strings.
     */
    public function stringList(string $key, array $default): array
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default;
        }

        return $this->list($key, $value, 'a list of strings');
    }

    /**
     * A list of words, given as a list of strings or as one string split on whitespace.
     *
     * @param list<string> $default
     * @return list<string>
     * @throws Exception\InvalidArgumentException When the value is neither a string nor an iterable of strings.
     */
    public function stringOrList(string $key, array $default): array
    {
        $value = $this->values[$key] ?? null;
        if (null === $value) {
            return $default;
        }

        if (is_string($value)) {
            $words = preg_split('/\s+/', $value, flags: PREG_SPLIT_NO_EMPTY);

            return false === $words ? [] : $words;
        }

        return $this->list($key, $value, 'a string or a list of strings');
    }

    private static function closure(mixed $value): ?Closure
    {
        return is_object($value) && method_exists($value, method: '__invoke') ? $value->__invoke(...) : null;
    }

    private static function normalise(string $key): string
    {
        return strtolower(str_replace(
            search: '-',
            replace: '_',
            subject: (string) preg_replace(
                pattern: '/(?<=[a-z0-9])[A-Z]/',
                replacement: '_$0',
                subject: $key,
            ),
        ));
    }

    /**
     * @return list<string>
     * @throws Exception\InvalidArgumentException When the value is not an iterable of strings.
     */
    private function list(string $key, mixed $value, string $expected): array
    {
        if (! is_iterable($value)) {
            throw $this->invalid($key, $expected, $value);
        }

        $list = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw $this->invalid($key, $expected, $item);
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * A bool, or one of the integers and strings that stand for one; null for anything else.
     */
    private static function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $text = strtolower((string) $value);

        return match (true) {
            in_array($text, self::TRUE_STRINGS, strict: true) => true,
            in_array($text, self::FALSE_STRINGS, strict: true) => false,
            default => null,
        };
    }

    private function missing(string $key): Exception\InvalidArgumentException
    {
        return new Exception\InvalidArgumentException(sprintf('%s: option "%s" is required', $this->context, $key));
    }

    private function invalid(string $key, string $expected, mixed $value): Exception\InvalidArgumentException
    {
        return new Exception\InvalidArgumentException(sprintf(
            '%s: option "%s" must be %s, got %s',
            $this->context,
            $key,
            $expected,
            get_debug_type($value),
        ));
    }
}
