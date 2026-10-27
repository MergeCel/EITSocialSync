<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Exceptions\PermanentSocialException;
use App\Services\Social\Exceptions\RetryableSocialException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;

class FacebookAdapter extends AbstractSocialAdapter
{
    public function platform(): string
    {
        return 'facebook';
    }

    public function graphBase(): string
    {
        return 'https://graph.facebook.com/'.config('social.graph_version', 'v25.0');
    }

    public function authUrl(string $redirectUri, string $state): string
    {
        return 'https://www.facebook.com/'.config('social.graph_version', 'v25.0').'/dialog/oauth?'.http_build_query([
            'client_id' => config('social.facebook.client_id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => implode(',', [
                'pages_show_list',
                'business_management',
                'pages_manage_posts',
                'pages_manage_engagement',
                'pages_read_engagement',
                'read_insights',
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
                'client_id' => config('social.facebook.client_id'),
                'redirect_uri' => $redirectUri,
                'client_secret' => config('social.facebook.client_secret'),
                'code' => $code,
            ],
        ]);

        $long = $this->request('GET', $this->graphBase().'/oauth/access_token', [
            'query' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => config('social.facebook.client_id'),
                'client_secret' => config('social.facebook.client_secret'),
                'fb_exchange_token' => $short['access_token'],
            ],
        ]);

        $this->request('GET', $this->graphBase().'/me/permissions', [
            'query' => ['access_token' => $long['access_token']],
        ]);

