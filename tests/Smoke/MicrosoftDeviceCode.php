<?php

declare(strict_types=1);

namespace Contenir\Mail\Tests\Smoke;

use RuntimeException;

use function file_get_contents;
use function fwrite;
use function http_build_query;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function sleep;
use function sprintf;
use function stream_context_create;
use function time;

use const STDERR;

/**
 * Gets a Microsoft access token with the device code flow: you open a web page,
 * type the code shown, and sign in; nothing else is needed than an app
 * registration that allows public client flows.
 *
 * @mago-expect lint:cyclomatic-complexity The device code flow: the request, the polling, and each reply Microsoft can give.
 */
final class MicrosoftDeviceCode
{
    private const string SCOPES =
        'offline_access https://outlook.office.com/SMTP.Send '
            . 'https://outlook.office.com/POP.AccessAsUser.All https://outlook.office.com/IMAP.AccessAsUser.All';

    /**
     * @throws RuntimeException When Microsoft refuses the request or the sign-in is not finished in time.
     */
    public function token(Provider $provider, string $clientId): string
    {
        $base   = sprintf('https://login.microsoftonline.com/%s/oauth2/v2.0', $provider->tenant());
        $device = self::post("{$base}/devicecode", ['client_id' => $clientId, 'scope' => self::SCOPES]);
        if (! is_string($device['device_code'] ?? null) || ! is_string($device['message'] ?? null)) {
            throw new RuntimeException('Microsoft refused the device code request: ' . self::error($device));
        }

        fwrite(STDERR, "{$device['message']}\n");
        $interval = is_int($device['interval'] ?? null) ? $device['interval'] : 5;
        $deadline = time() + (is_int($device['expires_in'] ?? null) ? $device['expires_in'] : 900);
        while (time() < $deadline) {
            sleep($interval);
            $token = self::post("{$base}/token", [
                'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id'   => $clientId,
                'device_code' => $device['device_code'],
            ]);
            if (is_string($token['access_token'] ?? null)) {
                return $token['access_token'];
            }

            if ('authorization_pending' !== ($token['error'] ?? null)) {
                throw new RuntimeException('Microsoft did not issue a token: ' . self::error($token));
            }
        }

        throw new RuntimeException('The sign-in was not finished in time');
    }

    /**
     * @param array<string, string> $form
     * @return array<mixed>
     */
    private static function post(string $url, array $form): array
    {
        $body = file_get_contents($url, context: stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query($form),
            'ignore_errors' => true,
        ]]));
        $data = json_decode((string) $body, associative: true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<mixed> $response
     */
    private static function error(array $response): string
    {
        $description = $response['error_description'] ?? $response['error'] ?? 'no reason given';

        return is_string($description) ? $description : 'no reason given';
    }
}
