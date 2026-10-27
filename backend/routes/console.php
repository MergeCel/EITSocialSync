<?php

use App\Jobs\DispatchDuePostsJob;
use App\Jobs\FetchEngagementStatsJob;
use App\Jobs\RefreshSocialTokenJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new DispatchDuePostsJob)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

foreach (['06:00', '12:00', '18:00'] as $time) {
    Schedule::job(new FetchEngagementStatsJob)
        ->dailyAt($time)
        ->timezone('Asia/Jakarta')
        ->withoutOverlapping(30)
        ->onOneServer();
}

Schedule::job(new RefreshSocialTokenJob)
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();
