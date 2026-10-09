<?php

declare(strict_types=1);

namespace Contenir\Mail\Container;

use Contenir\Mail\Exception\InvalidArgumentException;
use Contenir\Mail\Transport;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use SensitiveParameter;
use Traversable;

use function get_debug_type;
use function implode;
use function is_array;
use function is_string;
use function iterator_to_array;
use function sprintf;
use function str_replace;
use function strtolower;
use function var_export;

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
 * "type" is required: smtp, sendmail, file or in-memory. The other keys are passed to that
 * transport's Config.
 *
 * @mago-expect analysis:mixed-assignment Container configuration arrives untyped; each Config reads it into types.
 * @api
 */
final readonly class TransportFactory
{
    /** The accepted "type" values; "-" and "_" are ignored, so "in-memory" also works */
    public const array TYPES = ['smtp', 'sendmail', 'file', 'inmemory', 'failover'];

    /**
     * @throws InvalidArgumentException When the configuration is not an array, the type is missing or
     *     unknown, or the transport's settings are invalid.
     * @throws ContainerExceptionInterface When the container cannot provide its "config".
     */
    public function __invoke(ContainerInterface $container): Transport\TransportInterface
    {
        $config    = $container->has('config') ? $container->get('config') : [];
        $mail      = self::array($config, 'config')['mail'] ?? [];
        $transport = self::array($mail, 'config["mail"]')['transport'] ?? [];

        return self::create($transport, 'config["mail"]["transport"]');
    }

    /**
     * Build the transport a configuration names, a failover's transports included.
     *
     * @throws InvalidArgumentException When the configuration is not an array, the type is missing or
     *     unknown, or the transport's settings are invalid.
     */
    private static function create(#[SensitiveParameter] mixed $settings, string $path): Transport\TransportInterface
    {
        $transport = self::array($settings, $path);
        $type      = $transport['type'] ?? null;
        unset($transport['type']);
        if (null === $type) {
            throw new InvalidArgumentException(sprintf(
                '%s["type"] is required; set it to one of %s',
                $path,
                implode(', ', self::TYPES),
            ));
        }

        if (! is_string($type)) {
            throw new InvalidArgumentException(sprintf(
                '%s["type"] must be one of %s, got %s',
                $path,
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
            'failover' => self::failover($transport, $path),
            default    => throw new InvalidArgumentException(sprintf(
                '%s["type"] "%s" is unknown; expected one of %s',
                $path,
                $type,
                implode(', ', self::TYPES),
            )),
        };
    }

    /**
     * @param array<array-key, mixed> $settings
     * @throws InvalidArgumentException When "transports" is missing or empty, another setting is given,
     *     or a transport's configuration is invalid.
     */
    private static function failover(#[SensitiveParameter] array $settings, string $path): Transport\Failover
    {
        $list = self::array($settings['transports'] ?? [], "{$path}[\"transports\"]");
        unset($settings['transports']);
        if ([] !== $settings) {
            throw new InvalidArgumentException(sprintf('%s takes only "type" and "transports"', $path));
        }

        if ([] === $list) {
            throw new InvalidArgumentException(sprintf('%s["transports"] needs at least one transport', $path));
        }

        $transports = [];
        foreach ($list as $key => $transport) {
            $transports[] = self::create($transport, sprintf(
                '%s["transports"][%s]',
                $path,
                var_export($key, return: true),
            ));
        }

        return new Transport\Failover(...$transports);
    }

    /**
     * @param array<array-key, mixed> $settings
     * @throws InvalidArgumentException When settings are given, since InMemory takes none.
     */
    private static function inMemory(#[SensitiveParameter] array $settings): Transport\InMemory
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
    private static function array(#[SensitiveParameter] mixed $value, string $name): array
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
