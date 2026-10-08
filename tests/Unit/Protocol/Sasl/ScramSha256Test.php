<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Unit\Protocol\Sasl;

use Contenir\Mail\Protocol\Exception\InvalidArgumentException;
use Contenir\Mail\Protocol\Exception\RuntimeException;
use Contenir\Mail\Protocol\Sasl\SaslPrep;
use Contenir\Mail\Protocol\Sasl\ScramSha256;
use Contenir\Mail\Protocol\Smtp\Auth\Credentials;
use Contenir\Mail\Tests\Unit\TestAsset\ScramVector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function strlen;
use function substr;

/**
 * The SCRAM-SHA-256 client exchange, against the test vector of RFC 7677.
 */
#[CoversClass(ScramSha256::class)]
#[CoversClass(SaslPrep::class)]
#[Group('unit')]
final class ScramSha256Test extends TestCase
{
    #[Test]
    public function completesTheRfc7677Exchange(): void
    {
        $scram    = ScramVector::exchange();
        $first    = $scram->initialResponse();
        $response = $scram->respond(ScramVector::b64(ScramVector::SERVER_FIRST));
        $scram->verify(ScramVector::b64(ScramVector::SERVER_FINAL));

        static::assertSame(
            [ScramVector::CLIENT_FIRST, ScramVector::CLIENT_FINAL],
            [ScramVector::decode($first), ScramVector::decode($response)],
        );
    }

    #[Test]
    public function acceptsTheMostIterationsAllowed(): void
    {
        $scram    = ScramVector::exchange();
        $response = ScramVector::decode($scram->respond(ScramVector::b64(ScramVector::serverFirst('1000000'))));

        $prefix = 'c=biws,r=' . ScramVector::SERVER_NONCE . ',p=';

        static::assertSame([$prefix, 44], [
            substr($response, offset: 0, length: strlen($prefix)),
            strlen($response) - strlen($prefix),
        ]);
    }

    #[Test]
    public function acceptsExtensionsAfterTheIterationCount(): void
    {
        $scram    = ScramVector::exchange();
        $response = $scram->respond(ScramVector::b64(ScramVector::SERVER_FIRST . ',x=1'));

        static::assertSame(1, preg_match('/^c=biws,r=[^,]+,p=[^,]+$/D', ScramVector::decode($response)));
    }

    #[Test]
    public function signsTheExtensionsWithTheRestOfTheMessage(): void
    {
        $scram    = ScramVector::exchange();
        $response = ScramVector::decode($scram->respond(ScramVector::b64(ScramVector::SERVER_FIRST . ',x=1')));

        static::assertNotSame(ScramVector::CLIENT_FINAL, $response);
    }

    #[Test]
    public function acceptsExtensionsAfterTheServerSignature(): void
    {
        $scram = ScramVector::exchange();
        $scram->respond(ScramVector::b64(ScramVector::SERVER_FIRST));
        $scram->verify(ScramVector::b64(ScramVector::SERVER_FINAL . ',x=1'));

        static::assertSame(ScramVector::CLIENT_FIRST, ScramVector::decode($scram->initialResponse()));
    }

