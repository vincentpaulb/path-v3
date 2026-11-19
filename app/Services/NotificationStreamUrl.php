<?php

namespace App\Services;

use Illuminate\Http\Request;

class NotificationStreamUrl
{
    public function forRequest(Request $request): string
    {
        if ($configured = config('notifications.stream_url')) {
            return $configured;
        }

        // artisan serve has no reverse proxy. Local development can connect
        // directly to the existing loopback daemon without blocking PHP requests.
        if ($request->getScheme() === 'http'
            && in_array($request->getHost(), ['localhost', '127.0.0.1'], true)
            && str_contains((string) $request->server('SERVER_SOFTWARE'), 'Development Server')) {
            $port = parse_url('tcp://'.config('notifications.listen'), PHP_URL_PORT);

            return 'http://'.$request->getHost().':'.$port.'/notification-events';
        }

        return '/notification-events';
    }
}
