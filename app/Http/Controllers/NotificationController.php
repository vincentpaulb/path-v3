<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\User;
use App\Services\NotificationSignal;
use App\Services\NotificationStreamTicket;
use App\Services\NotificationStreamUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(8),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $stored = $request->user()->notifications()->findOrFail($notification);
        $stored->markAsRead();
        app(NotificationSignal::class)->publish((int) $request->user()->id);

        $proposalId = (int) ($stored->data['proposal_id'] ?? 0);
        $proposal = Proposal::query()
            ->visibleTo($request->user())
            ->whereKey($proposalId)
            ->first();

        if (! $proposal) {
            return redirect()->route('notifications.index')
                ->with('warning', 'This proposal is no longer available or is outside your access scope.');
        }

        if ($request->user()->isEvaluator()) {
            abort_unless($request->user()->hasPermission('evaluator_portal.view'), 403);

            return redirect()->to($this->destination($request->user(), $proposal, $stored->data));
        }

        abort_unless(
            $request->user()->hasPermission('proposals.view')
                || ($proposal->isFunded() && $request->user()->hasPermission('funded.view')),
            403
        );

        return redirect()->to($this->destination($request->user(), $proposal, $stored->data));
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);
        app(NotificationSignal::class)->publish((int) $request->user()->id);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function status(Request $request): JsonResponse
    {
        $notifications = $request->user()->notifications()->latest()->limit(6)->get();

        return response()->json([
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'notifications' => $notifications->map(fn ($notification) => [
                'id' => $notification->id,
                'action' => $notification->data['action'] ?? 'Proposal updated',
                'proponent' => $notification->data['proponent'] ?? 'Proposal',
                'actor_name' => $notification->data['actor_name'] ?? 'PATH user',
                'unread' => $notification->read_at === null,
                'time' => $notification->created_at->diffForHumans(null, true),
                'open_url' => route('notifications.open', $notification->id),
            ])->values(),
        ]);
    }

    public function streamTicket(Request $request, NotificationStreamTicket $tickets): JsonResponse
    {
        return response()->json([
            'url' => app(NotificationStreamUrl::class)->forRequest($request).'?ticket='.rawurlencode($tickets->issue((int) $request->user()->id, $request->getSchemeAndHttpHost())),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function destination(User $user, Proposal $proposal, array $data): string
    {
        $action = strtolower((string) ($data['action'] ?? ''));
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $evaluatorId = (int) ($metadata['evaluator_id'] ?? 0);
        $canTargetExactEvaluator = $evaluatorId > 0 && ! $user->isEvaluator();

        if (str_contains($action, 'liquidation monitoring')
            && $user->hasPermission('monitoring.liquidation.view')) {
            return route('monitoring.liquidation', [
                'proposal_id' => $proposal->id,
                'funding_year' => $proposal->date_funded?->year,
                'province' => $proposal->province,
                'sector' => $proposal->sector,
            ]).'#liquidation-proposal-'.$proposal->id;
        }

        if ((str_contains($action, 'rental') || str_contains($action, 'otp option'))
            && $user->hasPermission('rental_records.view')) {
            return route('rental-records.index', $proposal).'#rental-records';
        }

        $anchor = match (true) {
            str_contains($action, 'evaluator remark') => 'evaluator-remarks',
            str_contains($action, 'discussion'),
            str_contains($action, 'comment'),
            str_contains($action, 'replied') => 'proposal-discussion-updates',
            str_contains($action, 'lacking-document') => 'document-review-history',
            str_contains($action, 'required documents'),
            str_contains($action, 'checklist') => 'documents-checklist-items',
            str_contains($action, 'evaluator forward'),
            str_contains($action, 'evaluator return') => 'evaluator-remarks',
            str_contains($action, 'evaluator assignments') => 'evaluator-assignments',
            str_contains($action, 'evaluator status') && $canTargetExactEvaluator => 'evaluator-status-'.$evaluatorId,
            str_contains($action, 'evaluator status') => 'evaluator-status',
            str_contains($action, 'file') => 'project-files-list',
            str_contains($action, 'moa status'),
            str_contains($action, 'check status'),
            str_contains($action, 'funding status'),
            str_contains($action, 'marked as funded'),
            str_contains($action, 'proposal code'),
            str_contains($action, 'updated proposal'),
            str_contains($action, 'created proposal'),
            str_contains($action, 'restored proposal') => 'proposal-details-grid',
            default => 'audit-trail-entries',
        };

        $route = $user->isEvaluator()
            ? route('evaluator.proposals.show', $proposal)
            : ($proposal->isFunded() && ! $user->hasPermission('proposals.view') && $user->hasPermission('funded.view')
                ? route('proposals.show', ['proposal' => $proposal, 'from' => 'funded'])
                : route('proposals.show', $proposal));

        return $route.'#'.$anchor;
    }
}
