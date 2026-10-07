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
 * SendmailConfig::fromIterable(['parameters' => '-R hdrs']);
 * ```
 *
 * @mago-expect analysis:mixed-assignment Settings arrive untyped; ConfigReader reads them into types.
 */
final readonly class SendmailConfig
{
    public const array KEYS = ['parameters'];

    /**
     * Characters a sendmail argument may contain. PHP passes the parameters through a shell,
     * so quotes, spaces inside an argument, "$", "`", ";" and the like are refused.
     */
    public const string SAFE_ARGUMENT = '/^[A-Za-z0-9@._+=:,\/%-]+$/D';

    /**
     * Arguments for the sendmail command, as one list of words.
     *
     * @var list<string>
     */
    public array $parameters;

    /**
     * @param string|list<string> $parameters Extra sendmail arguments, such as "-R hdrs". When they
     *     include "-f", the envelope sender is not taken from the message.
     * @throws InvalidArgumentException When an argument contains a character outside SAFE_ARGUMENT.
     */
    public function __construct(string|array $parameters = [])
    {
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
     * @param iterable<mixed, mixed> $config Key "parameters": a string or a list of strings.
     * @throws InvalidArgumentException When a key is unknown or a value is invalid.
     */
    public static function fromIterable(iterable $config): self
    {
        $values = [];
        foreach ($config as $key => $value) {
            $values[$key] = 'parameters' === $key && is_string($value) ? [$value] : $value;
        }

        $reader = ConfigReader::read(self::class, $values, self::KEYS);

        return new self($reader->stringList('parameters', default: []));
    }

    /**
     * The parameters as the string mail() takes.
     */
    public function toString(): string
    {
        return implode(' ', $this->parameters);
    }
}
