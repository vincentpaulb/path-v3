<?php

namespace App\Http\Controllers;

use App\Models\Evaluator;
use App\Models\EvaluatorRemark;
use App\Models\Proposal;
use App\Models\RequiredDocument;
use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EvaluatorPortalController extends Controller
{
    public function __construct(private ActivityService $activity) {}

    private function evaluator(Request $request): Evaluator
    {
        abort_unless($request->user()?->isEvaluator(), 403, 'The Evaluator Portal is available only to Evaluator accounts.');

        return Evaluator::query()
            ->whereNotNull('email')
            ->where('email', $request->user()->email)
            ->firstOrFail();
    }

    private function ensureAssigned(Evaluator $evaluator, Proposal $proposal): void
    {
        abort_unless(
            $proposal->evaluators()->whereKey($evaluator->id)->exists(),
            403,
            'This proposal is not assigned to your evaluator account.'
        );
    }

    public function index(Request $request)
    {

        $range = new \App\Support\DateRangeFilter($request);        $evaluator = $this->evaluator($request);
        $query = $evaluator->proposals();

        $range->apply($query, 'proposals.date_submitted');

        if ($request->filled('status')) {
            $query->wherePivot('evaluation_status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search')->trim().'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('project_title', 'like', $term)
                    ->orWhere('proponent', 'like', $term);
            });
        }

        $proposals = $query
            ->latest('evaluator_proposal.created_at')
            ->paginate(8, [
                'proposals.id', 'project_title', 'proponent', 'province', 'sector', 'date_submitted',
            ])
            ->withQueryString();

        $statusCounts = DB::table('evaluator_proposal')
            ->where('evaluator_id', $evaluator->id)
            ->selectRaw('evaluation_status, COUNT(*) as total')
            ->groupBy('evaluation_status')
            ->pluck('total', 'evaluation_status');

        return view('evaluator-portal.index', compact('evaluator', 'proposals', 'statusCounts'));
    }

    public function show(Request $request, Proposal $proposal)
    {
        $evaluator = $this->evaluator($request);
        $this->ensureAssigned($evaluator, $proposal);

        $proposal->load([
            'owner',
            'evaluators',
            'evaluatorRemarks.evaluator',
            'evaluatorRemarks.user',
            'missingDocuments.creator',
            'documentStatuses.requiredDocument',
            'comments.user',
            'comments.replies.user',
            // Evaluators have a private audit view. Only actions performed by
            // the logged-in evaluator account are loaded into the relationship.
            'activities' => fn ($query) => $query
                ->where('user_id', $request->user()->id)
                ->latest('created_at'),
            'activities.user',
            'files.uploader',
        ]);

        $proposal->activities->each(function ($activity) {
            if ($activity->action === 'Evaluator status changed' && !empty($activity->metadata['to'])) {
                $from = !empty($activity->metadata['from']) ? " from {$activity->metadata['from']}" : '';
                $activity->action = "Evaluator status changed{$from} to {$activity->metadata['to']}";
            }
        });

        $requiredDocumentRecords = RequiredDocument::query()->orderBy('id')->get();
        $requiredDocuments = $requiredDocumentRecords->pluck('name')->values();

        return view('proposals.show', compact('proposal', 'evaluator', 'requiredDocuments', 'requiredDocumentRecords'));
    }

    public function updateStatus(Request $request, Proposal $proposal)
    {
        $evaluator = $this->evaluator($request);
        $this->ensureAssigned($evaluator, $proposal);

        $data = $request->validate([
            'evaluation_status' => ['required', Rule::in(['Started', 'Ongoing', 'Approved'])],
        ]);

        $pivot = DB::table('evaluator_proposal')
            ->where('proposal_id', $proposal->id)
            ->where('evaluator_id', $evaluator->id)
            ->first(['evaluation_status', 'evaluated_at']);

        $oldStatus = $pivot?->evaluation_status ?? 'Assigned';
        $pivotUpdate = ['evaluation_status' => $data['evaluation_status']];

        // evaluated_at is a permanent first-completion timestamp used by RTEC
        // Monitoring for quarterly completion and honorarium reporting. Reopening
        // an evaluation later must not erase or move the original completion date.
        if ($data['evaluation_status'] === 'Approved' && empty($pivot?->evaluated_at)) {
            $pivotUpdate['evaluated_at'] = now();
        }

        $proposal->evaluators()->updateExistingPivot($evaluator->id, $pivotUpdate);

        $this->activity->record(
            $proposal,
            $request->user(),
            'Evaluator status changed',
            [
                'evaluator_id' => $evaluator->id,
                'from' => $oldStatus,
                'to' => $data['evaluation_status'],
            ]
        );

        return back()->with('success', 'Evaluation status updated.');
    }

    public function remark(Request $request, Proposal $proposal)
    {
        $evaluator = $this->evaluator($request);
        $this->ensureAssigned($evaluator, $proposal);

        $data = $request->validate([
            'action' => ['required', Rule::in(['Forward', 'Return'])],
            'remark' => ['required', 'string', 'max:3000'],
        ]);

        $sequence = EvaluatorRemark::query()
            ->where('proposal_id', $proposal->id)
            ->where('evaluator_id', $evaluator->id)
            ->where('action', $data['action'])
            ->count() + 1;

        EvaluatorRemark::create($data + [
            'proposal_id' => $proposal->id,
            'evaluator_id' => $evaluator->id,
            'user_id' => $request->user()->id,
        ]);

        if ($data['action'] === 'Return') {
            $proposal->evaluators()->updateExistingPivot($evaluator->id, ['evaluation_status' => 'Ongoing']);
        }

        $this->activity->record(
            $proposal,
            $request->user(),
            "Evaluator {$data['action']} #{$sequence}",
            [
                'evaluator_id' => $evaluator->id,
                'remark' => $data['remark'],
            ]
        );

        return back()->with('success', "{$data['action']} #{$sequence} remark added.");
    }
}
