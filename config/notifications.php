<?php

return [
    'queue' => 'notifications',
    'connection' => 'database',
    // Reverse proxy this same-origin path to the PHP push command.
    'stream_url' => env('NOTIFICATION_STREAM_URL'),
    'listen' => env('NOTIFICATION_LISTEN', '127.0.0.1:8091'),
    'signal_path' => env('NOTIFICATION_SIGNAL_PATH', storage_path('framework/notification-signals')),
    'ticket_lifetime' => 300,
    'max_clients' => 200,
];
