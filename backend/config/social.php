<?php

return [

    'provider' => env('SOCIAL_PROVIDER', 'buffer'),

    'buffer' => [
        'api_url' => rtrim(env('BUFFER_API_URL', 'https://api.buffer.com'), '/'),
        'manage_url' => env('BUFFER_MANAGE_URL', 'https://publish.buffer.com/channels'),
        'max_channels' => (int) env('BUFFER_MAX_CHANNELS', 3),
        'ca_bundle' => env('BUFFER_CA_BUNDLE') ?: env('GOOGLE_CA_BUNDLE'),
    ],

    'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
    ],

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID') ?: env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET') ?: env('FACEBOOK_CLIENT_SECRET'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    ],

    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    /*
    | Meta and TikTok fetch media from a public URL. In local development this
    | must be an HTTPS tunnel (for example Cloudflare Tunnel or ngrok), because
    | localhost is not reachable by their servers.
    */
    'media_public_base_url' => env('MEDIA_PUBLIC_BASE_URL', env('APP_URL', 'http://localhost:8000')),

    'state_ttl_minutes' => 10,

];
