<?php

namespace App\Services;

use App\Jobs\DeliverProposalActivity;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ProposalActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class ProposalNotificationService
{
    public function send(Proposal $proposal, User $actor, string $action, array $metadata = []): void
    {
        if ($this->shouldSkip($action)) {
            return;
        }

        DeliverProposalActivity::dispatch((string) Str::uuid(), [
            'proposal_id' => $proposal->id,
            'proposal_title' => $proposal->project_title,
            'proponent' => $proposal->proponent,
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_name' => $actor->full_name,
            'metadata' => $this->safeMetadata($metadata),
        ]);
    }

    public function deliver(string $eventId, array $payload): void
    {
        $proposal = Proposal::find($payload['proposal_id']);
        $actor = User::find($payload['actor_id']);
        if (! $proposal || ! $actor) {
            return;
        }

        foreach ($this->recipients($proposal, $actor, $payload['action']) as $recipient) {
            // Stable IDs make retries safe, including a crash after saving but before signaling.
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'path:'.$eventId.':'.$recipient->id)->toString();
            DB::transaction(function () use ($recipient, $id, $payload) {
                $notification = $recipient->notifications()->firstOrCreate(['id' => $id], [
                    'type' => ProposalActivityNotification::class,
                    'data' => $payload,
                    'read_at' => null,
                ]);
                app(WebPushSubscriptions::class)->enqueue($notification);
            });
            app(NotificationSignal::class)->publish((int) $recipient->id);
        }
    }

    private function recipients(Proposal $proposal, User $actor, string $action): Collection
    {
        $candidateIds = collect([(int) $proposal->user_id]);

        $evaluatorEmails = $proposal->evaluators()
            ->whereNotNull('evaluators.email')
            ->pluck('evaluators.email');

        if ($evaluatorEmails->isNotEmpty()) {
            $candidateIds = $candidateIds->merge(
                User::query()->whereIn('email', $evaluatorEmails)->where('role', 'Evaluator')->pluck('id')
            );
        }

        $candidateIds = $candidateIds->merge(
            User::query()
                ->where('province', $proposal->province)
                ->where(function ($query) {
                    $query->where('role', 'PSTO')
                        ->orWhereHas('accessRole', fn ($role) => $role->where('data_scope', 'province'));
                })
                ->pluck('id')
        );

        if ($action === 'Proposal marked as Funded') {
            $candidateIds = $candidateIds->merge(
                User::query()
                    ->where(function ($query) {
                        $query->where('role', 'Cashier')
                            ->orWhereHas('accessRole', fn ($role) => $role->where('slug', 'cashier'));
                    })
                    ->pluck('id')
            );
        }

        return User::query()
            ->whereIn('id', $candidateIds->filter()->unique())
            ->whereKeyNot($actor->id)
            ->with('accessRole.permissions')
            ->get()
            ->filter(fn (User $recipient) => $this->canReceiveFor($recipient, $proposal))
            ->values();
    }

    private function canReceiveFor(User $recipient, Proposal $proposal): bool
    {
        if ($recipient->isEvaluator()) {
            return $recipient->hasPermission('evaluator_portal.view')
                && $proposal->evaluators()->where('evaluators.email', $recipient->email)->exists();
        }

        if ($recipient->dataScope() === 'province' && strcasecmp((string) $recipient->province, (string) $proposal->province) !== 0) {
            return false;
        }

        return $recipient->hasPermission('proposals.view')
            || ($proposal->isFunded() && $recipient->hasPermission('funded.view'));
    }

    private function shouldSkip(string $action): bool
    {
        return in_array($action, [
            'Archived proposal',
            'Deleted own discussion comment',
            'Deleted own discussion reply',
        ], true);
    }

    private function safeMetadata(array $metadata): array
    {
        return collect($metadata)
            ->except(['before', 'after'])
            ->map(fn ($value) => is_scalar($value) || $value === null ? $value : null)
            ->filter(fn ($value) => $value !== null)
            ->take(8)
            ->all();
    }
}
