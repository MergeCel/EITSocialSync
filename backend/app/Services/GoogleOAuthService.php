<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use RuntimeException;

class GoogleOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'http_errors' => false,
            'timeout' => 60,
            'connect_timeout' => 20,
            'verify' => config('google.ca_bundle') ?: true,
        ]);
    }

    public function isConfigured(): bool
    {
        return filled(config('google.client_id')) && filled(config('google.client_secret'));
    }

    public function driveUrl(string $state): string
    {
        return $this->authUrl($state, config('google.drive_scopes'), [
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_in: int, scope: string}
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->tokenRequest([
            'client_id' => config('google.client_id'),
            'client_secret' => config('google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('google.redirect_uri'),
        ]);

        return [
            'access_token' => (string) $response['access_token'],
            'refresh_token' => isset($response['refresh_token']) ? (string) $response['refresh_token'] : null,
            'expires_in' => (int) ($response['expires_in'] ?? 3600),
            'scope' => (string) ($response['scope'] ?? ''),
        ];
    }

    public function accessTokenFor(GoogleDriveConnection $connection): string
    {
        if (
            filled($connection->access_token)
            && $connection->token_expires_at !== null
            && $connection->token_expires_at->isAfter(now()->addMinute())
        ) {
            return (string) $connection->access_token;
        }

        if (blank($connection->refresh_token)) {
            throw new RuntimeException('Sesi Google Drive berakhir. Hubungkan ulang Google Drive.');
        }

        $response = $this->tokenRequest([
            'client_id' => config('google.client_id'),
            'client_secret' => config('google.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        $connection->update([
            'access_token' => (string) $response['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ]);

        return (string) $response['access_token'];
    }

    /**
     * @return array{id: string, email: string, name: string, picture: ?string}
     */
    public function userInfo(string $accessToken): array
    {
        try {
            $response = $this->http->get(self::USERINFO_URL, [
                'headers' => ['Authorization' => "Bearer {$accessToken}"],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Tidak dapat menghubungi Google: '.$exception->getMessage());
        }

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || ! is_array($body)) {
            throw new RuntimeException($this->error($body, 'Profil Google tidak dapat dibaca.'));
        }

        return [
            'id' => (string) ($body['sub'] ?? ''),
            'email' => strtolower((string) ($body['email'] ?? '')),
            'name' => (string) ($body['name'] ?? $body['email'] ?? 'Pengguna Google'),
            'picture' => $body['picture'] ?? null,
        ];
    }

    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, string>  $extra
     */
    private function authUrl(string $state, array $scopes, array $extra): string
    {
        return self::AUTH_URL.'?'.http_build_query(array_merge([
            'client_id' => config('google.client_id'),
            'redirect_uri' => config('google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'include_granted_scopes' => 'true',
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function tokenRequest(array $params): array
    {
        try {
            $response = $this->http->post(self::TOKEN_URL, ['form_params' => $params]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Pertukaran token Google gagal: '.$exception->getMessage());
        }

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || ! is_array($body) || ! isset($body['access_token'])) {
            throw new RuntimeException($this->error($body, 'Google menolak pertukaran token.'));
        }

        return $body;
    }

    private function error(mixed $body, string $fallback): string
    {
        if (! is_array($body)) {
            return $fallback;
        }

        return (string) (
            Arr::get($body, 'error_description')
            ?: Arr::get($body, 'error.message')
            ?: Arr::get($body, 'error')
            ?: $fallback
        );
    }
}
