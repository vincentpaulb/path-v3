<?php

// Read-only smoke check of the deployed proxy; does not create users or notifications.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$base = rtrim($argv[1] ?? 'http://path-v3.test', '/');
$request = Illuminate\Http\Request::create($base, server: [
    'SERVER_SOFTWARE' => in_array('--development', $argv, true) ? 'PHP (Development Server)' : 'Apache',
]);
$origin = $request->getSchemeAndHttpHost();
$ticket = app(App\Services\NotificationStreamTicket::class)->issue(2147483647, $origin);
$streamUrl = app(App\Services\NotificationStreamUrl::class)->forRequest($request);
$endpoint = str_starts_with($streamUrl, 'http') ? $streamUrl : $base.$streamUrl;
$curl = curl_init($endpoint.'?ticket='.rawurlencode($ticket));
$received = '';
$allowedOrigin = '';
curl_setopt_array($curl, [
    CURLOPT_TIMEOUT => 6,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_HTTPHEADER => ['Origin: '.$origin],
    CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$allowedOrigin): int {
        if (stripos($header, 'Access-Control-Allow-Origin:') === 0) {
            $allowedOrigin = trim(substr($header, strlen('Access-Control-Allow-Origin:')));
        }
        return strlen($header);
    },
    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$received): int {
        $received .= $chunk;
        return str_contains($received, 'event: changed') ? 0 : strlen($chunk);
    },
]);
curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
$passed = $status === 200 && str_contains($type ?? '', 'text/event-stream') && str_contains($received, 'event: changed') && $allowedOrigin === $origin;
echo json_encode(['endpoint' => $endpoint, 'http_status' => $status, 'content_type' => $type, 'allowed_origin' => $allowedOrigin, 'received_change_signal' => $passed], JSON_PRETTY_PRINT).PHP_EOL;
curl_close($curl);
exit($passed ? 0 : 1);
