<?php

namespace App\Services;

use App\Models\WebPushSubscription;
use App\Rules\WebPushEndpoint;
use GuzzleHttp\Client;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use RuntimeException;
use Throwable;

class WebPushTransport
{
    public function __construct(private readonly ?ClientInterface $client = null) {}

    public function send(WebPushSubscription $subscription, array $payload): array
    {
        if (! WebPushEndpoint::allowed($subscription->endpoint)) {
            return ['success' => false, 'expired' => true, 'status' => 0];
        }
        $settings = app(WebPushSettings::class);
        if (! $settings->enabled()) {
            throw new RuntimeException('Web Push signing keys are unavailable.');
        }
        try {
            $push = new WebPush(
                ['VAPID' => ['subject' => config('webpush.subject')] + $settings->keys()],
                ['TTL' => 3600, 'urgency' => 'normal'],
                $this->client ?? new Client(['timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]),
            );
            $report = $push->sendOneNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => 'aes128gcm',
            ]), json_encode($payload, JSON_THROW_ON_ERROR));

            return ['success' => $report->isSuccess(), 'expired' => $report->isSubscriptionExpired(), 'status' => $report->getResponse()?->getStatusCode() ?? 0];
        } catch (Throwable) {
            // Never persist capability URLs, subscription secrets, or VAPID headers in failed job logs.
            throw new RuntimeException('Web Push delivery failed; check network access and signing configuration.');
        }
    }
}
