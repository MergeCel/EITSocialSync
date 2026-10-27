<?php

namespace App\Services\Social;

class PublishResult
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $url = null,
    ) {}
}
