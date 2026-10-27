<?php

return [

    'disk' => env('MEDIA_DISK', 'media'),

    'max_size' => 512 * 1024 * 1024,

    'max_carousel_items' => 15,

    'signed_url_minutes' => 360,

    'thumbnail_width' => 720,

    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),

    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),

];
