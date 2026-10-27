<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Editor', 'Viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Storage::fake('media');
    }

    public function test_editor_can_upload_image_and_receive_a_signed_url(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');

        $response = $this->actingAs($user)->post('/api/media', [
            'file' => UploadedFile::fake()->image('kampanye.jpg', 1200, 1200),
            'name' => 'Kampanye Baru',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('name', 'Kampanye Baru')
            ->assertJsonPath('kind', 'image')
            ->assertJsonPath('dimensions', '1200 x 1200');

        $media = $response->json();
        Storage::disk('media')->assertExists(
            MediaFile::query()->findOrFail($media['id'])->path,
        );

        $this->get($media['thumbnail'])->assertOk();
    }

    public function test_viewer_cannot_upload_media(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Viewer');

        $this->actingAs($user)->post('/api/media', [
            'file' => UploadedFile::fake()->image('blocked.jpg'),
            'name' => 'Blocked',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_unsupported_file_is_rejected(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');

        $this->actingAs($user)->post('/api/media', [
            'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-php'),
            'name' => 'Payload',
        ], ['Accept' => 'application/json'])->assertUnprocessable();
    }
}
