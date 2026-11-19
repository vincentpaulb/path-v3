<?php

namespace App\Http\Middleware;

use App\Services\NotificationStreamUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $streamUrl = app(NotificationStreamUrl::class)->forRequest($request);
        $streamOrigin = str_starts_with($streamUrl, 'http://') || str_starts_with($streamUrl, 'https://')
            ? parse_url($streamUrl, PHP_URL_SCHEME).'://'.parse_url($streamUrl, PHP_URL_HOST).(parse_url($streamUrl, PHP_URL_PORT) ? ':'.parse_url($streamUrl, PHP_URL_PORT) : '')
            : '';
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com",
            "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com",
            "img-src 'self' data: blob: https://tile.openstreetmap.org https://api.mapbox.com https://unpkg.com",
            "connect-src 'self'".($streamOrigin !== '' ? ' '.$streamOrigin : ''),
        ];

        if (app()->isProduction() && $request->isSecure()) {
            $directives[] = 'upgrade-insecure-requests';
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $directives));
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
