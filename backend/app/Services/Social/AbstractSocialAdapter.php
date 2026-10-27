<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Services\Social\Exceptions\DisconnectException;
use App\Services\Social\Exceptions\PermanentSocialException;
use App\Services\Social\Exceptions\RefreshTokenException;
use App\Services\Social\Exceptions\RetryableSocialException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;

abstract class AbstractSocialAdapter implements SocialAdapter
{
    protected Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'http_errors' => false,
            'timeout' => 180,
            'connect_timeout' => 20,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function request(string $method, string $url, array $options = []): array
    {
        $lastMessage = 'Permintaan ke platform gagal.';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $response = $this->http->request($method, $url, $options);
            } catch (GuzzleException $exception) {
                if ($attempt < 3) {
                    sleep(5);

                    continue;
                }

                throw new RetryableSocialException($exception->getMessage());
            }

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();

            if (in_array($status, [200, 201], true)) {
                $decoded = json_decode($body, true);

                return is_array($decoded) ? $decoded : ['raw' => $body];
            }

            $mapped = $this->mapError($body, $status);
            $lastMessage = $mapped['message'];

            if ($mapped['type'] === 'refresh') {
                throw new RefreshTokenException($lastMessage);
            }

            if ($mapped['type'] === 'disconnect') {
                throw new DisconnectException($lastMessage);
            }

            if ($status === 429 || $status >= 500 || $mapped['type'] === 'retry') {
                if ($attempt < 3) {
                    sleep(5);

                    continue;
                }

                throw new RetryableSocialException($lastMessage);
            }

            throw new PermanentSocialException($lastMessage);
        }

        throw new RetryableSocialException($lastMessage);
    }

    /**
     * @return array{type: 'refresh'|'disconnect'|'retry'|'permanent', message: string}
     */
    abstract protected function mapError(string $body, int $status): array;

    protected function errorMessage(string $body): string
    {
        $decoded = json_decode($body, true);

        return (string) (
            Arr::get($decoded, 'error.message')
            ?: Arr::get($decoded, 'error')
            ?: Arr::get($decoded, 'message')
            ?: Arr::get($decoded, 'error_msg')
            ?: 'Platform menolak permintaan.'
        );
    }

    /**
     * @return array{type: 'refresh'|'disconnect'|'retry'|'permanent', message: string}
     */
    protected function inspect(string $body, int $status, array $rules): array
    {
        foreach ($rules as $type => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($body, $needle)) {
                    return ['type' => $type, 'message' => $this->errorMessage($body)];
                }
            }
        }

        return [
            'type' => $status >= 500 || $status === 429 ? 'retry' : 'permanent',
            'message' => $this->errorMessage($body),
        ];
    }

    protected function message(Post $post): string
    {
        return trim(trim((string) $post->caption)."\n\n".trim((string) $post->hashtags));
    }

    protected function booleanSetting(?array $settings, string $key, bool $default = true): bool
    {
        if (! is_array($settings) || ! array_key_exists($key, $settings)) {
            return $default;
        }

        return filter_var($settings[$key], FILTER_VALIDATE_BOOL);
    }

    protected function stringSetting(?array $settings, string $key, string $default = ''): string
    {
        $value = is_array($settings) ? ($settings[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }
}
