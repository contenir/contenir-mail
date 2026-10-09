<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit;

use ArrayIterator;
use Closure;
use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\ConnectionConfig;
use Contenir\Mail\Protocol\Security;
use Contenir\Mail\Tests\TestAsset\OtherSecurity;
use Contenir\Mail\Tests\TestAsset\Priority;
use Contenir\Mail\Tests\TestAsset\UpperCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ConfigReader::class)]
#[Group('unit')]
final class ConfigReaderTest extends TestCase
{
    private const array KEYS = ['host', 'port', 'verify_peer', 'security', 'connection', 'flags', 'priority'];

    /**
     * @param array<array-key, mixed> $config
     */
    private static function reader(array $config): ConfigReader
    {
        return ConfigReader::read('Example', $config, self::KEYS);
    }

    #[Test]
    #[DataProvider('keySpellingProvider')]
    public function acceptsKeysInAnySpelling(string $key): void
    {
        static::assertSame(['verify_peer'], self::reader([$key => true])->keys());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function keySpellingProvider(): array
    {
        return [
            'snake_case'  => ['verify_peer'],
            'camelCase'   => ['verifyPeer'],
            'PascalCase'  => ['VerifyPeer'],
            'kebab-case'  => ['verify-peer'],
            'upper snake' => ['VERIFY_PEER'],
        ];
    }

    #[Test]
    public function readsFromAnyIterable(): void
    {
        static::assertSame(
            'mail.example.com',
            ConfigReader::read('Example', new ArrayIterator(['host' => 'mail.example.com']), self::KEYS)->string(
                'host',
                default: 'localhost',
            ),
        );
    }

    #[Test]
    public function rejectsUnknownKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Example: unknown option "hots"; expected one of host, port, verify_peer, security, connection, flags, priority',
        );

