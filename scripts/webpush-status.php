<?php

use App\Models\User;
use App\Models\WebPushDelivery;
use App\Models\WebPushSubscription;
use App\Services\WebPushSettings;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Read-only diagnostics: never print subscription capability URLs or key material.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo json_encode([
    'configured' => app(WebPushSettings::class)->enabled(),
    'pending_jobs' => DB::table('jobs')->where('queue', 'webpush')->count(),
    'failed_jobs' => DB::table('failed_jobs')->where('queue', 'webpush')->count(),
    'subscriptions' => WebPushSubscription::all()->map(fn ($subscription) => [
        'id' => $subscription->id,
        'user_exists' => User::whereKey($subscription->user_id)->exists(),
        'provider' => parse_url($subscription->endpoint, PHP_URL_HOST),
    ]),
    'recent_deliveries' => WebPushDelivery::latest()->limit(10)->get()->map(fn ($delivery) => [
        'id' => $delivery->id,
        'is_test' => $delivery->notification_id === null,
        'created_at' => $delivery->created_at?->toIso8601String(),
        'accepted_at' => $delivery->sent_at?->toIso8601String(),
    ]),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
