<?php

declare(strict_types=1);

namespace Contenir\Mail\Transport;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;

use function explode;
use function implode;
use function is_string;
use function preg_match;
use function preg_replace;
use function sprintf;
use function trim;

/**
 * Settings for the Sendmail transport.
 *
 * ```php
 * new SendmailConfig(parameters: ['-R', 'hdrs']);
 * new SendmailConfig(path: '/usr/sbin/sendmail');
 * SendmailConfig::fromIterable(['path' => '/usr/sbin/sendmail', 'parameters' => '-R hdrs']);
 * ```
 *
 * Without a path, mail is sent with PHP's mail() function. With one, that program is run
 * directly, without a shell, with the message on its standard input.
 */
final readonly class SendmailConfig
{
    public const array KEYS = ['path', 'parameters'];

    /**
     * Characters a sendmail argument may contain. mail() passes the parameters through a shell,
     * so quotes, spaces inside an argument, "$", "`", ";" and the like are refused.
     */
    public const string SAFE_ARGUMENT = '/^[A-Za-z0-9@._+=:,\/%-]+$/D';

    /**
     * Arguments for the sendmail command, as one list of words.
     *
     * @var list<string>
     */
    public array $parameters;

    /** The sendmail program to run, or null to send with mail() */
    public ?string $path;

    /**
     * @param string|list<string> $parameters Extra sendmail arguments, such as "-R hdrs". When they
     *     include "-f", the envelope sender is not taken from the message.
     * @param string|null $path The sendmail program to run without a shell, such as "/usr/sbin/sendmail";
     *     null to send with mail(), which runs the sendmail_path set in php.ini.
     * @throws InvalidArgumentException When an argument contains a character outside SAFE_ARGUMENT,
     *     or the path is empty or contains a control character.
     */
    public function __construct(string|array $parameters = [], ?string $path = null)
    {
        if (null !== $path && 1 !== preg_match('/^[^\x00-\x1F\x7F]+$/D', $path)) {
            throw new InvalidArgumentException(
                'The sendmail path must be a program path, not empty and without control characters',
            );
        }

        $this->path = $path;

        $text  = trim(implode(' ', is_string($parameters) ? [$parameters] : $parameters), characters: " \t");
        $words = '' === $text ? [] : explode(' ', (string) preg_replace('/[ \t]+/', replacement: ' ', subject: $text));
        foreach ($words as $word) {
            if (1 !== preg_match(self::SAFE_ARGUMENT, $word)) {
                throw new InvalidArgumentException(sprintf(
                    'Sendmail parameter "%s" may only contain letters, digits and @ . _ + = : , / %% -',
                    $word,
                ));
            }
        }

        $this->parameters = $words;
    }

    /**
     * @param iterable<mixed, mixed> $config Keys "path" and "parameters", a string or a list of strings.
     * @throws InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(iterable $config): self
    {
        $reader = ConfigReader::read(self::class, $config, self::KEYS);

        return new self($reader->stringOrList('parameters', default: []), $reader->nullableString('path'));
    }

    /**
     * The parameters as the string mail() takes.
     */
    public function toString(): string
    {
        return implode(' ', $this->parameters);
    }
}
