<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleDriveConnection;
use App\Models\User;
use App\Services\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly GoogleOAuthService $google) {}

    public function driveRedirect(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user?->hasRole('Admin')) {
            return $this->errorRedirect('Hanya Admin yang dapat menghubungkan Google Drive.', $this->returnBase($request));
        }

        if (! $this->google->isConfigured()) {
            return $this->errorRedirect('Google Drive belum dikonfigurasi oleh Admin.', $this->returnBase($request));
        }

        $state = Str::random(48);
        Cache::put("google-state:{$state}", [
            'intent' => 'drive',
            'user_id' => $user->id,
            'base' => $this->returnBase($request),
        ], now()->addMinutes((int) config('google.state_ttl_minutes', 10)));

        return redirect()->away($this->google->driveUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $cached = Cache::pull("google-state:{$state}");

        if (! is_array($cached) || ($cached['intent'] ?? null) !== 'drive') {
            return $this->errorRedirect('State Google Drive OAuth tidak valid atau kedaluwarsa.');
        }

        $base = (string) ($cached['base'] ?? $this->frontendUrl());

        if ($request->filled('error')) {
            return $this->errorRedirect((string) $request->query('error_description', $request->query('error')), $base);
        }

        try {
            $auth = $this->google->exchangeCode((string) $request->query('code', ''));
            $profile = $this->google->userInfo($auth['access_token']);

            if ($profile['email'] === '') {
                throw new \RuntimeException('Akun Google tidak memberikan email yang valid.');
            }

            return $this->connectDrive($cached, $auth, $profile, $base);
        } catch (Throwable $exception) {
            Log::error('Google Drive OAuth callback failed', [
                'error' => $exception->getMessage(),
            ]);

            return $this->errorRedirect($exception->getMessage(), $base);
        }
    }

    /**
     * @param  array<string, mixed>  $cached
     * @param  array{access_token: string, refresh_token: ?string, expires_in: int, scope: string}  $auth
     * @param  array<string, mixed>  $profile
     */
    private function connectDrive(array $cached, array $auth, array $profile, string $base): RedirectResponse
    {
        $user = User::query()->find($cached['user_id'] ?? null);

        if (! $user || ! $user->active || ! $user->hasRole('Admin')) {
            return $this->errorRedirect('Sesi Google Drive tidak lagi memiliki akses Admin.', $base);
        }

        $existing = GoogleDriveConnection::query()->first();
        GoogleDriveConnection::query()->delete();
        GoogleDriveConnection::query()->create([
            'connected_by' => $user->id,
            'account_email' => $profile['email'],
            'access_token' => $auth['access_token'],
            'refresh_token' => $auth['refresh_token'] ?? $existing?->refresh_token,
            'token_expires_at' => now()->addSeconds($auth['expires_in']),
            'scopes' => array_values(array_filter(explode(' ', $auth['scope']))),
            'connected_at' => now(),
        ]);

        activity()
            ->causedBy($user)
            ->withProperties(['subject' => $profile['email']])
            ->log('menghubungkan Google Drive');

        return redirect()->away($base.'/#/accounts?connected=google-drive');
    }

    private function errorRedirect(string $message, ?string $base = null): RedirectResponse
    {
        return redirect()->away(($base ?? $this->frontendUrl()).'/#/accounts?error='.urlencode($message));
    }

    private function frontendUrl(): string
    {
        return rtrim((string) config('social.frontend_url'), '/');
    }

    private function returnBase(Request $request): string
    {
        $fallback = $this->frontendUrl();
        $referer = (string) $request->headers->get('referer', '');

        if ($referer === '') {
            return $fallback;
        }

        $parts = parse_url($referer);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $fallback;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $allowedOrigins = [
            $this->origin($fallback),
            $this->origin((string) config('app.url')),
        ];

        if (! in_array($origin, $allowedOrigins, true)) {
            return $fallback;
        }

        $path = rtrim((string) ($parts['path'] ?? '/'), '/');

        if ($path !== '' && ! str_starts_with($path, '/app')) {
            $path = '';
        }

        return $origin.($path === '' ? '' : '/app');
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
