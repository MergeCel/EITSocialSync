<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SocialOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
    }

    public function test_social_login_returns_an_actionable_error_when_meta_is_not_configured(): void
    {
        config([
            'social.facebook.client_id' => null,
            'social.facebook.client_secret' => null,
        ]);
        $admin = User::factory()->create(['active' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->getJson('/api/oauth/facebook/redirect')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Login Meta belum dikonfigurasi. Isi FACEBOOK_CLIENT_ID dan FACEBOOK_CLIENT_SECRET di backend/.env.');
    }

    public function test_social_login_requires_an_admin(): void
    {
        Role::findOrCreate('Viewer', 'web');
        $viewer = User::factory()->create(['active' => true]);
        $viewer->assignRole('Viewer');

        $this->actingAs($viewer)
            ->getJson('/api/oauth/tiktok/redirect')
            ->assertForbidden();
    }
}
