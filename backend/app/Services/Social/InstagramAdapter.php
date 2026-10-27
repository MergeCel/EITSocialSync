<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Exceptions\PermanentSocialException;
use App\Services\Social\Exceptions\RetryableSocialException;
use Illuminate\Support\Arr;

class InstagramAdapter extends AbstractSocialAdapter
{
    public function platform(): string
    {
        return 'instagram';
    }

    private function graphBase(): string
    {
        return 'https://graph.facebook.com/'.config('social.graph_version', 'v25.0');
    }

    public function authUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/'.config('social.graph_version', 'v25.0').'/dialog/oauth?'.http_build_query([
            'client_id' => config('social.instagram.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', [
                'instagram_basic',
                'pages_show_list',
                'pages_read_engagement',
                'business_management',
                'instagram_content_publish',
                'instagram_manage_comments',
                'instagram_manage_insights',
            ]),
        ]);
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function authenticateCode(string $code, string $redirectUri): array
    {
        $short = $this->request('GET', $this->graphBase().'/oauth/access_token', [
            'query' => [
                'client_id' => config('social.instagram.client_id'),
                'redirect_uri' => $redirectUri,
                'client_secret' => config('social.instagram.client_secret'),
                'code' => $code,
            ],
        ]);
        $long = $this->request('GET', $this->graphBase().'/oauth/access_token', [
            'query' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('social.instagram.client_id'),
                'client_secret' => config('social.instagram.client_secret'),
                'fb_exchange_token' => $short['access_token'],
            ],
        ]);

        return [
            'access_token' => (string) $long['access_token'],
            'expires_in' => (int) ($long['expires_in'] ?? 59 * 24 * 60 * 60),
        ];
    }

    /**
     * @return array<int, array{id: string, page_id: string, name: string, handle: string, access_token: string, picture: ?string}>
     */
    public function listAccounts(string $userToken): array
    {
        $accounts = [];
        $response = $this->request('GET', $this->graphBase().'/me/accounts', [
            'query' => [
                'fields' => 'id,instagram_business_account,access_token,name,picture.type(large)',
                'limit' => 100,
                'access_token' => $userToken,
            ],
        ]);

        foreach ($response['data'] ?? [] as $page) {
            $this->addPageAccount($accounts, $page, $userToken);
        }

        try {
            $businesses = $this->request('GET', $this->graphBase().'/me/businesses', [
                'query' => ['fields' => 'id', 'limit' => 100, 'access_token' => $userToken],
            ]);

            foreach ($businesses['data'] ?? [] as $business) {
                foreach (['owned_pages', 'client_pages'] as $edge) {
                    try {
                        $pages = $this->request('GET', $this->graphBase()."/{$business['id']}/{$edge}", [
                            'query' => [
                                'fields' => 'id,instagram_business_account,access_token,name,picture.type(large)',
                                'limit' => 100,
                                'access_token' => $userToken,
                            ],
                        ]);
                    } catch (\Throwable) {
                        continue;
                    }

                    foreach ($pages['data'] ?? [] as $page) {
                        $this->addPageAccount($accounts, $page, $userToken);
                    }
                }
            }
        } catch (\Throwable) {
            // Business pages are optional; personal page access is already collected.
        }

        return array_values($accounts);
    }

    public function publish(SocialAccount $account, Post $post, PostPlatform $platform, array $media): PublishResult
    {
        [$pageToken] = $this->tokens($account);
        $igId = (string) $account->internal_id;
        $message = $this->message($post);
        $settings = $platform->advanced_settings ?? [];

        if ($post->content_type === 'story') {
            return $this->publishStory($igId, $pageToken, $media[0]);
        }

        $containers = [];

        if ($post->content_type === 'carousel') {
            foreach ($media as $item) {
                $containers[] = $this->createContainer($igId, $pageToken, $item, ['is_carousel_item' => 'true']);
            }

            foreach ($containers as $container) {
                $this->waitForContainer($container, $pageToken);
            }

            $carousel = $this->createContainer($igId, $pageToken, null, [
                'media_type' => 'CAROUSEL',
                'children' => implode(',', $containers),
                'caption' => $message,
            ]);
            $this->waitForContainer($carousel, $pageToken);
            $publishedId = $this->publishContainer($igId, $pageToken, $carousel);
        } else {
            $item = $media[0];
            $extra = ['caption' => $message];

            if ($item['kind'] === 'video') {
                $extra['media_type'] = 'REELS';

                if ($item['thumb_url'] !== '') {
                    $extra['cover_url'] = $item['thumb_url'];
                }
            }

            $collaborators = $this->stringSetting($settings, 'collaborators');

            if ($collaborators !== '') {
                $extra['collaborators'] = json_encode(array_values(array_filter(array_map(
                    fn (string $value): string => ltrim(trim($value), '@'),
                    explode(',', $collaborators),
                ))));
            }

            $container = $this->createContainer($igId, $pageToken, $item, $extra);
            $this->waitForContainer($container, $pageToken);
            $publishedId = $this->publishContainer($igId, $pageToken, $container);
        }

        return new PublishResult($publishedId, $this->permalink($publishedId, $pageToken, $account));
    }

    public function fetchInsights(SocialAccount $account, PostPlatform $platform): array
    {
        [$pageToken] = $this->tokens($account);
        $id = (string) $platform->platform_post_id;

        if ($id === '') {
            return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
        }

        try {
            $response = $this->request('GET', $this->graphBase()."/{$id}/insights", [
                'query' => [
                    'metric' => 'views,reach,saved,likes,comments,shares',
                    'access_token' => $pageToken,
                ],
            ]);
        } catch (\Throwable) {
            return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
        }

        $values = [];

        foreach ($response['data'] ?? [] as $metric) {
            $values[$metric['name']] = collect($metric['values'] ?? [])
                ->sum(fn (array $value): int => (int) ($value['value'] ?? 0));
        }

        return [
            'views' => (int) ($values['views'] ?? $values['reach'] ?? 0),
            'likes' => (int) ($values['likes'] ?? 0),
            'comments' => (int) ($values['comments'] ?? 0),
            'shares' => (int) ($values['shares'] ?? 0),
            'saves' => (int) ($values['saved'] ?? 0),
        ];
    }

    public function followers(SocialAccount $account): ?int
    {
        [$pageToken] = $this->tokens($account);

        try {
            $response = $this->request('GET', $this->graphBase()."/{$account->internal_id}", [
                'query' => ['fields' => 'followers_count', 'access_token' => $pageToken],
            ]);
        } catch (\Throwable) {
            return null;
        }

        return isset($response['followers_count']) ? (int) $response['followers_count'] : null;
    }

    public function refresh(SocialAccount $account): bool
    {
        [$pageToken, $userToken] = $this->tokens($account);

        $long = $this->request('GET', $this->graphBase().'/oauth/access_token', [
            'query' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('social.instagram.client_id'),
                'client_secret' => config('social.instagram.client_secret'),
                'fb_exchange_token' => $userToken,
            ],
        ]);

        $page = $this->request('GET', $this->graphBase()."/{$account->page_id}", [
            'query' => ['fields' => 'access_token', 'access_token' => $long['access_token']],
        ]);

        $account->update([
            'access_token' => $page['access_token'].'___'.$long['access_token'],
            'token_expires_at' => now()->addSeconds((int) ($long['expires_in'] ?? 59 * 24 * 60 * 60)),
            'status' => 'connected',
        ]);

        return true;
    }

    /**
     * @param  array{url: string, thumb_url: string, kind: string, path: string}|null  $media
     * @param  array<string, string>  $extra
     */
    private function createContainer(string $igId, string $token, ?array $media, array $extra = []): string
    {
        $params = array_merge($extra, ['access_token' => $token]);

        if ($media !== null) {
            if ($media['kind'] === 'video') {
                $params['video_url'] = $media['url'];
                $params['media_type'] ??= 'VIDEO';
            } else {
                $params['image_url'] = $media['url'];
            }
        }

        $response = $this->request('POST', $this->graphBase()."/{$igId}/media", [
            'form_params' => $params,
        ]);

        return (string) $response['id'];
    }

    private function waitForContainer(string $containerId, string $token): void
    {
        for ($attempt = 0; $attempt < 24; $attempt++) {
            $response = $this->request('GET', $this->graphBase()."/{$containerId}", [
                'query' => ['fields' => 'status_code,status', 'access_token' => $token],
            ]);
            $status = (string) ($response['status_code'] ?? '');

            if (in_array($status, ['FINISHED', 'PUBLISHED'], true)) {
                return;
            }

            if (in_array($status, ['ERROR', 'EXPIRED'], true)) {
                throw new PermanentSocialException((string) ($response['status'] ?? 'Instagram gagal memproses media.'));
            }

            sleep(5);
        }

        throw new RetryableSocialException('Instagram masih memproses media. Coba lagi beberapa saat lagi.');
    }

    private function publishContainer(string $igId, string $token, string $containerId): string
    {
        $response = $this->request('POST', $this->graphBase()."/{$igId}/media_publish", [
            'form_params' => ['creation_id' => $containerId, 'field' => 'id', 'access_token' => $token],
        ]);

        return (string) $response['id'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $accounts
     * @param  array<string, mixed>  $page
     */
    private function addPageAccount(array &$accounts, array $page, string $userToken): void
    {
        $igId = (string) Arr::get($page, 'instagram_business_account.id', '');

        if ($igId === '' || blank($page['access_token'] ?? null)) {
            return;
        }

        $ig = $this->request('GET', $this->graphBase()."/{$igId}", [
            'query' => [
                'fields' => 'id,username,name,profile_picture_url',
                'access_token' => (string) $page['access_token'],
            ],
        ]);

        $accounts[$igId] = [
            'id' => $igId,
            'page_id' => (string) $page['id'],
            'name' => (string) ($ig['name'] ?? $ig['username'] ?? $igId),
            'handle' => '@'.ltrim((string) ($ig['username'] ?? 'instagram'), '@'),
            'access_token' => $page['access_token'].'___'.$userToken,
            'picture' => $ig['profile_picture_url'] ?? Arr::get($page, 'picture.data.url'),
        ];
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function publishStory(string $igId, string $token, array $media): PublishResult
    {
        $extra = ['media_type' => 'STORIES'];
        $container = $this->createContainer($igId, $token, $media, $extra);
        $this->waitForContainer($container, $token);
        $id = $this->publishContainer($igId, $token, $container);

        return new PublishResult($id, $this->permalink($id, $token, null));
    }

    private function permalink(string $id, string $token, ?SocialAccount $account): string
    {
        try {
            $response = $this->request('GET', $this->graphBase()."/{$id}", [
                'query' => ['fields' => 'permalink', 'access_token' => $token],
            ]);

            if (filled($response['permalink'] ?? null)) {
                return (string) $response['permalink'];
            }
        } catch (\Throwable) {
            // Stories usually do not expose a permalink.
        }

        return $account && $account->handle !== ''
            ? 'https://www.instagram.com/'.ltrim($account->handle, '@')
            : 'https://www.instagram.com/';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function tokens(SocialAccount $account): array
    {
        $parts = explode('___', (string) $account->access_token, 2);

        return [
            $parts[0],
            $parts[1] ?? $parts[0],
        ];
    }

    protected function mapError(string $body, int $status): array
    {
        return $this->inspect($body, $status, [
            'refresh' => [
                'REVOKED_ACCESS_TOKEN',
                '"error_subcode":33',
                'not a business account',
                'session invalidated',
            ],
            'disconnect' => [
                'You cannot access the app till you log in to',
                'Session key is malformed',
            ],
            'retry' => ['2207052', '2207082', 'An unknown error occurred'],
            'permanent' => [
                '2207081', '2207078', '2207050', '2207003', '2207020', '2207032',
                '2207053', '2207057', '2207026', '2207023', '2207006', '2207008',
                '2207028', '2207010', '2207035', '2207036', '2207037', '2207040',
                '2207004', '2207005', '2207009', '2207042', '(#200)',
                'Not enough permissions to post', '36003', '190', '36001',
                '2207051', '2207001', '1772107', '2207085', '2207077',
                'too little or too many attachments', '2207027', '2207018',
                'param collaborators is not allowed',
            ],
        ]);
    }
}
