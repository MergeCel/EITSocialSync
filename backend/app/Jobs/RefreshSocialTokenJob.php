<?php

namespace App\Jobs;

use App\Models\SocialAccount;
use App\Services\Social\SocialManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshSocialTokenJob implements ShouldQueue
{
    use Queueable;

    public function handle(SocialManager $manager): void
    {
        $accounts = SocialAccount::query()
            ->whereIn('platform', ['instagram', 'tiktok'])
            ->where('status', 'connected')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', now()->addDays(3))
            ->get();

        foreach ($accounts as $account) {
            try {
                $manager->adapter($account->platform)->refresh($account);
            } catch (\Throwable) {
                $account->update(['status' => 'expired']);
            }
        }
    }
}
