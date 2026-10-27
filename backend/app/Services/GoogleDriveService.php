<?php

namespace App\Services;

use App\Models\GoogleDriveConnection;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use RuntimeException;

class GoogleDriveService
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private Client $http;

    public function __construct(private readonly GoogleOAuthService $oauth)
    {
        $this->http = new Client([
            'http_errors' => false,
            'timeout' => 300,
            'connect_timeout' => 20,
            'verify' => config('google.ca_bundle') ?: true,
        ]);
    }

    public function connection(): ?GoogleDriveConnection
    {
        return GoogleDriveConnection::query()->latest('connected_at')->first();
    }

    /**
     * @return array<int, array{id: string, name: string, mimeType: string, size: int, modifiedTime: ?string, iconLink: ?string}>
     */
    public function listFiles(?string $search = null): array
    {
        $connection = $this->connection();

        if (! $connection || ! $connection->canSync()) {
            throw new RuntimeException('Google Drive belum terhubung.');
        }

        $token = $this->oauth->accessTokenFor($connection);
        $query = "trashed = false and (mimeType contains 'image/' or mimeType contains 'video/')";

        if (filled($search)) {
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], trim((string) $search));
            $query .= " and name contains '{$escaped}'";
        }

        $response = $this->http->get(self::API.'/files', [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query' => [
                'q' => $query,
                'fields' => 'files(id,name,mimeType,size,modifiedTime,iconLink)',
                'pageSize' => 100,
                'orderBy' => 'modifiedTime desc',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || ! is_array($body)) {
            throw new RuntimeException($this->error($body, 'Daftar file Google Drive tidak dapat dibaca.'));
        }

        return collect($body['files'] ?? [])->map(fn (array $file): array => [
            'id' => (string) $file['id'],
            'name' => (string) $file['name'],
            'mimeType' => (string) $file['mimeType'],
            'size' => (int) ($file['size'] ?? 0),
            'modifiedTime' => $file['modifiedTime'] ?? null,
            'iconLink' => $file['iconLink'] ?? null,
        ])->values()->all();
    }

    /**
     * @return array{id: string, name: string, mimeType: string, size: int}
     */
    public function file(string $fileId): array
    {
        $connection = $this->connection();

        if (! $connection || ! $connection->canSync()) {
            throw new RuntimeException('Google Drive belum terhubung.');
        }

        $token = $this->oauth->accessTokenFor($connection);
        $response = $this->http->get(self::API."/files/{$fileId}", [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query' => [
                'fields' => 'id,name,mimeType,size',
                'supportsAllDrives' => 'true',
            ],
        ]);
        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() !== 200 || ! is_array($body)) {
            throw new RuntimeException($this->error($body, 'Metadata file Google Drive tidak dapat dibaca.'));
        }

        return [
            'id' => (string) $body['id'],
            'name' => (string) $body['name'],
            'mimeType' => (string) $body['mimeType'],
            'size' => (int) ($body['size'] ?? 0),
        ];
    }

    /**
     * @param  null|callable(int, int): void  $onProgress
     */
    public function download(string $fileId, string $destination, ?callable $onProgress = null): void
    {
        $connection = $this->connection();

        if (! $connection || ! $connection->canSync()) {
            throw new RuntimeException('Google Drive belum terhubung.');
        }

        $token = $this->oauth->accessTokenFor($connection);

        try {
            $response = $this->http->get(self::API."/files/{$fileId}", [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'query' => ['alt' => 'media', 'supportsAllDrives' => 'true'],
                'sink' => $destination,
                'progress' => static function (
                    int $downloadTotal,
                    int $downloadedBytes,
                ) use ($onProgress): void {
                    if ($onProgress !== null) {
                        $onProgress($downloadTotal, $downloadedBytes);
                    }
                },
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Download Google Drive gagal: '.$exception->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            @unlink($destination);

            throw new RuntimeException('Google Drive menolak download file.');
        }
    }

    public function disconnect(): void
    {
        $this->connection()?->delete();
    }

    private function error(mixed $body, string $fallback): string
    {
        if (! is_array($body)) {
            return $fallback;
        }

        return (string) (
            Arr::get($body, 'error.message')
            ?: Arr::get($body, 'error_description')
            ?: Arr::get($body, 'error')
            ?: $fallback
        );
    }
}
