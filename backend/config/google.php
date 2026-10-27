<?php

return [

    'client_id' => env('GOOGLE_CLIENT_ID'),

    'client_secret' => env('GOOGLE_CLIENT_SECRET'),

    'ca_bundle' => env('GOOGLE_CA_BUNDLE'),

    'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/auth/google/callback'),

    'drive_scopes' => [
        'openid',
        'email',
        'profile',
        'https://www.googleapis.com/auth/drive.readonly',
    ],

    'state_ttl_minutes' => 10,

];
