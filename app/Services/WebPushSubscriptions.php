<?php

namespace App\Services;

use App\Jobs\SendWebPush;
use App\Models\WebPushDelivery;
use App\Models\WebPushSubscription;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cookie;

class WebPushSubscriptions
{
    public function current(Request $request): ?WebPushSubscription
    {
        $id = $request->cookie(config('webpush.cookie'));

        return $id && $request->user()
            ? WebPushSubscription::whereKey($id)->where('user_id', $request->user()->id)->first()
            : null;
    }

    public function revokeCurrent(Request $request): void
    {
        $this->current($request)?->delete();
        Cookie::queue(Cookie::forget(config('webpush.cookie')));
    }

    public function enqueue(DatabaseNotification $notification): void
    {
        if (! app(WebPushSettings::class)->enabled()) {
            return;
        }
        foreach (WebPushSubscription::where('user_id', $notification->notifiable_id)->pluck('id') as $id) {
            $delivery = WebPushDelivery::firstOrCreate([
                'subscription_id' => $id, 'notification_id' => $notification->id,
            ]);
            if ($delivery->wasRecentlyCreated) {
                SendWebPush::dispatch($delivery->id);
            }
        }
    }
}
