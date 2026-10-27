<?php

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DispatchDuePostsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Post::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($posts): void {
                foreach ($posts as $post) {
                    $claimed = Post::query()
                        ->whereKey($post->id)
                        ->where('status', 'scheduled')
                        ->update(['status' => 'publishing']);

                    if ($claimed === 1) {
                        PublishPostJob::dispatch($post->id)->onQueue('high');
                        Log::info('Scheduled post queued', ['post_id' => $post->id]);
                    }
                }
            });
    }
}
