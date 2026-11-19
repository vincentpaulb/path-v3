<?php

namespace App\Http\Controllers;

use App\Jobs\SendWebPush;
use App\Models\WebPushDelivery;
use App\Models\WebPushSubscription;
use App\Rules\WebPushEndpoint;
use App\Services\WebPushSettings;
use App\Services\WebPushSubscriptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebPushController extends Controller
{
    public function status(Request $request, WebPushSettings $settings, WebPushSubscriptions $subscriptions): JsonResponse
    {
        $current = $subscriptions->current($request);

        return response()->json([
            'available' => $settings->enabled(),
            'public_key' => $settings->keys()['publicKey'] ?? null,
            'endpoint' => $current?->endpoint,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, WebPushSettings $settings, WebPushSubscriptions $subscriptions): JsonResponse
    {
        abort_unless($settings->enabled(), 503, 'Desktop notifications are not configured yet.');
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', new WebPushEndpoint],
            'keys.p256dh' => ['required', 'string', 'max:100'],
            'keys.auth' => ['required', 'string', 'max:32'],
        ]);
        foreach (['p256dh' => 65, 'auth' => 16] as $key => $length) {
            $encoded = $data['keys'][$key];
            $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            if (! preg_match('/^[A-Za-z0-9_-]+={0,2}$/D', $encoded) || $decoded === false || strlen($decoded) !== $length
                || ($key === 'p256dh' && ord($decoded[0]) !== 4)) {
                throw ValidationException::withMessages(['keys.'.$key => 'Invalid browser subscription key.']);
            }
        }
        $subscription = DB::transaction(function () use ($request, $data, $subscriptions) {
            $hash = hash('sha256', $data['endpoint']);
            $existing = WebPushSubscription::where('endpoint_hash', $hash)->first();
            abort_if($existing && (int) $existing->user_id !== (int) $request->user()->id, 409, 'Desktop notifications belong to another account in this browser. Disable them there first.');
            $current = $subscriptions->current($request);
            if ($current && $current->endpoint_hash !== $hash) {
                $current->delete();
            }

            return WebPushSubscription::updateOrCreate(['endpoint_hash' => $hash], [
                'user_id' => $request->user()->id, 'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'], 'auth_token' => $data['keys']['auth'],
            ]);
        });

        return response()->json(['enabled' => true])->cookie(
            config('webpush.cookie'), $subscription->id, 60 * 24 * 365,
            '/', null, $request->isSecure(), true, false, 'lax'
        );
    }

    public function destroy(Request $request, WebPushSubscriptions $subscriptions): JsonResponse
    {
        $subscriptions->revokeCurrent($request);

        return response()->json(['enabled' => false]);
    }

    public function test(Request $request, WebPushSubscriptions $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->current($request);
        abort_unless($subscription, 422, 'Enable desktop notifications on this browser first.');
        $delivery = DB::transaction(function () use ($subscription) {
            $delivery = WebPushDelivery::create(['subscription_id' => $subscription->id]);
            SendWebPush::dispatch($delivery->id);

            return $delivery;
        });

        return response()->json([
            'queued' => true,
            'tag' => 'path-'.$delivery->id,
            'status_url' => route('notifications.push.test-status', $delivery->id),
        ], 202);
    }

    public function testStatus(Request $request, string $delivery, WebPushSubscriptions $subscriptions): JsonResponse
    {
        $subscription = $subscriptions->current($request);
        abort_unless($subscription, 404);
        $record = WebPushDelivery::whereKey($delivery)
            ->where('subscription_id', $subscription->id)->whereNull('notification_id')->firstOrFail();

        return response()->json(['accepted' => $record->sent_at !== null])
            ->header('Cache-Control', 'no-store, private');
    }
}
