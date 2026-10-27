<?php

namespace Tests\Feature;

use App\Models\BufferConnection;
use App\Models\User;
use App\Services\BufferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Editor', 'Viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_users_cannot_be_created_through_the_web_api(): void
    {
        $admin = User::factory()->create(['active' => true]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Pegawai Baru',
            'email' => 'pegawai@example.com',
            'role' => 'Editor',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'pegawai@example.com']);
    }

    public function test_cli_provisions_user_and_encrypts_buffer_key(): void
    {
        $passwordFile = $this->secretFile('Password#Aman2026');
        $key = 'buffer-personal-key-super-secret';
        $keyFile = $this->secretFile($key);

        $buffer = Mockery::mock(BufferService::class);
        $buffer->shouldReceive('profile')->once()->with($key)->andReturn([
            'account_id' => 'buffer-account-1',
            'account_name' => 'Nadia Buffer',
            'account_email' => 'nadia@example.com',
            'organization_id' => 'buffer-org-1',
            'organization_name' => 'SocialSync',
        ]);
        $this->app->instance(BufferService::class, $buffer);

        try {
            $this->artisan('socialsync:user-provision', [
                '--email' => 'nadia@example.com',
                '--name' => 'Nadia Putri',
                '--role' => 'Admin',
                '--password-file' => $passwordFile,
                '--buffer-key-file' => $keyFile,
                '--no-interaction' => true,
            ])->assertSuccessful();
        } finally {
            @unlink($passwordFile);
            @unlink($keyFile);
        }

        $user = User::query()->where('email', 'nadia@example.com')->firstOrFail();
        $connection = BufferConnection::query()->whereBelongsTo($user)->firstOrFail();
        $rawKey = DB::table('buffer_connections')->where('id', $connection->id)->value('api_key');

        $this->assertTrue($user->hasRole('Admin'));
        $this->assertSame($key, $connection->api_key);
        $this->assertNotSame($key, $rawKey);
        $this->assertStringNotContainsString($key, (string) $rawKey);
        $this->assertSame(hash('sha256', $key), $connection->api_key_fingerprint);
    }

    public function test_cli_rejects_a_buffer_account_already_assigned_to_another_user(): void
    {
        BufferConnection::factory()->create([
            'buffer_account_id' => 'duplicate-account',
            'api_key_fingerprint' => hash('sha256', 'existing-key'),
        ]);
        $passwordFile = $this->secretFile('Password#Aman2026');
        $keyFile = $this->secretFile('different-key');

        $buffer = Mockery::mock(BufferService::class);
        $buffer->shouldReceive('profile')->once()->andReturn([
            'account_id' => 'duplicate-account',
            'account_name' => 'Duplicate',
            'account_email' => 'duplicate@example.com',
            'organization_id' => 'buffer-org-2',
            'organization_name' => 'Duplicate Org',
        ]);
        $this->app->instance(BufferService::class, $buffer);

        try {
            $this->artisan('socialsync:user-provision', [
                '--email' => 'second@example.com',
                '--name' => 'Second User',
                '--password-file' => $passwordFile,
                '--buffer-key-file' => $keyFile,
                '--no-interaction' => true,
            ])->assertFailed();
        } finally {
            @unlink($passwordFile);
            @unlink($keyFile);
        }

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    private function secretFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'socialsync-secret-');
        file_put_contents($path, $contents);

        return $path;
    }
}
