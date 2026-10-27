<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Editor', 'Viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_user_can_login_and_load_bootstrap_state(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'Secret#12345',
            'active' => true,
        ]);
        $user->assignRole('Admin');

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/auth/login', [
                'email' => 'admin@example.com',
                'password' => 'Secret#12345',
            ])
            ->assertOk()
            ->assertJsonPath('state.activeUserId', (string) $user->id)
            ->assertJsonPath('state.users.0.role', 'Admin');

        $this->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonStructure([
                'version', 'activeUserId', 'users', 'folders', 'media', 'posts',
                'accounts', 'driveConnection', 'notifications', 'activities',
            ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'email' => 'disabled@example.com',
            'password' => 'Secret#12345',
            'active' => false,
        ]);
        $user->assignRole('Editor');

        $this->withHeader('Origin', 'http://localhost')
            ->postJson('/api/auth/login', [
                'email' => 'disabled@example.com',
                'password' => 'Secret#12345',
            ])
            ->assertUnprocessable();
    }

    public function test_bootstrap_requires_authentication(): void
    {
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Viewer');

        $this->withHeader('Origin', 'http://localhost')
            ->actingAs($user)
            ->postJson('/api/auth/logout')
            ->assertOk();
    }
}
