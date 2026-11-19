<?php

namespace Tests\Feature;

use App\Services\NotificationStreamUrl;
use Illuminate\Http\Request;
use Tests\TestCase;

class NotificationDevelopmentUrlTest extends TestCase
{
    public function test_artisan_localhost_uses_the_loopback_push_port_and_matching_csp(): void
    {
        config(['notifications.stream_url' => null]);
        $request = Request::create('http://localhost:8000/login', server: ['SERVER_SOFTWARE' => 'PHP/8.3.26 (Development Server)']);
        $this->assertSame('http://localhost:8091/notification-events', app(NotificationStreamUrl::class)->forRequest($request));
        $response = $this->withServerVariables(['SERVER_SOFTWARE' => 'PHP/8.3.26 (Development Server)'])->get('http://localhost:8000/login');
        $response->assertRedirect('/?login=1');
        $this->assertStringContainsString("connect-src 'self' http://localhost:8091", $response->headers->get('Content-Security-Policy'));
    }

    public function test_apache_and_nonlocal_hosts_keep_the_same_origin_proxy(): void
    {
        config(['notifications.stream_url' => null]);
        foreach (['http://path-v3.test', 'http://192.168.1.10:8000', 'https://localhost:8000'] as $url) {
            $request = Request::create($url, server: ['SERVER_SOFTWARE' => 'Apache/2.4']);
            $this->assertSame('/notification-events', app(NotificationStreamUrl::class)->forRequest($request));
        }
    }
}