        return [
            'access_token' => (string) $long['access_token'],
            'expires_in' => (int) ($long['expires_in'] ?? 59 * 24 * 60 * 60),
        ];
    }

    /**
     * @return array<int, array{id: string, name: string, handle: string, access_token: string, picture: ?string}>
     */
    public function listPages(string $userToken): array
    {
        $pages = [];
        $url = $this->graphBase().'/me/accounts';
        $query = [
            'fields' => 'id,username,name,access_token,picture.type(large)',
            'limit' => 100,
            'access_token' => $userToken,
        ];

        do {
            $response = $this->request('GET', $url, ['query' => $query]);

            foreach ($response['data'] ?? [] as $page) {
                $this->addPage($pages, $page);
            }

            $url = (string) Arr::get($response, 'paging.next', '');
            $query = [];
        } while ($url !== '');

        $businesses = $this->request('GET', $this->graphBase().'/me/businesses', [
            'query' => ['fields' => 'id', 'limit' => 100, 'access_token' => $userToken],
        ]);

        foreach ($businesses['data'] ?? [] as $business) {
            foreach (['owned_pages', 'client_pages'] as $edge) {
                try {
                    $response = $this->request('GET', $this->graphBase()."/{$business['id']}/{$edge}", [
                        'query' => [
                            'fields' => 'id,username,name,access_token,picture.type(large)',
                            'limit' => 100,
                            'access_token' => $userToken,
                        ],
                    ]);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($response['data'] ?? [] as $page) {
                    $this->addPage($pages, $page);
                }
            }
        }

        return array_values($pages);
    }

    public function publish(SocialAccount $account, Post $post, PostPlatform $platform, array $media): PublishResult
    {
        $pageId = (string) ($account->page_id ?: $account->internal_id);
        $token = (string) $account->access_token;
        $message = $this->message($post);

        if ($post->content_type === 'story') {
            return $this->publishStory($pageId, $token, $media[0]);
        }

        $videos = array_values(array_filter($media, fn (array $item): bool => $item['kind'] === 'video'));

        if ($videos !== []) {
            $payload = [
                'file_url' => $videos[0]['url'],
                'description' => $message,
                'published' => 'true',
            ];

            if ($post->title !== '') {
                $payload['title'] = $post->title;
            }

            $response = $this->request('POST', $this->graphBase()."/{$pageId}/videos", [
                'query' => ['fields' => 'id,permalink_url', 'access_token' => $token],
                'form_params' => $payload,
            ]);

            $videoId = (string) $response['id'];
            $this->waitForVideo($videoId, $token);

            return new PublishResult($videoId, (string) ($response['permalink_url'] ?? "https://www.facebook.com/reel/{$videoId}"));
        }

        $attached = [];

        foreach ($media as $item) {
            $photo = $this->request('POST', $this->graphBase()."/{$pageId}/photos", [
                'query' => ['access_token' => $token],
                'form_params' => ['url' => $item['url'], 'published' => 'false'],
            ]);
            $attached[] = ['media_fbid' => (string) $photo['id']];
        }

        $response = $this->request('POST', $this->graphBase()."/{$pageId}/feed", [
            'query' => ['fields' => 'id,permalink_url', 'access_token' => $token],
            'json' => ['attached_media' => $attached, 'message' => $message, 'published' => true],
        ]);

        $id = (string) $response['id'];

        return new PublishResult($id, (string) ($response['permalink_url'] ?? "https://www.facebook.com/{$id}"));
    }

    public function fetchInsights(SocialAccount $account, PostPlatform $platform): array
    {
        $id = (string) $platform->platform_post_id;
        $token = (string) $account->access_token;

        if ($id === '') {
            return $this->emptyMetrics();
        }

        try {
            if (str_contains($id, '_')) {
                $response = $this->request('GET', $this->graphBase()."/{$id}/insights", [
                    'query' => [
                        'metric' => 'post_total_media_view_unique,post_reactions_by_type_total,post_clicks,post_clicks_by_type',
                        'access_token' => $token,
                    ],
                ]);
            } else {
                $response = $this->request('GET', $this->graphBase()."/{$id}/video_insights", [
                    'query' => [
                        'metric' => 'total_video_impressions,total_video_views,total_video_reactions_by_type_total,fb_reels_total_plays,post_video_likes_by_reaction_type,post_video_social_actions',
                        'access_token' => $token,
                    ],
                ]);
            }
        } catch (\Throwable) {
            return $this->emptyMetrics();
        }

        $values = [];

        foreach ($response['data'] ?? [] as $metric) {
            $values[$metric['name']] = collect($metric['values'] ?? [])
                ->sum(fn (array $value): int => (int) ($value['value'] ?? 0));
        }

        $likes = (int) ($values['post_video_likes_by_reaction_type'] ?? $values['post_reactions_by_type_total'] ?? 0);
        $comments = (int) ($values['post_video_social_actions'] ?? 0);
        $views = (int) ($values['total_video_views'] ?? $values['total_video_impressions'] ?? $values['fb_reels_total_plays'] ?? 0);

        return [
            'views' => $views,
            'likes' => $likes,
            'comments' => $comments,
            'shares' => 0,
            'saves' => 0,
        ];
    }

    public function followers(SocialAccount $account): ?int
    {
        $pageId = (string) ($account->page_id ?: $account->internal_id);

        try {
            $response = $this->request('GET', $this->graphBase()."/{$pageId}", [
                'query' => ['fields' => 'followers_count', 'access_token' => $account->access_token],
            ]);
        } catch (\Throwable) {
            return null;
        }

        return isset($response['followers_count']) ? (int) $response['followers_count'] : null;
    }

    public function refresh(SocialAccount $account): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $media
     */
    private function publishStory(string $pageId, string $token, array $media): PublishResult
    {
        if ($media['kind'] === 'video') {
            $start = $this->request('POST', $this->graphBase()."/{$pageId}/video_stories", [
                'form_params' => ['upload_phase' => 'start', 'access_token' => $token],
            ]);

            $videoId = (string) $start['video_id'];
            $uploadUrl = (string) $start['upload_url'];

            try {
                $response = $this->http->post($uploadUrl, [
                    'headers' => [
                        'Authorization' => "OAuth {$token}",
                        'file_url' => $media['url'],
                    ],
                ]);
            } catch (GuzzleException $exception) {
                throw new RetryableSocialException($exception->getMessage());
            }

            if (! in_array($response->getStatusCode(), [200, 201], true)) {
                throw new PermanentSocialException('Upload video story Facebook gagal.');
            }

            $this->request('POST', $this->graphBase()."/{$pageId}/video_stories", [
                'form_params' => [
                    'upload_phase' => 'finish',
                    'video_id' => $videoId,
                    'access_token' => $token,
                ],
            ]);
            $this->waitForVideo($videoId, $token);

            return new PublishResult($videoId, "https://www.facebook.com/stories/{$videoId}");
        }

        $photo = $this->request('POST', $this->graphBase()."/{$pageId}/photos", [
            'form_params' => ['url' => $media['url'], 'published' => 'false', 'access_token' => $token],
        ]);

        $response = $this->request('POST', $this->graphBase()."/{$pageId}/photo_stories", [
            'form_params' => ['photo_id' => (string) $photo['id'], 'access_token' => $token],
        ]);
        $id = (string) ($response['post_id'] ?? $photo['id']);

        return new PublishResult($id, "https://www.facebook.com/stories/{$id}");
    }

    private function waitForVideo(string $videoId, string $token): void
    {
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $response = $this->request('GET', $this->graphBase()."/{$videoId}", [
                'query' => ['fields' => 'status', 'access_token' => $token],
            ]);
            $status = (string) Arr::get($response, 'status.video_status', '');

            if (in_array($status, ['ready', 'upload_complete'], true)) {
                return;
            }

            if ($status === 'error') {
                throw new PermanentSocialException('Facebook gagal memproses video.');
            }

            sleep(5);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $pages
     * @param  array<string, mixed>  $page
     */
    private function addPage(array &$pages, array $page): void
    {
        if (blank($page['access_token'] ?? null)) {
            return;
        }

        $pages[(string) $page['id']] = [
            'id' => (string) $page['id'],
            'name' => (string) ($page['name'] ?? $page['username'] ?? $page['id']),
            'handle' => (string) ($page['username'] ?? $page['name'] ?? $page['id']),
            'access_token' => (string) $page['access_token'],
            'picture' => Arr::get($page, 'picture.data.url'),
        ];
    }

    /**
     * @return array{views: int, likes: int, comments: int, shares: int, saves: int}
     */
    private function emptyMetrics(): array
    {
        return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
    }

    protected function mapError(string $body, int $status): array
    {
        return $this->inspect($body, $status, [
            'refresh' => [
                'Error validating access token',
                'REVOKED_ACCESS_TOKEN',
                'Unpublished posts must be posted to a page as the page itself',
                'must be granted before impersonating',
                '1404078',
                '490',
            ],
            'disconnect' => ['Session has expired', 'access token has expired'],
            'retry' => ['1390008', 'Sorry, something went wrong'],
            'permanent' => [
                '(#200)',
                '1366046',
                '1346003',
                '1404102',
                '1366051',
                '1609008',
                '2061006',
                '1349125',
                '1404112',
                'Name parameter too long',
                '1363047',
                '1609010',
                '4854002',
                '(#100) No permission to publish the video',
                '2069019',
                '1404006',
            ],
        ]);
    }
}
