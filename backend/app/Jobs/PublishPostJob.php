<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\BufferConnection;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\BufferService;
use App\Services\Social\Exceptions\DisconnectException;
use App\Services\Social\Exceptions\PermanentSocialException;
use App\Services\Social\Exceptions\RefreshTokenException;
use App\Services\Social\Exceptions\RetryableSocialException;
use App\Services\Social\SocialManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

class PublishPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 840;

    public function __construct(public string $postId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SocialManager $manager, ?BufferService $buffer = null): void
    {
        $post = Post::query()
            ->with(['platforms', 'orderedMedia', 'creator'])
            ->find($this->postId);

        if (! $post || $post->status !== 'publishing') {
            return;
        }

        $retryMessage = null;

        foreach ($post->platforms as $platform) {
            if (! $platform->is_enabled || $platform->publish_status === 'success') {
                continue;
            }

            $usingBuffer = config('social.provider') === 'buffer';
            $account = SocialAccount::query()
                ->where('platform', $platform->platform)
                ->where('status', 'connected')
                ->when(
                    $usingBuffer,
                    fn ($query) => $query
                        ->where('connected_by', $post->created_by)
                        ->where('metadata->provider', 'buffer'),
                    fn ($query) => $query
                        ->whereNotNull('access_token')
                        ->where(fn ($query) => $query->whereNull('token_expires_at')->orWhere('token_expires_at', '>', now())),
                )
                ->orderByDesc('updated_at')
                ->first();

            if (! $account) {
                $this->markFailed($platform, 'Belum ada akun platform yang terhubung atau token sudah tidak valid.');

                continue;
            }

            try {
                $media = $this->mediaPayload($post);
                if ($usingBuffer) {
                    $connection = BufferConnection::query()
                        ->where('user_id', $post->created_by)
                        ->where('status', 'active')
                        ->first();

                    if (! $connection) {
                        $this->markFailed($platform, 'Akun Buffer pengguna belum diprovisikan atau tidak aktif.');

                        continue;
                    }

                    $buffer ??= app(BufferService::class);
                    $result = $buffer->publish($connection, $account, $post, $platform, $media);
                } else {
                    $result = $manager->adapter($platform->platform)->publish($account, $post, $platform, $media);
                }

                $platform->update([
                    'publish_status' => 'success',
                    'platform_post_id' => $result->id,
                    'platform_url' => $result->url,
                    'published_at' => now(),
                    'error_message' => null,
                    'attempts' => $platform->attempts + 1,
                ]);
            } catch (RefreshTokenException $exception) {
                $account->update(['status' => 'expired']);
                $this->markFailed($platform, $exception->getMessage());
            } catch (DisconnectException $exception) {
                $account->update(['status' => 'disconnected', 'access_token' => null]);
                $this->markFailed($platform, $exception->getMessage());
            } catch (RetryableSocialException $exception) {
                $platform->increment('attempts');
                $platform->update(['error_message' => $exception->getMessage()]);
                $retryMessage = $exception->getMessage();
            } catch (PermanentSocialException $exception) {
                $this->markFailed($platform, $exception->getMessage());
            } catch (Throwable $exception) {
                $platform->increment('attempts');
                $platform->update(['error_message' => $exception->getMessage()]);
                $retryMessage = $exception->getMessage();
            }
        }

        if ($retryMessage !== null) {
            if ($this->attempts() < $this->tries) {
                throw new RetryableSocialException($retryMessage);
            }

            foreach ($post->platforms()->where('publish_status', 'pending')->get() as $platform) {
                $this->markFailed($platform, $platform->error_message ?: $retryMessage);
            }
        }

        $this->finalise($post->fresh(['platforms', 'creator']));
    }

    public function failed(?Throwable $exception): void
    {
        $post = Post::query()->with(['platforms', 'creator'])->find($this->postId);

        if (! $post) {
            return;
        }

        foreach ($post->platforms as $platform) {
            if ($platform->publish_status !== 'success') {
                $this->markFailed($platform, $platform->error_message ?: ($exception?->getMessage() ?? 'Publikasi gagal.'));
            }
        }

        $this->finalise($post->fresh(['platforms', 'creator']));
    }

    /**
     * @return array<int, array{url: string, thumb_url: string, kind: string, path: string}>
     */
    private function mediaPayload(Post $post): array
    {
        $root = rtrim((string) config('social.media_public_base_url'), '/');

        if ($root !== '') {
            URL::forceRootUrl($root);
        }

        return $post->orderedMedia->map(function ($media): array {
            $url = URL::temporarySignedRoute(
                'media.public',
                now()->addHours(2),
                ['mediaFile' => $media->id, 'variant' => 'original'],
            );
            $thumbUrl = URL::temporarySignedRoute(
                'media.public',
                now()->addHours(2),
                ['mediaFile' => $media->id, 'variant' => 'thumb'],
            );

            return [
                'url' => $url,
                'thumb_url' => $thumbUrl,
                'kind' => $media->kind,
                'path' => Storage::disk('media')->path($media->path),
            ];
        })->values()->all();
    }

    private function markFailed(PostPlatform $platform, string $message): void
    {
        $platform->update([
            'publish_status' => 'failed',
            'error_message' => mb_substr($message, 0, 2000),
            'attempts' => $platform->attempts + 1,
        ]);
    }

    private function finalise(Post $post): void
    {
        $platforms = $post->platforms;
        $successes = $platforms->where('publish_status', 'success')->count();
        $failures = $platforms->where('publish_status', 'failed')->count();

        if ($successes === $platforms->count()) {
            $status = 'published';
        } elseif ($successes > 0) {
            $status = 'partial';
        } elseif ($failures === $platforms->count()) {
            $status = 'failed';
        } else {
            return;
        }

        $post->update([
            'status' => $status,
            'published_at' => $successes > 0 ? now() : null,
        ]);

        $failedPlatforms = $platforms
            ->where('publish_status', 'failed')
            ->pluck('platform')
            ->map(fn (string $platform): string => ucfirst($platform))
            ->implode(', ');

        AppNotification::query()->create([
            'user_id' => $post->created_by,
            'type' => $status === 'published' ? 'success' : ($status === 'partial' ? 'warning' : 'error'),
            'title' => match ($status) {
                'published' => 'Publikasi selesai',
                'partial' => 'Publikasi selesai sebagian',
                default => 'Publikasi gagal',
            },
            'message' => match ($status) {
                'published' => "{$post->title} berhasil tayang di semua platform.",
                'partial' => "{$post->title}: {$successes} dari {$platforms->count()} platform berhasil. Gagal: {$failedPlatforms}.",
                default => "{$post->title} gagal dipublikasikan. Periksa koneksi akun dan detail error.",
            },
        ]);
    }
}
