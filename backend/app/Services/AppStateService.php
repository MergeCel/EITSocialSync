<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\BufferConnection;
use App\Models\Folder;
use App\Models\GoogleDriveConnection;
use App\Models\MediaFile;
use App\Models\MediaTask;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Spatie\Activitylog\Models\Activity;

class AppStateService
{
    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $users = User::query()
            ->with('roles')
            ->orderBy('created_at')
            ->get();

        $folders = Folder::query()->orderBy('created_at')->get();
        $media = MediaFile::query()->orderByDesc('created_at')->get();
        $mediaTasks = MediaTask::query()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query
                ->whereIn('status', ['queued', 'processing', 'failed'])
                ->orWhere('updated_at', '>=', now()->subMinute()))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $posts = Post::query()
            ->with(['platforms.engagement', 'orderedMedia'])
            ->orderByDesc('created_at')
            ->get();

        $accounts = SocialAccount::query()
            ->where('connected_by', $user->id)
            ->orderBy('platform')
            ->orderBy('account_name')
            ->get();
        $notifications = AppNotification::query()
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereNull('user_id'))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $activities = Activity::query()
            ->with('causer')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $drive = GoogleDriveConnection::query()->latest('connected_at')->first();

        return [
            'version' => 1,
            'activeUserId' => (string) $user->id,
            'users' => $users->map(fn (User $item): array => $this->user($item))->values()->all(),
            'folders' => $folders->map(fn (Folder $folder): array => [
                'id' => $folder->id,
                'name' => $folder->name,
                'parentId' => $folder->parent_id,
                'createdAt' => $folder->created_at?->toIso8601String(),
            ])->values()->all(),
            'media' => $media->map(fn (MediaFile $file): array => $this->media($file))->values()->all(),
            'mediaTasks' => $mediaTasks->map(fn (MediaTask $task): array => $this->mediaTask($task))->values()->all(),
            'posts' => $posts->map(fn (Post $post): array => $this->post($post))->values()->all(),
            'accounts' => $accounts->map(fn (SocialAccount $account): array => $this->account($account))->values()->all(),
            'bufferConnection' => $this->bufferConnection($user),
            'driveConnection' => [
                'provider' => 'google-drive',
                'accountEmail' => $drive?->account_email ?? '',
                'connected' => (bool) $drive?->canSync(),
                'connectedAt' => $drive?->connected_at?->toIso8601String(),
            ],
            'notifications' => $notifications->map(fn (AppNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'read' => $notification->read_at !== null,
                'createdAt' => $notification->created_at?->toIso8601String(),
            ])->values()->all(),
            'activities' => $activities->map(fn (Activity $activity): array => [
                'id' => (string) $activity->id,
                'actor' => $activity->causer?->name ?? 'Sistem',
                'action' => $activity->description,
                'subject' => (string) ($activity->properties['subject'] ?? ''),
                'createdAt' => $activity->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function user(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first() ?? 'Viewer',
            'active' => (bool) $user->active,
            'initials' => $user->initials(),
            'passwordTemplate' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function folder(Folder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'parentId' => $folder->parent_id,
            'createdAt' => $folder->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function media(MediaFile $file): array
    {
        return [
            'id' => $file->id,
            'folderId' => $file->folder_id,
            'name' => $file->custom_name,
            'originalName' => $file->original_filename,
            'kind' => $file->kind,
            'mimeType' => $file->mime_type,
            'size' => (int) $file->size,
            'duration' => $file->duration,
            'dimensions' => $file->dimensions(),
            'thumbnail' => $this->mediaUrl($file, 'thumb'),
            'uploadedBy' => (string) $file->uploaded_by,
            'createdAt' => $file->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mediaTask(MediaTask $task): array
    {
        return [
            'id' => $task->id,
            'source' => $task->source,
            'status' => $task->status,
            'progress' => (int) $task->progress,
            'name' => $task->name,
            'error' => $task->error_message,
            'createdAt' => $task->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function post(Post $post): array
    {
        $metrics = [];

        foreach ($post->platforms as $platform) {
            if ($platform->engagement !== null) {
                $metrics[$platform->platform] = [
                    'views' => (int) $platform->engagement->views,
                    'likes' => (int) $platform->engagement->likes,
                    'comments' => (int) $platform->engagement->comments,
                    'shares' => (int) $platform->engagement->shares,
                    'saves' => (int) $platform->engagement->saves,
                    'fetchedAt' => $platform->engagement->fetched_at?->toIso8601String(),
                ];
            }
        }

        return [
            'id' => $post->id,
            'title' => $post->title,
            'type' => $post->content_type,
            'caption' => $post->caption ?? '',
            'hashtags' => $post->hashtags ?? '',
            'mediaIds' => $post->orderedMedia->pluck('id')->values()->all(),
            'status' => $post->status,
            'scheduledAt' => $post->scheduled_at?->toIso8601String(),
            'createdAt' => $post->created_at?->toIso8601String(),
            'createdBy' => (string) $post->created_by,
            'platforms' => $post->platforms->map(fn ($platform): array => [
                'platform' => $platform->platform,
                'status' => $this->platformStatus($post->status, $platform->publish_status),
                'platformPostId' => $platform->platform_post_id,
                'publishedAt' => $platform->published_at?->toIso8601String(),
                'error' => $platform->error_message,
                'settings' => (object) ($platform->advanced_settings ?? []),
            ])->values()->all(),
            'metrics' => (object) $metrics,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function account(SocialAccount $account): array
    {
        return [
            'id' => $account->id,
            'platform' => $account->platform,
            'accountName' => $account->account_name,
            'handle' => $account->handle ?? '',
            'status' => $account->effectiveStatus(),
            'expiresAt' => $account->token_expires_at?->toIso8601String(),
            'followers' => (int) $account->followers,
        ];
    }

    /** @return array<string, mixed> */
    public function bufferConnection(User $user): array
    {
        /** @var BufferConnection|null $connection */
        $connection = $user->bufferConnection()->first();
        $channelCount = SocialAccount::query()
            ->where('connected_by', $user->id)
            ->where('status', 'connected')
            ->where('metadata->provider', 'buffer')
            ->count();
        $maximum = (int) config('social.buffer.max_channels', 3);

        return [
            'configured' => $connection !== null && $connection->status === 'active',
            'status' => $connection?->status ?? 'unconfigured',
            'accountName' => $connection?->account_name ?? '',
            'accountEmail' => $connection?->account_email ?? '',
            'organizationName' => $connection?->organization_name ?? '',
            'channelCount' => $channelCount,
            'maxChannels' => $maximum,
            'limitReached' => $channelCount >= $maximum,
            'manageUrl' => (string) config('social.buffer.manage_url'),
            'lastSyncedAt' => $connection?->last_synced_at?->toIso8601String(),
            'lastError' => $connection?->last_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return $this->forUser($user);
    }

    private function mediaUrl(MediaFile $file, string $variant): string
    {
        $root = rtrim((string) config('social.media_public_base_url'), '/');

        if ($root !== '') {
            URL::forceRootUrl($root);
        }

        return URL::temporarySignedRoute(
            'media.public',
            now()->addMinutes((int) config('media.signed_url_minutes', 360)),
            ['mediaFile' => $file->id, 'variant' => $variant],
        );
    }

    private function platformStatus(string $postStatus, string $publishStatus): string
    {
        if ($postStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($publishStatus === 'success' || $publishStatus === 'failed') {
            return $publishStatus;
        }

        if ($postStatus === 'publishing') {
            return 'processing';
        }

        return 'pending';
    }
}