    #[Test]
    public function escapesCommasAndEqualsSignsInTheUsername(): void
    {
        $scram = new ScramSha256('a=b,c', 'secret', 'nonce');

        static::assertSame('n,,n=a=3Db=2Cc,r=nonce', ScramVector::decode($scram->initialResponse()));
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function normalisesTheUsername(): void
    {
        $scram = new ScramSha256("\u{2168}", 'secret', 'nonce');

        static::assertSame('n,,n=IX,r=nonce', ScramVector::decode($scram->initialResponse()));
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function normalisesThePassword(): void
    {
        $plain       = new ScramSha256(ScramVector::USER, 'IX', ScramVector::CLIENT_NONCE);
        $compatible  = new ScramSha256(ScramVector::USER, "\u{2168}", ScramVector::CLIENT_NONCE);
        $serverFirst = ScramVector::b64(ScramVector::SERVER_FIRST);

        static::assertSame($plain->respond($serverFirst), $compatible->respond($serverFirst));
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function refusesAPasswordThatCannotBePrepared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The SCRAM password must be UTF-8 text without control characters');

        new ScramSha256(ScramVector::USER, "pen\u{0085}cil");
    }

    #[Test]
    #[RequiresPhpExtension('intl')]
    public function refusesAUsernameThatCannotBePrepared(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The SCRAM username must be UTF-8 text without control characters');

        new ScramSha256("\xFF", ScramVector::PASSWORD);
    }

    #[Test]
    public function makesARandomNonceForEachExchange(): void
    {
        $first  = ScramVector::decode((new ScramSha256('jo', 'secret'))->initialResponse());
        $second = ScramVector::decode((new ScramSha256('jo', 'secret'))->initialResponse());

        static::assertSame(
            [1, 1, 42, true],
            [
                preg_match('#^n,,n=jo,r=[A-Za-z0-9+/]{32}$#D', $first),
                preg_match('#^n,,n=jo,r=[A-Za-z0-9+/]{32}$#D', $second),
                strlen($first),
                $first !== $second,
            ],
        );
    }

    #[Test]
    #[DataProvider('invalidNonceProvider')]
    public function refusesAnInvalidNonce(string $nonce): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The SCRAM nonce must be printable ASCII without ","');

        new ScramSha256('jo', 'secret', $nonce);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNonceProvider(): array
    {
        return [
            'empty'             => [''],
            'a comma'           => ['a,b'],
            'a space'           => ['a b'],
            'a delete'          => ["a\x7F"],
            'a trailing break'  => ["ab\n"],
            'a leading control' => ["\x01ab"],
        ];
    }

    #[Test]
    #[DataProvider('invalidServerFirstProvider')]
    public function refusesAnInvalidServerFirstMessage(string $challenge, string $message): void
    {
        $scram = ScramVector::exchange();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $scram->respond($challenge);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidServerFirstProvider(): array
    {
        $invalid  = 'The server sent an invalid SCRAM-SHA-256 challenge';
        $nonce    = "The server's SCRAM-SHA-256 nonce does not extend the client's";
        $salt     = ',s=' . ScramVector::SALT . ',i=4096';
        $tooFew   = 'The server asked for 4095 SCRAM-SHA-256 iterations; between 4096 and 1000000 are accepted';
        $tooMany  = 'The server asked for 1000001 SCRAM-SHA-256 iterations; between 4096 and 1000000 are accepted';
        $overflow = 'The server asked for 99999999999999999999 SCRAM-SHA-256 iterations';

        return [
            'not base64'                => ['*', 'The server sent a SCRAM-SHA-256 challenge that is not base64'],
            'a mandatory extension'     => [ScramVector::b64('m=x,' . ScramVector::SERVER_FIRST), $invalid],
            'no nonce'                  => [ScramVector::b64('s=' . ScramVector::SALT . ',i=4096'), $invalid],
            'no salt'                   => [ScramVector::b64('r=' . ScramVector::SERVER_NONCE . ',i=4096'), $invalid],
            'no iteration count'        => [
                ScramVector::b64('r=' . ScramVector::SERVER_NONCE . ',s=' . ScramVector::SALT),
                $invalid,
            ],
            'an iteration count suffix' => [ScramVector::b64(ScramVector::serverFirst('4096x')), $invalid],
            'a trailing line break'     => [ScramVector::b64(ScramVector::SERVER_FIRST . "\n"), $invalid],
            'an error'                  => [ScramVector::b64('e=other-error'), $invalid],
            'another nonce'             => [ScramVector::b64("r=fyko+d2lbbFgONRv9qkxdawL{$salt}"), $nonce],
            'the client nonce only'     => [ScramVector::b64('r=' . ScramVector::CLIENT_NONCE . $salt), $nonce],
            'the client nonce inside'   => [ScramVector::b64('r=x' . ScramVector::SERVER_NONCE . $salt), $nonce],
            'a salt not in base64'      => [
                ScramVector::b64('r=' . ScramVector::SERVER_NONCE . ',s=***,i=4096'),
                'The server sent a SCRAM-SHA-256 salt that is not base64',
            ],
            'too few iterations'        => [ScramVector::b64(ScramVector::serverFirst('4095')), $tooFew],
            'no iterations'             => [
                ScramVector::b64(ScramVector::serverFirst('0')),
                'The server asked for 0 SCRAM-SHA-256 iterations',
            ],
            'too many iterations'       => [ScramVector::b64(ScramVector::serverFirst('1000001')), $tooMany],
            'an overflowing count'      => [
                ScramVector::b64(ScramVector::serverFirst('99999999999999999999')),
                $overflow,
            ],
        ];
    }

    #[Test]
    #[DataProvider('invalidServerFinalProvider')]
    public function refusesAnInvalidServerFinalMessage(string $challenge, string $message): void
    {
        $scram = ScramVector::exchange();
        $scram->respond(ScramVector::b64(ScramVector::SERVER_FIRST));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $scram->verify($challenge);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidServerFinalProvider(): array
    {
        $mismatch = "The server's SCRAM-SHA-256 signature does not match: it does not know the password";
        $invalid  = 'The server sent an invalid SCRAM-SHA-256 final message';

        return [
            'not base64'             => ['*', 'The server sent a SCRAM-SHA-256 challenge that is not base64'],
            'an error'               => [
                ScramVector::b64('e=invalid-proof'),
                'The server refused the SCRAM-SHA-256 authentication: invalid-proof',
            ],
            'an error with controls' => [
                ScramVector::b64("e=other\x1B[31m-error"),
                'The server refused the SCRAM-SHA-256 authentication: other [31m-error',
            ],
            'another signature'      => [ScramVector::b64('v=' . ScramVector::SALT), $mismatch],
            'an empty signature'     => [ScramVector::b64('v='), $mismatch],
            'a signature not base64' => [ScramVector::b64('v=***'), $mismatch],
            'no signature'           => [ScramVector::b64('x=1'), $invalid],
            'a signature not first'  => [ScramVector::b64('x' . ScramVector::SERVER_FINAL), $invalid],
        ];
    }

    #[Test]
    public function refusesToVerifyBeforeResponding(): void
    {
        $scram = ScramVector::exchange();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The SCRAM-SHA-256 exchange has no client-final message to verify');

        $scram->verify(ScramVector::b64(ScramVector::SERVER_FINAL));
    }

    #[Test]
    public function hidesThePasswordFromDumps(): void
    {
        static::assertSame(['password' => Credentials::HIDDEN], ScramVector::exchange()->__debugInfo());
    }
}
