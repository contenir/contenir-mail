<?php

declare(strict_types=1);

namespace Contenir\Mail\Container;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Transport;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Traversable;

use function get_debug_type;
use function implode;
use function is_array;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function str_replace;
use function strtolower;

/**
 * Creates the TransportInterface service from `$config['mail']['transport']`:
 *
 * ```php
 * 'mail' => [
 *     'transport' => [
 *         'type' => 'smtp',
 *         'host' => 'smtp.example.com',
 *         'auth' => ['type' => 'login', 'username' => 'orders', 'password' => getenv('SMTP_PASSWORD')],
 *     ],
 * ],
 * ```
 *
 * "type" is smtp, sendmail (the default), file or in-memory; the other keys are passed to
 * that transport's Config.
 *
 * @mago-expect analysis:mixed-assignment Container configuration arrives untyped; each Config reads it into types.
 */
final readonly class TransportFactory
{
    /** The accepted "type" values; "-" and "_" are ignored, so "in-memory" also works */
    public const array TYPES = ['smtp', 'sendmail', 'file', 'inmemory'];

    /**
     * @throws InvalidArgumentException When the configuration is not an array, the type is unknown,
     *     or the transport's settings are invalid.
     * @throws ContainerExceptionInterface When the container cannot provide its "config".
     */
    public function __invoke(ContainerInterface $container): Transport\TransportInterface
    {
        $config    = $container->has('config') ? $container->get('config') : [];
        $mail      = self::array($config, 'config')['mail'] ?? [];
        $transport = self::array($mail, 'config["mail"]')['transport'] ?? [];
        $transport = self::array($transport, 'config["mail"]["transport"]');

        $type = $transport['type'] ?? 'sendmail';
        unset($transport['type']);
        if (! is_string($type)) {
            throw new InvalidArgumentException(sprintf(
                'config["mail"]["transport"]["type"] must be one of %s, got %s',
                implode(', ', self::TYPES),
                get_debug_type($type),
            ));
        }

        return match (str_replace(
            search: ['-', '_'],
            replace: '',
            subject: strtolower($type),
        )) {
            'smtp'     => new Transport\Smtp(Transport\SmtpConfig::fromIterable($transport)),
            'sendmail' => new Transport\Sendmail(Transport\SendmailConfig::fromIterable($transport)),
            'file'     => new Transport\File(Transport\FileConfig::fromIterable($transport)),
            'inmemory' => self::inMemory($transport),
            default    => throw new InvalidArgumentException(sprintf(
                'config["mail"]["transport"]["type"] "%s" is unknown; expected one of %s',
                $type,
                implode(', ', self::TYPES),
            )),
        };
    }

    /**
     * @param array<array-key, mixed> $settings
     * @throws InvalidArgumentException When settings are given, since InMemory takes none.
     */
    private static function inMemory(array $settings): Transport\InMemory
    {
        if ([] !== $settings) {
            throw new InvalidArgumentException('The in-memory transport takes no settings besides "type"');
        }

        return new Transport\InMemory();
    }

    /**
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException When the value is neither an array nor Traversable.
     */
    private static function array(mixed $value, string $name): array
    {
        if ($value instanceof Traversable) {
            return iterator_to_array($value);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException(sprintf('%s must be an array, got %s', $name, get_debug_type($value)));
        }

        return $value;
    }
}
