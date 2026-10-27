<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Exceptions\PermanentSocialException;
use App\Services\Social\Exceptions\RetryableSocialException;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class TikTokAdapter extends AbstractSocialAdapter
{
    private const BASE = 'https://open.tiktokapis.com';

    public function platform(): string
    {
        return 'tiktok';
    }

    public function authUrl(string $redirectUri, string $state, ?string $codeChallenge = null): string
    {
        $query = [
            'client_key' => config('social.tiktok.client_key'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', [
                'video.list',
                'user.info.basic',
                'video.publish',
                'video.upload',
                'user.info.profile',
                'user.info.stats',
            ]),
        ];

        if ($codeChallenge !== null) {
            $query['code_challenge'] = $codeChallenge;
            $query['code_challenge_method'] = 'S256';
        }

        return 'https://www.tiktok.com/v2/auth/authorize/?'.http_build_query($query);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, open_id: string}
     */
    public function authenticateCode(string $code, string $redirectUri, ?string $codeVerifier = null): array
    {
        $params = [
            'client_key' => config('social.tiktok.client_key'),
            'client_secret' => config('social.tiktok.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ];

        if ($codeVerifier !== null) {
            $params['code_verifier'] = $codeVerifier;
        }

        $response = $this->request('POST', self::BASE.'/v2/oauth/token/', [
            'form_params' => $params,
        ]);

        return [
            'access_token' => (string) $response['access_token'],
            'refresh_token' => (string) ($response['refresh_token'] ?? ''),
            'expires_in' => (int) ($response['expires_in'] ?? 86400),
            'open_id' => (string) ($response['open_id'] ?? ''),
        ];
    }

    /**
     * @return array{open_id: string, display_name: string, username: string, followers: int, picture: ?string}
     */
    public function userInfo(string $accessToken): array
    {
        $response = $this->request('GET', self::BASE.'/v2/user/info/', [
            'query' => ['fields' => 'open_id,avatar_url,display_name,union_id,username,follower_count'],
            'headers' => ['Authorization' => "Bearer {$accessToken}"],
        ]);

        $user = $response['data']['user'] ?? [];

        return [
            'open_id' => (string) ($user['open_id'] ?? ''),
            'display_name' => (string) ($user['display_name'] ?? $user['username'] ?? 'TikTok'),
            'username' => (string) ($user['username'] ?? ''),
            'followers' => (int) ($user['follower_count'] ?? 0),
            'picture' => $user['avatar_url'] ?? null,
        ];
    }

    public function publish(SocialAccount $account, Post $post, PostPlatform $platform, array $media): PublishResult
    {
        $token = (string) $account->access_token;
        $settings = $platform->advanced_settings ?? [];
        $message = $this->message($post);

        if ($post->content_type === 'carousel' || ($post->content_type === 'photo' && count($media) > 1)) {
            return $this->publishPhotos($token, $post, $media, $settings, $account);
        }

        if ($post->content_type === 'photo' || $post->content_type === 'story') {
            return $this->publishPhotos($token, $post, $media, $settings, $account);
        }

        return $this->publishVideo($token, $post, $media[0], $settings, $account);
    }

    public function fetchInsights(SocialAccount $account, PostPlatform $platform): array
    {
        $id = (string) $platform->platform_post_id;

        if ($id === '') {
            return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
        }

        try {
            $response = $this->request('POST', self::BASE.'/v2/video/query/?fields=id,like_count,comment_count,share_count,view_count', [
                'headers' => [
                    'Authorization' => "Bearer {$account->access_token}",
                    'Content-Type' => 'application/json',
                ],
                'json' => ['filters' => ['video_ids' => [$id]]],
            ]);
        } catch (\Throwable) {
            return ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
        }

        $video = $response['data']['videos'][0] ?? [];

        return [
            'views' => (int) ($video['view_count'] ?? 0),
            'likes' => (int) ($video['like_count'] ?? 0),
            'comments' => (int) ($video['comment_count'] ?? 0),
            'shares' => (int) ($video['share_count'] ?? 0),
            'saves' => 0,
        ];
    }

    public function followers(SocialAccount $account): ?int
    {
        try {
            return $this->userInfo((string) $account->access_token)['followers'];
        } catch (\Throwable) {
            return null;
        }
    }

    public function refresh(SocialAccount $account): bool
    {
        $response = $this->request('POST', self::BASE.'/v2/oauth/token/', [
            'form_params' => [
                'client_key' => config('social.tiktok.client_key'),
                'client_secret' => config('social.tiktok.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ],
        ]);

        $account->update([
            'access_token' => (string) $response['access_token'],
            'refresh_token' => (string) ($response['refresh_token'] ?? $account->refresh_token),
            'token_expires_at' => now()->addSeconds((int) ($response['expires_in'] ?? 86400)),
            'status' => 'connected',
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function publishPhotos(string $token, Post $post, array $media, array $settings, SocialAccount $account): PublishResult
    {
        $message = $this->message($post);
        $postInfo = [
            'title' => mb_substr($post->title !== '' ? $post->title : $message, 0, 90),
            'description' => $message,
            'privacy_level' => $this->stringSetting($settings, 'privacy', 'PUBLIC_TO_EVERYONE'),
            'disable_comment' => ! $this->booleanSetting($settings, 'comments'),
            'auto_add_music' => $this->booleanSetting($settings, 'autoAddMusic', true),
            'brand_content_toggle' => $this->booleanSetting($settings, 'brandContent', false),
            'brand_organic_toggle' => $this->booleanSetting($settings, 'brandOrganic', false),
        ];

        $response = $this->request('POST', self::BASE.'/v2/post/publish/content/init/', [
            'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            'json' => [
                'post_info' => $postInfo,
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'photo_cover_index' => 0,
                    'photo_images' => array_values(array_map(fn (array $item): string => $item['url'], $media)),
                ],
                'post_mode' => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ],
        ]);

        return $this->pollStatus($token, (string) $response['data']['publish_id'], $account, 'photo');
    }

    /**
     * @param  array{url: string, thumb_url: string, kind: string, path: string}  $media
     * @param  array<string, mixed>  $settings
     */
    private function publishVideo(string $token, Post $post, array $media, array $settings, SocialAccount $account): PublishResult
    {
        $creator = $this->request('POST', self::BASE.'/v2/post/publish/creator_info/query/', [
            'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            'json' => new \stdClass,
        ]);

        $maxDuration = (int) Arr::get($creator, 'data.max_video_post_duration_sec', 0);

        if ($maxDuration > 0 && $post->duration && $post->duration > $maxDuration) {
            throw new PermanentSocialException("Durasi video melebihi batas akun TikTok ({$maxDuration} detik).");
        }

        $size = File::size($media['path']);

        if ($size <= 0) {
            throw new PermanentSocialException('File video tidak dapat dibaca.');
        }

        if ($size <= 64 * 1024 * 1024) {
            $chunkSize = $size;
            $totalChunks = 1;
        } else {
            $chunkSize = 10 * 1024 * 1024;
            $totalChunks = (int) ceil($size / $chunkSize);
        }

        $message = $this->message($post);
        $postInfo = [
            'title' => mb_substr($message, 0, 2200),
            'privacy_level' => $this->stringSetting($settings, 'privacy', 'PUBLIC_TO_EVERYONE'),
            'disable_duet' => ! $this->booleanSetting($settings, 'duet'),
            'disable_comment' => ! $this->booleanSetting($settings, 'comments'),
            'disable_stitch' => ! $this->booleanSetting($settings, 'stitch'),
            'is_aigc' => $this->booleanSetting($settings, 'videoMadeWithAi', false),
            'brand_content_toggle' => $this->booleanSetting($settings, 'brandContent', false),
            'brand_organic_toggle' => $this->booleanSetting($settings, 'brandOrganic', false),
        ];

        $response = $this->request('POST', self::BASE.'/v2/post/publish/video/init/', [
            'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            'json' => [
                'post_info' => $postInfo,
                'source_info' => [
                    'source' => 'FILE_UPLOAD',
                    'video_size' => $size,
                    'chunk_size' => $chunkSize,
                    'total_chunk_count' => $totalChunks,
                ],
                'post_mode' => 'DIRECT_POST',
            ],
        ]);

        $publishId = (string) $response['data']['publish_id'];
        $uploadUrl = (string) $response['data']['upload_url'];
        $handle = fopen($media['path'], 'rb');

        try {
            for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
                $start = $chunk * $chunkSize;
                $length = min($chunkSize, $size - $start);
                $bytes = '';

                while (strlen($bytes) < $length && ! feof($handle)) {
                    $bytes .= fread($handle, $length - strlen($bytes));
                }

                if (strlen($bytes) !== $length) {
                    throw new RetryableSocialException('File video berubah saat upload. Coba lagi.');
                }

                $end = $start + $length - 1;

                try {
                    $upload = $this->http->put($uploadUrl, [
                        'headers' => [
                            'Content-Type' => 'video/mp4',
                            'Content-Length' => (string) $length,
                            'Content-Range' => "bytes {$start}-{$end}/{$size}",
                        ],
                        'body' => $bytes,
                    ]);
                } catch (GuzzleException $exception) {
                    throw new RetryableSocialException($exception->getMessage());
                }

                if (! in_array($upload->getStatusCode(), [200, 201, 206], true)) {
                    throw $this->uploadError((string) $upload->getBody(), $upload->getStatusCode());
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->pollStatus($token, $publishId, $account, 'video');
    }

    private function pollStatus(string $token, string $publishId, SocialAccount $account, string $kind): PublishResult
    {
        for ($attempt = 0; $attempt < 15; $attempt++) {
            $response = $this->request('POST', self::BASE.'/v2/post/publish/status/fetch/', [
                'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
                'json' => ['publish_id' => $publishId],
            ]);
            $status = (string) Arr::get($response, 'data.status', '');

            if ($status === 'PUBLISH_COMPLETE') {
                $raw = json_encode($response);
                preg_match('/"publicaly_available_post_id"\s*:\s*\[?\s*"?(\d+)/', (string) $raw, $matches);
                $id = $matches[1] ?? (string) Arr::get($response, 'data.publicaly_available_post_id.0', '');
                $id = $id !== '' ? $id : (string) Arr::get($response, 'data.post_id', $publishId);
                $handle = ltrim((string) $account->handle, '@');

                return new PublishResult(
                    $id,
                    $handle !== '' ? "https://www.tiktok.com/@{$handle}/{$kind}/{$id}" : null,
                );
            }

            if (in_array($status, ['FAILED', 'SEND_TO_USER_INBOX'], true)) {
                if ($status === 'SEND_TO_USER_INBOX') {
                    return new PublishResult($publishId, 'https://www.tiktok.com/@'.ltrim((string) $account->handle, '@'));
                }

                throw new PermanentSocialException(
                    (string) (Arr::get($response, 'data.fail_reason') ?: Arr::get($response, 'error.message') ?: 'TikTok gagal mempublikasikan konten.'),
                );
            }

            sleep(5);
        }

        throw new RetryableSocialException('TikTok masih memproses konten. Status akan diperiksa ulang.');
    }

    private function uploadError(string $body, int $status): \Throwable
    {
        $mapped = $this->mapError($body, $status);

        return $mapped['type'] === 'retry'
            ? new RetryableSocialException($mapped['message'])
            : new PermanentSocialException($mapped['message']);
    }

    protected function mapError(string $body, int $status): array
    {
        return $this->inspect($body, $status, [
            'refresh' => ['access_token_invalid'],
            'disconnect' => ['reached_active_user_cap'],
            'retry' => ['rate_limit_exceeded', 'internal'],
            'permanent' => [
                'scope_not_authorized',
                'scope_permission_missed',
                'file_format_check_failed',
                'app_version_check_failed',
                'duration_check_failed',
                'frame_rate_check_failed',
                'video_pull_failed',
                'photo_pull_failed',
                'spam_risk',
                'unaudited_client_can_only_post_to_private_accounts',
                'url_ownership_unverified',
                'privacy_level_option_mismatch',
                'invalid_file_upload',
                'invalid_params',
                'picture_size_check_failed',
                'TikTok API error',
            ],
        ]);
    }
}
