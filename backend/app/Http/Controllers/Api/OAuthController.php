<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\FacebookAdapter;
use App\Services\Social\InstagramAdapter;
use App\Services\Social\SocialManager;
use App\Services\Social\TikTokAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OAuthController extends Controller
{
    public function __construct(private readonly SocialManager $manager) {}

    public function redirect(Request $request, string $platform): JsonResponse
    {
        $this->requireAdmin($request);
        abort_unless(in_array($platform, SocialAccount::PLATFORMS, true), 404);

        abort_unless(
            $this->platformConfigured($platform),
            422,
            $this->configurationMessage($platform),
        );

        $state = Str::random(48);
        $codeVerifier = null;
        $codeChallenge = null;

        if ($platform === 'tiktok') {
            $codeVerifier = Str::random(64);
            $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        }

        Cache::put("oauth-state:{$state}", [
            'user_id' => $request->user()->id,
            'platform' => $platform,
            'code_verifier' => $codeVerifier,
        ], now()->addMinutes((int) config('social.state_ttl_minutes', 10)));

        $redirectUri = route('oauth.callback', ['platform' => $platform]);
        $adapter = $this->manager->adapter($platform);

        $url = match (true) {
            $adapter instanceof FacebookAdapter => $adapter->authUrl($redirectUri, $state),
            $adapter instanceof InstagramAdapter => $adapter->authUrl($redirectUri, $state),
            $adapter instanceof TikTokAdapter => $adapter->authUrl($redirectUri, $state, $codeChallenge),
            default => throw new \RuntimeException('Adapter OAuth tidak tersedia.'),
        };

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request, string $platform): RedirectResponse
    {
        $frontend = rtrim((string) config('social.frontend_url'), '/');
        $state = (string) $request->query('state', '');
        $cached = Cache::pull("oauth-state:{$state}");

        if (! is_array($cached) || $cached['platform'] !== $platform) {
            return redirect("{$frontend}/#/accounts?error=".urlencode('State OAuth tidak valid atau kedaluwarsa.'));
        }

        if ($request->filled('error')) {
            return redirect("{$frontend}/#/accounts?error=".urlencode((string) $request->query('error_description', $request->query('error'))));
        }

        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect("{$frontend}/#/accounts?error=".urlencode('Kode OAuth tidak diterima.'));
        }

        $user = User::query()->find($cached['user_id']);

        if (! $user || ! $user->active || ! $user->hasRole('Admin')) {
            return redirect("{$frontend}/#/accounts?error=".urlencode('Sesi OAuth tidak lagi memiliki akses Admin.'));
        }

        try {
            $redirectUri = route('oauth.callback', ['platform' => $platform]);
            $adapter = $this->manager->adapter($platform);

            if ($adapter instanceof FacebookAdapter) {
                $auth = $adapter->authenticateCode($code, $redirectUri);
                $pages = $adapter->listPages($auth['access_token']);

                if ($pages === []) {
                    throw new \RuntimeException('Tidak ada Facebook Page yang dapat dipublikasikan. Pastikan akun memiliki izin pengelola Page.');
                }

                foreach ($pages as $page) {
                    $account = SocialAccount::query()->updateOrCreate(
                        ['platform' => 'facebook', 'internal_id' => $page['id']],
                        [
                            'account_name' => $page['name'],
                            'handle' => $page['handle'],
                            'page_id' => $page['id'],
                            'access_token' => $page['access_token'],
                            'refresh_token' => null,
                            'token_expires_at' => now()->addSeconds($auth['expires_in']),
                            'status' => 'connected',
                            'metadata' => ['picture' => $page['picture']],
                            'connected_by' => $user->id,
                        ],
                    );

                    $this->refreshFollowers($adapter, $account);
                }
            } elseif ($adapter instanceof InstagramAdapter) {
                $auth = $adapter->authenticateCode($code, $redirectUri);
                $accounts = $adapter->listAccounts($auth['access_token']);

                if ($accounts === []) {
                    throw new \RuntimeException('Tidak ada akun Instagram Business yang terhubung ke Facebook Page Anda.');
                }

                foreach ($accounts as $item) {
                    $account = SocialAccount::query()->updateOrCreate(
                        ['platform' => 'instagram', 'internal_id' => $item['id']],
                        [
                            'account_name' => $item['name'],
                            'handle' => $item['handle'],
                            'page_id' => $item['page_id'],
                            'access_token' => $item['access_token'],
                            'refresh_token' => null,
                            'token_expires_at' => now()->addSeconds($auth['expires_in']),
                            'status' => 'connected',
                            'metadata' => ['picture' => $item['picture']],
                            'connected_by' => $user->id,
                        ],
                    );

                    $this->refreshFollowers($adapter, $account);
                }
            } elseif ($adapter instanceof TikTokAdapter) {
                $auth = $adapter->authenticateCode($code, $redirectUri, $cached['code_verifier'] ?? null);
                $profile = $adapter->userInfo($auth['access_token']);
                $openId = str_replace('-', '', $profile['open_id'] ?: $auth['open_id']);

                $account = SocialAccount::query()->updateOrCreate(
                    ['platform' => 'tiktok', 'internal_id' => $openId],
                    [
                        'account_name' => $profile['display_name'],
                        'handle' => $profile['username'] !== '' ? '@'.$profile['username'] : '',
                        'page_id' => null,
                        'access_token' => $auth['access_token'],
                        'refresh_token' => $auth['refresh_token'],
                        'token_expires_at' => now()->addSeconds($auth['expires_in']),
                        'status' => 'connected',
                        'followers' => $profile['followers'],
                        'metadata' => ['picture' => $profile['picture']],
                        'connected_by' => $user->id,
                    ],
                );
            }

            activity()
                ->causedBy($user)
                ->withProperties(['subject' => $platform])
                ->log('menghubungkan akun '.$platform);

            return redirect("{$frontend}/#/accounts?connected={$platform}");
        } catch (\Throwable $exception) {
            Log::error('OAuth callback failed', [
                'platform' => $platform,
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);

            return redirect("{$frontend}/#/accounts?error=".urlencode($exception->getMessage()));
        }
    }

    private function refreshFollowers(object $adapter, SocialAccount $account): void
    {
        try {
            $followers = $adapter->followers($account);

            if ($followers !== null) {
                $account->update(['followers' => $followers]);
            }
        } catch (\Throwable) {
            // Follower counts are informational and must not fail account linking.
        }
    }

    private function platformConfigured(string $platform): bool
    {
        return match ($platform) {
            'facebook' => filled(config('social.facebook.client_id'))
                && filled(config('social.facebook.client_secret')),
            'instagram' => filled(config('social.instagram.client_id'))
                && filled(config('social.instagram.client_secret')),
            'tiktok' => filled(config('social.tiktok.client_key'))
                && filled(config('social.tiktok.client_secret')),
            default => false,
        };
    }

    private function configurationMessage(string $platform): string
    {
        return match ($platform) {
            'facebook', 'instagram' => 'Login Meta belum dikonfigurasi. Isi FACEBOOK_CLIENT_ID dan FACEBOOK_CLIENT_SECRET di backend/.env.',
            'tiktok' => 'Login TikTok belum dikonfigurasi. Isi TIKTOK_CLIENT_KEY dan TIKTOK_CLIENT_SECRET di backend/.env.',
            default => 'Login platform belum dikonfigurasi.',
        };
    }
}
