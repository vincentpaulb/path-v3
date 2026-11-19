<?php

namespace App\Jobs;

use App\Services\ProposalNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverProposalActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public readonly string $eventId, public readonly array $payload)
    {
        $this->onConnection(config('notifications.connection'));
        $this->onQueue(config('notifications.queue'));
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [5, 15, 60, 120];
    }

    public function handle(ProposalNotificationService $notifications): void
    {
        $notifications->deliver($this->eventId, $this->payload);
    }
}
