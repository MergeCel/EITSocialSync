<?php

namespace App\Services\Social;

use InvalidArgumentException;

class SocialManager
{
    /**
     * @return array<string, SocialAdapter>
     */
    public function adapters(): array
    {
        return [
            'facebook' => new FacebookAdapter,
            'instagram' => new InstagramAdapter,
            'tiktok' => new TikTokAdapter,
        ];
    }

    public function adapter(string $platform): SocialAdapter
    {
        return $this->adapters()[$platform]
            ?? throw new InvalidArgumentException("Platform {$platform} tidak didukung.");
    }
}
