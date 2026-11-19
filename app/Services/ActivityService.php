<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Proposal;
use App\Models\User;
use Throwable;

class ActivityService
{
    public function __construct(private ProposalNotificationService $notifications) {}

    public function record(Proposal $proposal, User $actor, string $action, array $metadata = []): void
    {
        ActivityLog::create([
            'proposal_id' => $proposal->id,
            'user_id' => $actor->id,
            'action' => $action,
            'sector' => $proposal->sector,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        // Notification delivery must not interrupt an already completed update.
        try {
            $this->notifications->send($proposal, $actor, $action, $metadata);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
