<?php

namespace Tests\Feature;

use App\Jobs\ImportGoogleDriveMediaJob;
use App\Jobs\ProcessUploadedMediaJob;
use App\Models\MediaTask;
use App\Models\User;
use App\Services\GoogleDriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackgroundMediaTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Admin', 'Editor', 'Viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }

        Queue::fake();
    }

    public function test_video_upload_returns_immediately_and_queues_processing(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');

        $response = $this->actingAs($user)->post('/api/media', [
            'file' => UploadedFile::fake()->create('kampanye.mp4', 1024, 'video/mp4'),
            'name' => 'Kampanye Video',
        ], ['Accept' => 'application/json']);

        $response->assertAccepted()
            ->assertJsonPath('task.source', 'upload')
            ->assertJsonPath('task.status', 'queued')
            ->assertJsonPath('task.progress', 60)
            ->assertJsonPath('task.name', 'Kampanye Video');

        $this->assertDatabaseHas('media_tasks', [
            'user_id' => $user->id,
            'source' => 'upload',
            'status' => 'queued',
        ]);
        $this->assertDatabaseCount('media_files', 0);
        Queue::assertPushed(ProcessUploadedMediaJob::class);
    }

    public function test_google_drive_import_returns_immediately_and_queues_download(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');

        $drive = Mockery::mock(GoogleDriveService::class);
        $drive->shouldReceive('file')->once()->with('drive-file-123')->andReturn([
            'id' => 'drive-file-123',
            'name' => 'campaign.mp4',
            'mimeType' => 'video/mp4',
            'size' => 25 * 1024 * 1024,
        ]);
        $this->app->instance(GoogleDriveService::class, $drive);

        $response = $this->actingAs($user)->postJson('/api/drive/import', [
            'fileId' => 'drive-file-123',
            'name' => 'Campaign Drive',
        ]);

        $response->assertAccepted()
            ->assertJsonPath('task.source', 'google-drive')
            ->assertJsonPath('task.status', 'queued')
            ->assertJsonPath('task.progress', 5)
            ->assertJsonPath('task.name', 'Campaign Drive');

        Queue::assertPushed(ImportGoogleDriveMediaJob::class);
    }

    public function test_bootstrap_only_exposes_the_signed_in_users_media_tasks(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');
        $otherUser = User::factory()->create(['active' => true]);
        $otherUser->assignRole('Editor');

        $visibleTask = MediaTask::factory()->create(['user_id' => $user->id, 'name' => 'Visible']);
        MediaTask::factory()->create(['user_id' => $otherUser->id, 'name' => 'Hidden']);

        $this->actingAs($user)->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonCount(1, 'mediaTasks')
            ->assertJsonPath('mediaTasks.0.id', $visibleTask->id);
    }
}
