<?php

declare(strict_types=1);

namespace Contenir\Mail\Protocol;

use Contenir\Mail\ConfigReader;
use Contenir\Mail\Exception\InvalidArgumentException;

use function array_filter;
use function preg_match;
use function sprintf;

/**
 * TLS settings beyond peer verification: which certificate authorities to trust,
 * the name the certificate must carry, and a client certificate.
 *
 * Every setting is off unless given, so the defaults stay those of PHP and the
 * system: the system's certificate authorities, and the host name as the peer
 * name. Nothing here turns peer verification off; ConnectionConfig::$verifyPeer
 * alone does that. allowSelfSigned is the one setting that weakens it, and only
 * when set explicitly.
 *
 * ```php
 * new ConnectionConfig('mail.internal', tls: new TlsConfig(caFile: '/etc/ssl/internal-ca.pem'));
 * ConnectionConfig::fromIterable(['host' => 'mail.internal', 'cafile' => '/etc/ssl/internal-ca.pem']);
 * ```
 *
 * @api
 *
 * @mago-expect lint:excessive-parameter-list Built with named arguments; every setting is optional.
 */
final readonly class TlsConfig
{
    /**
     * The settings read by fromReader(), named as PHP's ssl context options are
     *
     * @var list<string>
     */
    public const array KEYS = ['cafile', 'capath', 'peer_name', 'allow_self_signed', 'local_cert', 'local_pk'];

    /** C0 controls, DEL, and NUL: a path or name holding one is refused */
    private const string CONTROLS = '/[\x00-\x1F\x7F]/';

    /**
     * @param string|null $caFile A file of certificate authorities to trust, in place of the system's.
     * @param string|null $caPath A directory of hashed certificate authority files to trust.
     * @param string|null $peerName The name the server's certificate must carry, when it differs from the host.
     * @param bool $allowSelfSigned Accept a certificate that signs itself. Weakens verification; off by default.
     * @param string|null $localCert A client certificate to present, in PEM, which may hold its private key.
     * @param string|null $localPrivateKey The client certificate's private key, when it is in a file of its own.
     * @throws InvalidArgumentException When a value is empty or holds a control character,
     *     or a private key is given without a certificate.
     */
    public function __construct(
        public ?string $caFile = null,
        public ?string $caPath = null,
        public ?string $peerName = null,
        public bool $allowSelfSigned = false,
        public ?string $localCert = null,
        public ?string $localPrivateKey = null,
    ) {
        self::check('cafile', $caFile);
        self::check('capath', $caPath);
        self::check('peer_name', $peerName);
        self::check('local_cert', $localCert);
        self::check('local_pk', $localPrivateKey);
        if (null !== $localPrivateKey && null === $localCert) {
            throw new InvalidArgumentException('A TLS private key (local_pk) needs its certificate (local_cert)');
        }
    }

    /**
     * Read the TLS settings from a reader that also holds other keys, such as ConnectionConfig's.
     *
     * @internal
     * @throws InvalidArgumentException When a value has the wrong type or is invalid.
     */
    public static function fromReader(ConfigReader $reader): self
    {
        return new self(
            caFile: $reader->nullableString('cafile'),
            caPath: $reader->nullableString('capath'),
            peerName: $reader->nullableString('peer_name'),
            allowSelfSigned: $reader->bool('allow_self_signed', default: false),
            localCert: $reader->nullableString('local_cert'),
            localPrivateKey: $reader->nullableString('local_pk'),
        );
    }

    /**
     * The ssl context options for the settings given, and none for the rest.
     *
     * @return array<string, string|true>
     */
    public function contextOptions(): array
    {
        $options = [
            'cafile'     => $this->caFile,
            'capath'     => $this->caPath,
            'peer_name'  => $this->peerName,
            'local_cert' => $this->localCert,
            'local_pk'   => $this->localPrivateKey,
        ];
        $options = array_filter($options, static fn(?string $value): bool => null !== $value);
        if ($this->allowSelfSigned) {
            $options['allow_self_signed'] = true;
        }

        return $options;
    }

    /**
     * @throws InvalidArgumentException When the value is empty or holds a control character.
     */
    private static function check(string $name, ?string $value): void
    {
        if (null !== $value && ('' === $value || 1 === preg_match(self::CONTROLS, $value))) {
            throw new InvalidArgumentException(sprintf(
                'TLS setting "%s" must not be empty or contain control characters',
                $name,
            ));
        }
    }
}
