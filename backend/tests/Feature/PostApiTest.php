<?php

namespace Tests\Feature;

use App\Jobs\DispatchDuePostsJob;
use App\Jobs\PublishPostJob;
use App\Models\MediaFile;
use App\Models\Post;
use App\Models\User;
use App\Services\Social\SocialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PostApiTest extends TestCase
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

    public function test_editor_can_create_a_draft_with_media(): void
    {
        [$user, $media] = $this->editorAndMedia();

        $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Draft Kampanye',
            'type' => 'photo',
            'caption' => 'Caption draft',
            'hashtags' => '#draft',
            'mediaIds' => [$media->id],
            'platforms' => ['instagram'],
            'settings' => ['instagram' => ['branded' => false]],
            'asDraft' => true,
        ])->assertCreated()
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('mediaIds.0', $media->id);

        $this->assertDatabaseHas('posts', ['title' => 'Draft Kampanye', 'status' => 'draft']);
    }

    public function test_publish_now_queues_the_publish_job(): void
    {
        [$user, $media] = $this->editorAndMedia();

        $response = $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Upload Sekarang',
            'type' => 'photo',
            'caption' => 'Caption',
            'mediaIds' => [$media->id],
            'platforms' => ['instagram'],
        ]);

        $response->assertCreated()->assertJsonPath('status', 'publishing');
        Queue::assertPushed(PublishPostJob::class);
    }

    public function test_scheduled_post_requires_a_future_date(): void
    {
        [$user, $media] = $this->editorAndMedia();

        $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Jadwal Lampau',
            'type' => 'photo',
            'caption' => 'Caption',
            'mediaIds' => [$media->id],
            'platforms' => ['instagram'],
            'scheduledAt' => now()->subMinute()->toIso8601String(),
        ])->assertUnprocessable();
    }

    public function test_cross_platform_compatibility_is_validated_on_the_server(): void
    {
        [$user, $media] = $this->editorAndMedia();

        $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Story TikTok',
            'type' => 'story',
            'mediaIds' => [$media->id],
            'platforms' => ['tiktok'],
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Carousel Facebook',
            'type' => 'carousel',
            'caption' => 'Caption',
            'mediaIds' => [$media->id],
            'platforms' => ['facebook'],
        ])->assertUnprocessable();
    }

    public function test_viewer_cannot_create_posts(): void
    {
        [$editor, $media] = $this->editorAndMedia();
        $viewer = User::factory()->create(['active' => true]);
        $viewer->assignRole('Viewer');
        $this->actingAs($editor);

        $this->actingAs($viewer)->postJson('/api/posts', [
            'title' => 'Blocked',
            'type' => 'photo',
            'caption' => 'Caption',
            'mediaIds' => [$media->id],
            'platforms' => ['instagram'],
        ])->assertForbidden();
    }

    public function test_publish_job_marks_platform_failed_when_no_account_is_connected(): void
    {
        [$user, $media] = $this->editorAndMedia();

        $post = $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Tanpa Akun',
            'type' => 'photo',
            'caption' => 'Caption',
            'mediaIds' => [$media->id],
            'platforms' => ['instagram'],
        ])->json();

        (new PublishPostJob($post['id']))->handle(app(SocialManager::class));

        $this->assertDatabaseHas('posts', ['id' => $post['id'], 'status' => 'failed']);
        $this->assertDatabaseHas('post_platforms', [
            'post_id' => $post['id'],
            'publish_status' => 'failed',
        ]);
        $this->assertDatabaseHas('notifications', ['user_id' => $user->id, 'type' => 'error']);
    }

    public function test_due_scheduled_posts_are_queued_for_publishing(): void
    {
        [$user, $media] = $this->editorAndMedia();

        $post = $this->actingAs($user)->postJson('/api/posts', [
            'title' => 'Jadwal Due',
            'type' => 'photo',
            'caption' => 'Caption',
            'mediaIds' => [$media->id],
            'platforms' => ['instagram'],
            'scheduledAt' => now()->addMinute()->toIso8601String(),
        ])->json();

        Post::query()->whereKey($post['id'])->update(['scheduled_at' => now()->subMinute()]);

        (new DispatchDuePostsJob)->handle();

        $this->assertDatabaseHas('posts', ['id' => $post['id'], 'status' => 'publishing']);
        Queue::assertPushed(PublishPostJob::class);
    }

    /**
     * @return array{0: User, 1: MediaFile}
     */
    private function editorAndMedia(): array
    {
        $user = User::factory()->create(['active' => true]);
        $user->assignRole('Editor');

        $media = MediaFile::query()->create([
            'original_filename' => 'photo.jpg',
            'custom_name' => 'Photo',
            'path' => 'originals/'.Str::uuid().'/photo.jpg',
            'thumbnail_path' => 'thumbnails/'.Str::uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
            'kind' => 'image',
            'width' => 1080,
            'height' => 1080,
            'uploaded_by' => $user->id,
        ]);

        return [$user, $media];
    }
}
