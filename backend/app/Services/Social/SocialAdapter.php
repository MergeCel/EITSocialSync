<?php

namespace App\Services\Social;

use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;

interface SocialAdapter
{
    public function platform(): string;

    /**
     * @param  array<int, array{url: string, thumb_url: string, kind: string, path: string}>  $media
     */
    public function publish(SocialAccount $account, Post $post, PostPlatform $platform, array $media): PublishResult;

    /**
     * @return array{views: int, likes: int, comments: int, shares: int, saves: int}
     */
    public function fetchInsights(SocialAccount $account, PostPlatform $platform): array;

    public function refresh(SocialAccount $account): bool;

    public function followers(SocialAccount $account): ?int;
}
