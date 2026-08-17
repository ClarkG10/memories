<?php

declare(strict_types=1);

namespace App\Services\GoogleDrive;

use Google\Client;
use Google\Service\Drive;
use InvalidArgumentException;
use RuntimeException;

/**
 * Builds the authenticated Google client.
 *
 * Two strategies, because which one works depends on the kind of Google
 * account behind the archive:
 *
 *  - oauth: the owner grants access once and we keep a refresh token. Files
 *    belong to that account and use its storage. Works with a plain Gmail
 *    account, which is the common case for a personal archive.
 *
 *  - service_account: only viable against a Workspace shared drive or with
 *    domain-wide delegation. A standalone service account owns no storage, so
 *    every upload would fail with storageQuotaExceeded.
 */
class GoogleDriveClientFactory
{
    private ?Client $client = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public function client(): Client
    {
        return $this->client ??= $this->build();
    }

    public function drive(): Drive
    {
        return new Drive($this->client());
    }

    /**
     * Whether credentials are present. Used by the health check so the archive
     * can report a clear "Drive is not connected" instead of failing mid-upload.
     */
    public function isConfigured(): bool
    {
        return match ($this->strategy()) {
            'oauth' => filled($this->config['oauth']['client_id'] ?? null)
                && filled($this->config['oauth']['client_secret'] ?? null)
                && filled($this->config['oauth']['refresh_token'] ?? null),
            'service_account' => $this->serviceAccountCredentials() !== null,
            default => false,
        };
    }

    public function strategy(): string
    {
        return (string) ($this->config['auth'] ?? 'oauth');
    }

    /**
     * A client that can complete the consent handshake but has no token yet.
     * Used only by `php artisan drive:authorize`.
     */
    public function consentClient(): Client
    {
        $client = $this->baseClient();

        $client->setClientId((string) $this->config['oauth']['client_id']);
        $client->setClientSecret((string) $this->config['oauth']['client_secret']);
        $client->setRedirectUri((string) $this->config['oauth']['redirect_uri']);

        // Google only returns a refresh token on the first consent, and only
        // when both of these are set.
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    private function build(): Client
    {
        return match ($this->strategy()) {
            'oauth' => $this->buildOauthClient(),
            'service_account' => $this->buildServiceAccountClient(),
            default => throw new InvalidArgumentException(
                "Unknown GOOGLE_DRIVE_AUTH value [{$this->strategy()}]. Expected 'oauth' or 'service_account'."
            ),
        };
    }

    private function buildOauthClient(): Client
    {
        $oauth = $this->config['oauth'];

        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (blank($oauth[$key] ?? null)) {
                throw new RuntimeException(
                    'Google Drive is not connected: GOOGLE_DRIVE_'.strtoupper($key).' is missing. '.
                    'Run `php artisan drive:authorize` to obtain a refresh token.'
                );
            }
        }

        $client = $this->baseClient();
        $client->setClientId((string) $oauth['client_id']);
        $client->setClientSecret((string) $oauth['client_secret']);

        /*
         | Seed a deliberately expired token carrying the refresh token, rather
         | than calling refreshToken() here: that would fire a network request
         | every time the container resolves this service, including in tests
         | and on requests that never touch Drive. The library exchanges it for
         | a live access token on the first actual API call instead.
         */
        $client->setAccessToken([
            'access_token' => '',
            'refresh_token' => (string) $oauth['refresh_token'],
            'expires_in' => 0,
            'created' => 0,
        ]);

        return $client;
    }

    private function buildServiceAccountClient(): Client
    {
        $credentials = $this->serviceAccountCredentials();

        if ($credentials === null) {
            throw new RuntimeException(
                'Google Drive is not connected: set GOOGLE_DRIVE_CREDENTIALS_PATH or '.
                'GOOGLE_DRIVE_CREDENTIALS_JSON_BASE64 for service account authentication.'
            );
        }

        $client = $this->baseClient();
        $client->setAuthConfig($credentials);

        if (filled($this->config['service_account']['impersonate'] ?? null)) {
            $client->setSubject((string) $this->config['service_account']['impersonate']);
        }

        return $client;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serviceAccountCredentials(): ?array
    {
        $base64 = $this->config['service_account']['credentials_json_base64'] ?? null;

        if (filled($base64)) {
            $json = base64_decode((string) $base64, true);

            if ($json === false) {
                throw new RuntimeException('GOOGLE_DRIVE_CREDENTIALS_JSON_BASE64 is not valid base64.');
            }

            $decoded = json_decode($json, true);

            if (! is_array($decoded)) {
                throw new RuntimeException('GOOGLE_DRIVE_CREDENTIALS_JSON_BASE64 does not contain valid JSON.');
            }

            return $decoded;
        }

        $path = $this->config['service_account']['credentials_path'] ?? null;

        if (blank($path)) {
            return null;
        }

        if (! is_readable((string) $path)) {
            throw new RuntimeException("Google Drive credentials file is not readable: {$path}");
        }

        $decoded = json_decode((string) file_get_contents((string) $path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Google Drive credentials file is not valid JSON: {$path}");
        }

        return $decoded;
    }

    private function baseClient(): Client
    {
        $client = new Client([
            'retry' => [
                'retries' => (int) ($this->config['retries'] ?? 3),
            ],
        ]);

        $client->setApplicationName(config('app.name', 'Memories'));
        $client->setScopes([(string) $this->config['scope']]);
        $client->setHttpClient(new \GuzzleHttp\Client([
            'timeout' => (int) ($this->config['timeout'] ?? 120),
            'connect_timeout' => (int) ($this->config['connect_timeout'] ?? 10),
        ]));

        return $client;
    }
}
