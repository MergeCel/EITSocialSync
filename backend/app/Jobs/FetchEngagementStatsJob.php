<?php

namespace App\Jobs;

use App\Models\BufferConnection;
use App\Models\EngagementStat;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\BufferService;
use App\Services\Social\SocialManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchEngagementStatsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function handle(SocialManager $manager, BufferService $buffer): void
    {
        if (config('social.provider') === 'buffer') {
            $this->fetchBufferMetrics($buffer);

            return;
        }

        $accounts = SocialAccount::query()
            ->where('status', 'connected')
            ->whereNotNull('access_token')
            ->where(fn ($query) => $query->whereNull('token_expires_at')->orWhere('token_expires_at', '>', now()))
            ->get();

        foreach ($accounts as $account) {
            try {
                $followers = $manager->adapter($account->platform)->followers($account);

                if ($followers !== null) {
                    $account->update(['followers' => $followers]);
                }
            } catch (\Throwable) {
                // Insight retrieval must not break the scheduler.
            }
        }

        PostPlatform::query()
            ->where('publish_status', 'success')
            ->whereNotNull('platform_post_id')
            ->chunkById(100, function ($platforms) use ($manager, $accounts): void {
                foreach ($platforms as $platform) {
                    $account = $accounts->firstWhere('platform', $platform->platform);

                    if (! $account) {
                        continue;
                    }

                    try {
                        $metrics = $manager->adapter($platform->platform)->fetchInsights($account, $platform);

                        EngagementStat::query()->updateOrCreate(
                            ['post_platform_id' => $platform->id],
                            [
                                'views' => max(0, (int) $metrics['views']),
                                'likes' => max(0, (int) $metrics['likes']),
                                'comments' => max(0, (int) $metrics['comments']),
                                'shares' => max(0, (int) $metrics['shares']),
                                'saves' => max(0, (int) $metrics['saves']),
                                'raw' => $metrics,
                                'fetched_at' => now(),
                            ],
                        );
                    } catch (\Throwable) {
                        // Keep the previous snapshot when a platform request fails.
                    }
                }
            });
    }

    private function fetchBufferMetrics(BufferService $buffer): void
    {
        PostPlatform::query()
            ->with('post')
            ->where('publish_status', 'success')
            ->whereNotNull('platform_post_id')
            ->chunkById(100, function ($platforms) use ($buffer): void {
                $connections = BufferConnection::query()
                    ->whereIn('user_id', $platforms->pluck('post.created_by')->filter()->unique())
                    ->where('status', 'active')
                    ->get()
                    ->keyBy('user_id');

                foreach ($platforms as $platform) {
                    $connection = $connections->get($platform->post?->created_by);

                    if (! $connection) {
                        continue;
                    }

                    try {
                        $metrics = $buffer->metrics($connection, (string) $platform->platform_post_id);

                        EngagementStat::query()->updateOrCreate(
                            ['post_platform_id' => $platform->id],
                            [
                                'views' => max(0, (int) $metrics['views']),
                                'likes' => max(0, (int) $metrics['likes']),
                                'comments' => max(0, (int) $metrics['comments']),
                                'shares' => max(0, (int) $metrics['shares']),
                                'saves' => max(0, (int) $metrics['saves']),
                                'raw' => $metrics,
                                'fetched_at' => now(),
                            ],
                        );
                    } catch (\Throwable) {
                        // Preserve the previous Buffer snapshot on transient failures.
                    }
                }
            });
    }
}
