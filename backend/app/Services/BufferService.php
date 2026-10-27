<?php

namespace App\Services;

use App\Models\BufferConnection;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\PublishResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BufferService
{
    private Client $http;

    public function __construct()
    {
        $this->http = new Client([
            'http_errors' => false,
            'timeout' => 60,
            'connect_timeout' => 20,
            'verify' => config('social.buffer.ca_bundle') ?: true,
        ]);
    }

    /**
     * @return array{account_id:string,account_name:?string,account_email:?string,organization_id:string,organization_name:?string}
     */
    public function profile(string $apiKey): array
    {
        $data = $this->graphql($apiKey, <<<'GRAPHQL'
            query SocialSyncAccount {
              account {
                id
                name
                email
                organizations { id name ownerEmail }
              }
            }
            GRAPHQL);
        $account = $data['account'] ?? null;
        $organization = is_array($account) ? ($account['organizations'][0] ?? null) : null;

        if (! is_array($account) || blank($account['id'] ?? null) || ! is_array($organization) || blank($organization['id'] ?? null)) {
            throw new RuntimeException('Akun Buffer tidak memiliki organisasi yang dapat digunakan.');
        }

        return [
            'account_id' => (string) $account['id'],
            'account_name' => filled($account['name'] ?? null) ? (string) $account['name'] : null,
            'account_email' => filled($account['email'] ?? null) ? strtolower((string) $account['email']) : null,
            'organization_id' => (string) $organization['id'],
            'organization_name' => filled($organization['name'] ?? null) ? (string) $organization['name'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function channels(string $apiKey, string $organizationId): array
    {
        $data = $this->graphql($apiKey, <<<'GRAPHQL'
            query SocialSyncChannels($organizationId: OrganizationId!) {
              channels(input: { organizationId: $organizationId }) {
                id
                name
                displayName
                service
                avatar
                isQueuePaused
                isDisconnected
                isLocked
                organizationId
                serviceId
                externalLink
                type
              }
            }
            GRAPHQL, ['organizationId' => $organizationId]);

        return array_values(array_filter($data['channels'] ?? [], 'is_array'));
    }

    /**
     * Synchronize the authenticated user's supported Buffer channels.
     *
     * @return array<int, SocialAccount>
     */
    public function syncChannels(BufferConnection $connection): array
    {
        $channels = collect($this->channels($connection->api_key, $connection->organization_id))
            ->map(function (array $channel): ?array {
                $platform = $this->platform((string) ($channel['service'] ?? ''));

                if ($platform === null || ($channel['isDisconnected'] ?? false) || ($channel['isLocked'] ?? false)) {
                    return null;
                }

                return ['platform' => $platform, 'channel' => $channel];
            })
            ->filter()
            ->values();

        $maximum = (int) config('social.buffer.max_channels', 3);

        if ($channels->count() > $maximum) {
            throw new RuntimeException("Akun Buffer memiliki {$channels->count()} channel aktif. SocialSync membatasi maksimal {$maximum} channel per pengguna.");
        }

        return DB::transaction(function () use ($connection, $channels): array {
            $syncedIds = [];

            foreach ($channels as $item) {
                $channel = $item['channel'];
                $channelId = (string) $channel['id'];
                $existing = SocialAccount::query()
                    ->where('platform', $item['platform'])
                    ->where('internal_id', $channelId)
                    ->first();

                if ($existing && (int) $existing->connected_by !== (int) $connection->user_id) {
                    throw new RuntimeException('Salah satu channel Buffer sudah terhubung ke pengguna SocialSync lain.');
                }

                $account = SocialAccount::query()->updateOrCreate(
                    ['platform' => $item['platform'], 'internal_id' => $channelId],
                    [
                        'account_name' => (string) ($channel['displayName'] ?: $channel['name'] ?: ucfirst($item['platform'])),
                        'handle' => filled($channel['name'] ?? null) ? (string) $channel['name'] : null,
                        'page_id' => filled($channel['serviceId'] ?? null) ? (string) $channel['serviceId'] : null,
                        'access_token' => null,
                        'refresh_token' => null,
                        'token_expires_at' => null,
                        'status' => 'connected',
                        'metadata' => [
                            'provider' => 'buffer',
                            'avatar' => $channel['avatar'] ?? null,
                            'external_link' => $channel['externalLink'] ?? null,
                            'channel_type' => $channel['type'] ?? null,
                            'queue_paused' => (bool) ($channel['isQueuePaused'] ?? false),
                        ],
                        'connected_by' => $connection->user_id,
                    ],
                );
                $syncedIds[] = $account->id;
            }

            SocialAccount::query()
                ->where('connected_by', $connection->user_id)
                ->where('metadata->provider', 'buffer')
                ->when($syncedIds !== [], fn ($query) => $query->whereNotIn('id', $syncedIds))
                ->delete();

            $connection->update([
                'status' => 'active',
                'last_synced_at' => now(),
                'last_error' => null,
            ]);

            return SocialAccount::query()
                ->whereIn('id', $syncedIds)
                ->orderBy('platform')
                ->get()
                ->all();
        });
    }

    /**
     * @param  array<int, array{url:string,thumb_url:string,kind:string,path:string}>  $media
     */
    public function publish(BufferConnection $connection, SocialAccount $account, Post $post, PostPlatform $platform, array $media): PublishResult
    {
        $assets = collect($media)->map(function (array $item): array {
            if ($item['kind'] === 'video') {
                return ['video' => ['url' => $item['url'], 'metadata' => ['thumbnailOffset' => 0]]];
            }

            return ['image' => ['url' => $item['url'], 'thumbnailUrl' => $item['thumb_url']]];
        })->values()->all();

        $text = trim(implode("\n\n", array_filter([$post->caption, $post->hashtags])));
        $data = $this->graphql($connection->api_key, <<<'GRAPHQL'
            mutation SocialSyncCreatePost($input: CreatePostInput!) {
              createPost(input: $input) {
                __typename
                ... on PostActionSuccess { post { id externalLink status } }
                ... on MutationError { message }
              }
            }
            GRAPHQL, [
            'input' => [
                'text' => $text,
                'channelId' => $account->internal_id,
                'assets' => $assets,
                'metadata' => $this->postMetadata($account, $post),
                'mode' => 'shareNow',
                'schedulingType' => 'automatic',
            ],
        ]);
        $result = $data['createPost'] ?? null;

        if (! is_array($result) || ($result['__typename'] ?? null) !== 'PostActionSuccess' || blank($result['post']['id'] ?? null)) {
            throw new RuntimeException((string) ($result['message'] ?? 'Buffer menolak publikasi post.'));
        }

        return new PublishResult((string) $result['post']['id'], $result['post']['externalLink'] ?? null);
    }

    /** @return array<string, int> */
    public function metrics(BufferConnection $connection, string $bufferPostId): array
    {
        $data = $this->graphql($connection->api_key, <<<'GRAPHQL'
            query SocialSyncPostMetrics($id: PostId!) {
              post(input: { id: $id }) {
                metrics { type name value unit }
              }
            }
            GRAPHQL, ['id' => $bufferPostId]);
        $result = ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];

        foreach ($data['post']['metrics'] ?? [] as $metric) {
            $label = strtolower((string) (($metric['name'] ?? '').' '.($metric['type'] ?? '')));

            foreach (array_keys($result) as $key) {
                $aliases = $key === 'views' ? ['view', 'reach', 'impression', 'play'] : [rtrim($key, 's')];

                if (collect($aliases)->contains(fn (string $alias): bool => str_contains($label, $alias))) {
                    $result[$key] = max($result[$key], (int) round((float) ($metric['value'] ?? 0)));
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $apiKey, string $query, array $variables = []): array
    {
        try {
            $response = $this->http->post((string) config('social.buffer.api_url'), [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => ['query' => $query, 'variables' => (object) $variables],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Buffer tidak dapat dihubungi. Coba lagi beberapa saat.', previous: $exception);
        }

        $body = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() === 401 || $response->getStatusCode() === 403) {
            throw new RuntimeException('API key Buffer tidak valid atau tidak memiliki izin.');
        }

        if ($response->getStatusCode() >= 400 || ! is_array($body)) {
            throw new RuntimeException('Buffer mengembalikan respons yang tidak valid.');
        }

        if (! empty($body['errors'])) {
            $message = (string) ($body['errors'][0]['message'] ?? 'Permintaan Buffer gagal.');
            throw new RuntimeException(mb_substr($message, 0, 500));
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    private function platform(string $service): ?string
    {
        $service = strtolower($service);

        return match (true) {
            str_contains($service, 'instagram') => 'instagram',
            str_contains($service, 'tiktok') => 'tiktok',
            str_contains($service, 'facebook') => 'facebook',
            default => null,
        };
    }

    /** @return array<string, array<string, mixed>|object> */
    private function postMetadata(SocialAccount $account, Post $post): array
    {
        $type = match ($post->content_type) {
            'story' => 'story',
            'video' => 'reel',
            default => 'post',
        };

        return match ($account->platform) {
            'instagram' => [
                'instagram' => [
                    'type' => $type,
                    'shouldShareToFeed' => $type !== 'story',
                ],
            ],
            'facebook' => ['facebook' => ['type' => $type]],
            'tiktok' => ['tiktok' => in_array($post->content_type, ['photo', 'carousel'], true)
                ? ['title' => mb_substr($post->title, 0, 90)]
                : (object) []],
            default => [],
        };
    }
}