        self::reader(['hots' => 'mail.example.com']);
    }

    #[Test]
    public function rejectsNumericKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Example: option names must be strings, got int');

        self::reader(['mail.example.com']);
    }

    #[Test]
    public function rejectsKeyGivenTwiceInDifferentSpellings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Example: option "verify_peer" is given more than once');

        self::reader(['verify_peer' => true, 'verifyPeer' => false]);
    }

    #[Test]
    #[DataProvider('hasProvider')]
    public function reportsWhetherKeyHasValue(array $config, bool $expected): void
    {
        static::assertSame($expected, self::reader($config)->has('host'));
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function hasProvider(): array
    {
        return [
            'given'       => [['host' => 'a'], true],
            'empty value' => [['host' => ''], true],
            'null'        => [['host' => null], false],
            'absent'      => [[], false],
        ];
    }

    #[Test]
    #[DataProvider('stringProvider')]
    public function readsString(array $config, string $expected): void
    {
        static::assertSame($expected, self::reader($config)->string('host', default: 'localhost'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function stringProvider(): array
    {
        return [
            'given'  => [['host' => 'mail.example.com'], 'mail.example.com'],
            'empty'  => [['host' => ''], ''],
            'null'   => [['host' => null], 'localhost'],
            'absent' => [[], 'localhost'],
        ];
    }

    #[Test]
    public function readsAbsentNullableStringAsNull(): void
    {
        static::assertNull(self::reader([])->nullableString('host'));
    }

    #[Test]
    public function rejectsNonStringForString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Example: option "host" must be a string, got int');

        self::reader(['host' => 25])->string('host', default: 'localhost');
    }

    #[Test]
    #[DataProvider('intProvider')]
    public function readsInt(array $config, int $expected): void
    {
        static::assertSame($expected, self::reader($config)->int('port', default: 25));
    }

    /**
     * @return array<string, array{array<string, mixed>, int}>
     */
    public static function intProvider(): array
    {
        return [
            'integer'         => [['port' => 587], 587],
            'digits'          => [['port' => '587'], 587],
            'negative digits' => [['port' => '-1'], -1],
            'zero'            => [['port' => '0'], 0],
            'null'            => [['port' => null], 25],
            'absent'          => [[], 25],
        ];
    }

    #[Test]
    public function readsAbsentNullableIntAsNull(): void
    {
        static::assertNull(self::reader([])->nullableInt('port'));
    }

    #[Test]
    #[DataProvider('invalidIntProvider')]
    public function rejectsNonInteger(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Example: option \"port\" must be an integer, got {$type}");

        self::reader(['port' => $value])->int('port', default: 25);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidIntProvider(): array
    {
        return [
            'float'            => [587.0, 'float'],
            'decimal string'   => ['587.5', 'string'],
            'empty string'     => ['', 'string'],
            'leading text'     => ['port587', 'string'],
            'trailing text'    => ['587tcp', 'string'],
            'trailing newline' => ["587\n", 'string'],
            'bool'             => [true, 'bool'],
        ];
    }

    #[Test]
    #[DataProvider('boolProvider')]
    public function readsBool(mixed $value, bool $default, bool $expected): void
    {
        static::assertSame($expected, self::reader(['verify_peer' => $value])->bool('verify_peer', $default));
    }

    /**
     * @return array<string, array{mixed, bool, bool}>
     */
    public static function boolProvider(): array
    {
        return [
            'true'               => [true, false, true],
            'false'              => [false, true, false],
            'one'                => [1, false, true],
            'zero'               => [0, true, false],
            '"1"'                => ['1', false, true],
            '"0"'                => ['0', true, false],
            '"true"'             => ['true', false, true],
            '"false"'            => ['false', true, false],
            '"TRUE" in capitals' => ['TRUE', false, true],
            '"yes"'              => ['yes', false, true],
            '"no"'               => ['no', true, false],
            '"on"'               => ['on', false, true],
            '"off"'              => ['off', true, false],
            'null keeps true'    => [null, true, true],
            'null keeps false'   => [null, false, false],
        ];
    }

    #[Test]
    #[DataProvider('invalidBoolProvider')]
    public function rejectsNonBool(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Example: option \"verify_peer\" must be a bool, got {$type}");

        self::reader(['verify_peer' => $value])->bool('verify_peer', default: true);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidBoolProvider(): array
    {
        return [
            'two'          => [2, 'int'],
            'word'         => ['maybe', 'string'],
            'empty string' => ['', 'string'],
            'float'        => [1.0, 'float'],
            'array'        => [[], 'array'],
        ];
    }

    #[Test]
    #[DataProvider('enumProvider')]
    public function readsEnum(mixed $value, Security $expected): void
    {
        static::assertSame(
            $expected,
            self::reader(['security' => $value])->enum('security', default: Security::None),
        );
    }

    /**
     * @return array<string, array{mixed, Security}>
     */
    public static function enumProvider(): array
    {
        return [
            'case'             => [Security::StartTls, Security::StartTls],
            'value'            => ['starttls', Security::StartTls],
            'value, any case'  => ['StartTLS', Security::StartTls],
            'null for default' => [null, Security::None],
        ];
    }

    #[Test]
    #[DataProvider('invalidEnumProvider')]
    public function rejectsNonEnumValue(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Example: option \"security\" must be a Contenir\\Mail\\Protocol\\Security case or its value, got {$type}",
        );

        self::reader(['security' => $value])->enum('security', default: Security::None);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidEnumProvider(): array
    {
        return [
            'unknown value' => ['ssl', 'string'],
            'integer'       => [1, 'int'],
            'bool'          => [true, 'bool'],
            'other enum'    => [OtherSecurity::Tls, OtherSecurity::class],
        ];
    }

    #[Test]
    #[DataProvider('intEnumProvider')]
    public function readsIntegerBackedEnum(mixed $value, Priority $expected): void
    {
        static::assertSame(
            $expected,
            self::reader(['priority' => $value])->enum('priority', default: Priority::Low),
        );
    }

    /**
     * @return array<string, array{mixed, Priority}>
     */
    public static function intEnumProvider(): array
    {
        return [
            'case'   => [Priority::High, Priority::High],
            'value'  => [5, Priority::High],
            'digits' => ['5', Priority::High],
            'null'   => [null, Priority::Low],
        ];
    }

    #[Test]
    #[DataProvider('invalidIntEnumProvider')]
    public function rejectsNonIntegerEnumValue(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Example: option "priority" must be a ' . Priority::class . ' case or its value, got ' . $type,
        );

        self::reader(['priority' => $value])->enum('priority', default: Priority::Low);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidIntEnumProvider(): array
    {
        return [
            'unknown value'          => [3, 'int'],
            'unknown digits'         => ['3', 'string'],
            'text before digits'     => ['x5', 'string'],
            'text after digits'      => ['5x', 'string'],
            'line feed after digits' => ["5\n", 'string'],
            'word'                   => ['high', 'string'],
            'float'                  => [5.0, 'float'],
        ];
    }

    #[Test]
    public function readsSectionFromSettings(): void
    {
        $section = self::reader(['connection' => ['host' => 'mail.example.com']])->section(
            'connection',
            ConnectionConfig::class,
            ConnectionConfig::fromIterable(...),
        );

        static::assertEquals(new ConnectionConfig(host: 'mail.example.com'), $section);
    }

    #[Test]
    public function readsSectionGivenAsObject(): void
    {
        $connection = new ConnectionConfig(host: 'mail.example.com');

        static::assertSame(
            $connection,
            self::reader(['connection' => $connection])->section(
                'connection',
                ConnectionConfig::class,
                ConnectionConfig::fromIterable(...),
            ),
        );
    }

    #[Test]
    public function readsAbsentSectionAsNull(): void
    {
        static::assertNull(self::reader([])->section(
            'connection',
            ConnectionConfig::class,
            ConnectionConfig::fromIterable(...),
        ));
    }

    #[Test]
    public function readsInstanceOfClass(): void
    {
        $value = new stdClass();

        static::assertSame($value, self::reader(['connection' => $value])->instance('connection', stdClass::class));
    }

    #[Test]
    public function readsAbsentInstanceAsNull(): void
    {
        static::assertNull(self::reader(['connection' => null])->instance('connection', stdClass::class));
    }

    #[Test]
    #[DataProvider('invalidSectionProvider')]
    public function rejectsInstanceOfWrongType(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Example: option \"connection\" must be a Contenir\\Mail\\Protocol\\ConnectionConfig, got {$type}",
        );

        self::reader(['connection' => $value])->instance('connection', ConnectionConfig::class);
    }

    #[Test]
    #[DataProvider('invalidSectionProvider')]
    public function rejectsSectionOfWrongType(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Example: option \"connection\" must be a Contenir\\Mail\\Protocol\\ConnectionConfig"
                . " or an array of its settings, got {$type}",
        );

        self::reader(['connection' => $value])->section(
            'connection',
            ConnectionConfig::class,
            ConnectionConfig::fromIterable(...),
        );
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidSectionProvider(): array
    {
        return [
            'string'       => ['mail.example.com', 'string'],
            'other object' => [new stdClass(), 'stdClass'],
        ];
    }

    #[Test]
    #[DataProvider('stringListProvider')]
    public function readsStringList(mixed $value, array $expected): void
    {
        static::assertSame($expected, self::reader(['flags' => $value])->stringList('flags', default: ['\Seen']));
    }

    /**
     * @return array<string, array{mixed, list<string>}>
     */
    public static function stringListProvider(): array
    {
        return [
            'list'           => [['\Seen', '\Flagged'], ['\Seen', '\Flagged']],
            'keyed iterable' => [new ArrayIterator(['a' => '\Draft']), ['\Draft']],
            'empty'          => [[], []],
            'null'           => [null, ['\Seen']],
        ];
    }

    #[Test]
    #[DataProvider('invalidStringListProvider')]
    public function rejectsNonStringList(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Example: option \"flags\" must be a list of strings, got {$type}");

        self::reader(['flags' => $value])->stringList('flags', default: []);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidStringListProvider(): array
    {
        return [
            'string'           => ['\Seen', 'string'],
            'list with an int' => [['\Seen', 1], 'int'],
        ];
    }

    #[Test]
    public function readsRequiredString(): void
    {
        static::assertSame('mail.example.com', self::reader(['host' => 'mail.example.com'])->requiredString('host'));
    }

    #[Test]
    #[DataProvider('missingProvider')]
    public function rejectsMissingRequiredString(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Example: option "host" is required');

        self::reader($config)->requiredString('host');
    }

    #[Test]
    public function rejectsNonStringForRequiredString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Example: option "host" must be a string, got int');

        self::reader(['host' => 1])->requiredString('host');
    }

    #[Test]
    public function readsRequiredInt(): void
    {
        static::assertSame(993, self::reader(['port' => '993'])->requiredInt('port'));
    }

    #[Test]
    public function rejectsMissingRequiredInt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Example: option "port" is required');

        self::reader([])->requiredInt('port');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingProvider(): array
    {
        return [
            'absent' => [[]],
            'null'   => [['host' => null]],
        ];
    }

    #[Test]
    #[DataProvider('stringOrBoolProvider')]
    public function readsStringOrBool(mixed $value, string|bool|null $expected): void
    {
        static::assertSame($expected, self::reader(['security' => $value])->stringOrBool('security'));
    }

    /**
     * @return array<string, array{mixed, string|bool|null}>
     */
    public static function stringOrBoolProvider(): array
    {
        return [
            'string'      => ['ssl', 'ssl'],
            'bool string' => ['true', 'true'],
            'empty'       => ['', ''],
            'true'        => [true, true],
            'false'       => [false, false],
            'one'         => [1, true],
            'zero'        => [0, false],
            'null'        => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('invalidStringOrBoolProvider')]
    public function rejectsValueThatIsNeitherStringNorBool(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Example: option \"security\" must be a string or a bool, got {$type}");

        self::reader(['security' => $value])->stringOrBool('security');
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidStringOrBoolProvider(): array
    {
        return [
            'array' => [[], 'array'],
            'two'   => [2, 'int'],
            'float' => [1.0, 'float'],
        ];
    }

    #[Test]
    public function readsInvokableObjectAsClosure(): void
    {
        $callable = self::reader(['connection' => new UpperCase()])->callable('connection');

        static::assertSame('ABC', null === $callable ? null : $callable('abc'));
    }

    #[Test]
    public function keepsGivenClosure(): void
    {
        $closure = static fn(): string => 'x';

        static::assertSame($closure, self::reader(['connection' => $closure])->callable('connection'));
    }

    #[Test]
    public function readsAbsentCallableAsNull(): void
    {
        static::assertNull(self::reader([])->callable('connection'));
    }

    /**
     * Settings stored as data must never name a function for the library to call (CVE-2021-3603 in PHPMailer).
     */
    #[Test]
    #[DataProvider('namedCallableProvider')]
    public function refusesCallableGivenByNameAgainstFunctionInjection(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Example: option \"connection\" must be a Closure or an invokable object, got {$type}",
        );

        self::reader(['connection' => $value])->callable('connection');
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function namedCallableProvider(): array
    {
        return [
            'function name'        => ['system', 'string'],
            'static method name'   => [self::class . '::missingProvider', 'string'],
            'class and method'     => [[self::class, 'missingProvider'], 'array'],
            'not a function'       => ['not a function', 'string'],
            'object not invokable' => [new stdClass(), 'stdClass'],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('stringOrListProvider')]
    public function readsStringOrList(mixed $value, array $expected): void
    {
        static::assertSame($expected, self::reader(['flags' => $value])->stringOrList('flags', default: ['\Seen']));
    }

    /**
     * @return array<string, array{mixed, list<string>}>
     */
    public static function stringOrListProvider(): array
    {
        return [
            'string'           => ['-R hdrs', ['-R', 'hdrs']],
            'other whitespace' => [" -R\t\n hdrs  ", ['-R', 'hdrs']],
            'blank string'     => [' ', []],
            'list'             => [['-R', 'hdrs'], ['-R', 'hdrs']],
            'keyed iterable'   => [new ArrayIterator(['a' => '-oi']), ['-oi']],
            'null'             => [null, ['\Seen']],
        ];
    }

    #[Test]
    #[DataProvider('invalidStringOrListProvider')]
    public function rejectsValueThatIsNeitherStringNorList(mixed $value, string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Example: option \"flags\" must be a string or a list of strings, got {$type}");

        self::reader(['flags' => $value])->stringOrList('flags', default: []);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidStringOrListProvider(): array
    {
        return [
            'int'              => [1, 'int'],
            'list with an int' => [['-oi', 1], 'int'],
        ];
    }

    /**
     * A string is a value such as a token, never the name of a function to call.
     */
    #[Test]
    public function readsStringOrCallableStringAsString(): void
    {
        static::assertSame('strtoupper', self::reader(['connection' => 'strtoupper'])->stringOrCallable('connection'));
    }

    #[Test]
    public function readsStringOrCallableInvokableAsClosure(): void
    {
        $callable = self::reader(['connection' => new UpperCase()])->stringOrCallable('connection');

        static::assertSame('TOKEN', $callable instanceof Closure ? $callable() : null);
    }

    #[Test]
    public function keepsStringOrCallableClosure(): void
    {
        $closure = static fn(): string => 'token';

        static::assertSame($closure, self::reader(['connection' => $closure])->stringOrCallable('connection'));
    }

    #[Test]
    public function readsAbsentStringOrCallableAsNull(): void
    {
        static::assertNull(self::reader([])->stringOrCallable('connection'));
    }

    #[Test]
    public function rejectsValueThatIsNeitherStringNorCallable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Example: option "connection" must be a string, a Closure or an invokable object, got array',
        );

        self::reader(['connection' => [self::class, 'missingProvider']])->stringOrCallable('connection');
    }

    #[Test]
    public function hidesEveryValueFromVarDump(): void
    {
        static::assertSame(
            ['context' => 'Example', 'values' => ['host' => '[hidden]', 'connection' => '[hidden]']],
            self::reader(['host' => 'mail.example.com', 'connection' => 'hunter2'])->__debugInfo(),
        );
    }
}
