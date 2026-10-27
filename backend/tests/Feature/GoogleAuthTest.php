<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Editor', 'Viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_google_application_login_endpoints_are_removed(): void
    {
        $this->get('/api/auth/google/redirect')->assertNotFound();
        $this->getJson('/api/auth/google/status')->assertNotFound();
        $this->assertFalse(Schema::hasColumn('users', 'google_id'));
    }

    public function test_admin_can_connect_google_drive_without_linking_application_login(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Admin');

        $mock = Mockery::mock(GoogleOAuthService::class);
        $mock->shouldReceive('isConfigured')->once()->andReturn(true);
        $mock->shouldReceive('driveUrl')->once()->andReturnUsing(
            fn (string $state): string => 'https://accounts.google.com/o/oauth2/v2/auth?state='.$state,
        );
        $mock->shouldReceive('exchangeCode')->once()->with('test-code')->andReturn([
            'access_token' => 'ya29.test',
            'refresh_token' => 'refresh.test',
            'expires_in' => 3600,
            'scope' => 'openid email profile https://www.googleapis.com/auth/drive.readonly',
        ]);
        $mock->shouldReceive('userInfo')->once()->with('ya29.test')->andReturn([
            'id' => 'google-drive-owner-123',
            'email' => 'drive-owner@example.com',
            'name' => 'Drive Owner',
            'picture' => null,
        ]);
        $this->app->instance(GoogleOAuthService::class, $mock);

        $redirect = $this->actingAs($user)->get('/api/drive/google/redirect');
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get('/api/auth/google/callback?state='.$query['state'].'&code=test-code')
            ->assertRedirect(rtrim(config('social.frontend_url'), '/').'/#/accounts?connected=google-drive');

        $this->assertDatabaseHas('google_drive_connections', [
            'connected_by' => $user->id,
            'account_email' => 'drive-owner@example.com',
        ]);
    }

    public function test_callback_rejects_legacy_google_login_state(): void
    {
        Cache::put('google-state:legacy-login-state', [
            'intent' => 'login',
            'redirect' => '/dashboard',
        ], now()->addMinutes(10));

        $this->get('/api/auth/google/callback?state=legacy-login-state&code=test-code')
            ->assertRedirectContains('/#/accounts?error=');

        $this->assertGuest();
    }

    public function test_drive_files_requires_authentication_and_a_connection(): void
    {
        $this->getJson('/api/drive/files')->assertUnauthorized();

        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');

        $this->actingAs($user)->getJson('/api/drive/files')
            ->assertUnprocessable()
            ->assertJsonPath('reconnect', true);
    }
}
