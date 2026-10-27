<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\PublishPostJob;
use App\Models\MediaFile;
use App\Models\Post;
use App\Services\AppStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostController extends Controller
{
    public function __construct(private readonly AppStateService $state) {}

    public function store(Request $request): JsonResponse
    {
        $this->requireEditor($request);
        $payload = $this->validated($request);
        $this->assertCompatible($payload);

        $status = $payload['asDraft'] ?? false
            ? 'draft'
            : (filled($payload['scheduledAt'] ?? null) ? 'scheduled' : 'publishing');

        $post = DB::transaction(function () use ($request, $payload, $status): Post {
            $post = Post::query()->create([
                'title' => trim($payload['title']),
                'content_type' => $payload['type'],
                'caption' => trim((string) ($payload['caption'] ?? '')),
                'hashtags' => trim((string) ($payload['hashtags'] ?? '')),
                'status' => $status,
                'scheduled_at' => $payload['scheduledAt'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->syncRelations($post, $payload);

            return $post;
        });

        $this->log($request, match ($status) {
            'draft' => 'menyimpan draft',
            'scheduled' => 'menjadwalkan',
            default => 'mengirim untuk publikasi',
        }, $post->title);

        if ($status === 'publishing') {
            PublishPostJob::dispatch($post->id)->onQueue('high');
        }

        return response()->json(
            $this->state->post($post->fresh(['platforms.engagement', 'orderedMedia'])),
            201,
        );
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        $this->requireEditor($request);

        abort_unless(
            in_array($post->status, ['draft', 'scheduled', 'cancelled'], true),
            422,
            'Post yang sudah dipublikasikan tidak dapat diubah.',
        );

        $payload = $this->validated($request);
        $this->assertCompatible($payload);

        $status = ($payload['asDraft'] ?? false)
            ? 'draft'
            : (filled($payload['scheduledAt'] ?? null) ? 'scheduled' : 'publishing');

        DB::transaction(function () use ($post, $payload, $status): void {
            $post->update([
                'title' => trim($payload['title']),
                'content_type' => $payload['type'],
                'caption' => trim((string) ($payload['caption'] ?? '')),
                'hashtags' => trim((string) ($payload['hashtags'] ?? '')),
                'status' => $status,
                'scheduled_at' => $payload['scheduledAt'] ?? null,
                'published_at' => null,
            ]);

            $post->platforms()->delete();
            $post->media()->detach();
            $this->syncRelations($post, $payload);
        });

        $this->log($request, $status === 'draft' ? 'menyimpan draft' : ($status === 'scheduled' ? 'menjadwalkan' : 'mengirim untuk publikasi'), $post->title);

        if ($status === 'publishing') {
            PublishPostJob::dispatch($post->id)->onQueue('high');
        }

        return response()->json(
            $this->state->post($post->fresh(['platforms.engagement', 'orderedMedia'])),
        );
    }

    public function cancel(Request $request, Post $post): JsonResponse
    {
        $this->requireEditor($request);

        if ($post->status !== 'scheduled') {
            return response()->json(['message' => 'Hanya post terjadwal yang dapat dibatalkan.'], 422);
        }

        $post->update(['status' => 'cancelled']);
        $this->log($request, 'membatalkan jadwal', $post->title);

        return response()->json(
            $this->state->post($post->fresh(['platforms.engagement', 'orderedMedia'])),
        );
    }

    public function publish(Request $request, Post $post): JsonResponse
    {
        $this->requireEditor($request);

        abort_unless(in_array($post->status, ['draft', 'scheduled', 'cancelled'], true), 422, 'Post ini sudah diproses.');

        $post->update(['status' => 'publishing', 'scheduled_at' => null]);
        PublishPostJob::dispatch($post->id)->onQueue('high');

        return response()->json(
            $this->state->post($post->fresh(['platforms.engagement', 'orderedMedia'])),
        );
    }

    public function destroy(Request $request, Post $post): JsonResponse
    {
        $this->requireEditor($request);

        if (! in_array($post->status, ['draft', 'cancelled'], true)) {
            return response()->json(['message' => 'Hanya draft atau post yang dibatalkan yang dapat dihapus.'], 422);
        }

        $title = $post->title;
        $post->delete();
        $this->log($request, 'menghapus draft', $title);

        return response()->json(['message' => 'Post dihapus.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:story,video,photo,carousel'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'hashtags' => ['nullable', 'string', 'max:1000'],
            'mediaIds' => ['required', 'array', 'min:1', 'max:15'],
            'mediaIds.*' => ['required', 'uuid', 'distinct', 'exists:media_files,id'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => ['required', 'distinct', 'in:instagram,tiktok,facebook'],
            'scheduledAt' => ['nullable', 'date'],
            'settings' => ['nullable', 'array'],
            'asDraft' => ['sometimes', 'boolean'],
        ]);

        if (($validated['type'] ?? null) !== 'story' && blank($validated['caption'] ?? null)) {
            throw ValidationException::withMessages(['caption' => 'Caption wajib diisi untuk tipe konten ini.']);
        }

        if (filled($validated['scheduledAt'] ?? null) && ! $request->boolean('asDraft')) {
            if (now()->gte($validated['scheduledAt'])) {
                throw ValidationException::withMessages(['scheduledAt' => 'Jadwal harus berada di waktu yang akan datang.']);
            }
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertCompatible(array $payload): void
    {
        $type = $payload['type'];
        $platforms = $payload['platforms'];
        $media = MediaFile::query()->whereIn('id', $payload['mediaIds'])->get();

        if ($media->count() !== count($payload['mediaIds'])) {
            throw ValidationException::withMessages(['mediaIds' => 'Salah satu media tidak ditemukan.']);
        }

        if ($type === 'story' && count($payload['mediaIds']) !== 1) {
            throw ValidationException::withMessages(['mediaIds' => 'Story hanya mendukung satu media.']);
        }

        if (in_array($type, ['photo', 'carousel'], true) && $media->contains(fn (MediaFile $item): bool => $item->kind !== 'image')) {
            throw ValidationException::withMessages(['mediaIds' => 'Tipe konten ini hanya mendukung foto.']);
        }

        if ($type === 'carousel' && (count($payload['mediaIds']) < 2 || count($payload['mediaIds']) > 15)) {
            throw ValidationException::withMessages(['mediaIds' => 'Carousel membutuhkan 2–15 foto.']);
        }

        if (in_array('tiktok', $platforms, true) && $type === 'story') {
            throw ValidationException::withMessages(['platforms' => 'TikTok tidak mendukung Story pada MVP ini.']);
        }

        if (in_array('facebook', $platforms, true) && $type === 'carousel') {
            throw ValidationException::withMessages(['platforms' => 'Facebook tidak mendukung carousel melalui API ini.']);
        }

        if (in_array('instagram', $platforms, true) && $type === 'carousel' && count($payload['mediaIds']) > 10) {
            throw ValidationException::withMessages(['mediaIds' => 'Instagram membatasi carousel maksimal 10 item.']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncRelations(Post $post, array $payload): void
    {
        $post->media()->attach(
            collect($payload['mediaIds'])
                ->values()
                ->mapWithKeys(fn (string $id, int $index): array => [$id => ['sort_order' => $index]])
                ->all(),
        );

        foreach ($payload['platforms'] as $platform) {
            $post->platforms()->create([
                'platform' => $platform,
                'is_enabled' => true,
                'publish_status' => 'pending',
                'advanced_settings' => ($payload['settings'] ?? [])[$platform] ?? [],
            ]);
        }
    }

    private function log(Request $request, string $action, string $subject): void
    {
        activity()
            ->causedBy($request->user())
            ->withProperties(['subject' => $subject])
            ->log($action);
    }
}
