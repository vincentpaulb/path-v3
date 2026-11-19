<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WebPushDelivery;
use App\Models\WebPushSubscription;
use App\Services\WebPushTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use RuntimeException;

class SendWebPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly string $deliveryId)
    {
        $this->onConnection('database')->onQueue(config('webpush.queue'));
        // The job row and delivery row are committed together on the application database.
        $this->beforeCommit();
    }

    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(WebPushTransport $transport): void
    {
        $delivery = WebPushDelivery::find($this->deliveryId);
        if (! $delivery || $delivery->sent_at) {
            return;
        }
        $subscription = WebPushSubscription::find($delivery->subscription_id);
        if (! $subscription || ! User::find($subscription->user_id)) {
            return;
        }
        if ($delivery->notification_id) {
            $notification = DatabaseNotification::find($delivery->notification_id);
            if (! $notification || $notification->notifiable_type !== (new User)->getMorphClass()
                || (int) $notification->notifiable_id !== (int) $subscription->user_id || $notification->read_at) {
                return;
            }
        }
        $report = $transport->send($subscription, [
            'title' => $delivery->notification_id ? 'New PATH notification' : 'PATH notifications enabled',
            'body' => $delivery->notification_id ? 'You have a new update. Open PATH to view it.' : 'Desktop notifications are working on this browser.',
            'tag' => 'path-'.($delivery->notification_id ?: $delivery->id),
        ]);
        if ($report['expired']) {
            $subscription->delete();
        } elseif ($report['success']) {
            $delivery->update(['sent_at' => now()]);
        } else {
            throw new RuntimeException('Push service rejected delivery (HTTP '.(int) $report['status'].').');
        }
    }
}
