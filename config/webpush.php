<?php

return [
    'enabled' => env('WEB_PUSH_ENABLED', true),
    'subject' => env('WEB_PUSH_SUBJECT', 'https://path-v3.test'),
    'public_key' => env('WEB_PUSH_PUBLIC_KEY'),
    'private_key' => env('WEB_PUSH_PRIVATE_KEY'),
    'key_path' => storage_path('app/private/webpush-vapid.json'),
    'cookie' => 'path_push_device',
    'queue' => 'webpush',
];
