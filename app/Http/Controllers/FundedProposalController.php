<?php

namespace App\Http\Controllers;

use App\Enums\{FundingStatus, Sector};
use App\Models\Province;
use App\Models\Proposal;
use App\Services\ActivityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FundedProposalController extends Controller
{
    public function __construct(private ActivityService $activity) {}

    public function index(Request $request)
    {

        $range = new \App\Support\DateRangeFilter($request);        $query = Proposal::visibleTo($request->user())->funded();

        $range->apply($query, 'date_funded');

        foreach (['sector', 'province', 'funding_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }

        if ($request->filled('search')) {
            $search = '%'.$request->search.'%';
            $query->where(fn ($builder) => $builder
                ->where('project_title', 'like', $search)
                ->orWhere('proponent', 'like', $search)
                ->orWhere('proposal_code', 'like', $search));
        }

        $proposals = $query->latest('date_funded')->paginate(8, [
            'id', 'proposal_code', 'project_title', 'proponent', 'sector', 'province', 'date_funded',
            'funding_status', 'amount',
        ])->withQueryString();

        return view('funded.index', [
            'proposals' => $proposals,
            'sectors' => Sector::values(),
            'provinces' => Province::allValues(),
            'fundingStatuses' => FundingStatus::values(),
        ]);
    }

    public function updateStatus(Request $request, Proposal $proposal)
    {
        abort_unless($request->user()->hasPermission('funded.update_status'), 403);
        $this->guardProposal($request, $proposal);

        $data = $request->validate([
            'funding_status' => ['required', Rule::in(FundingStatus::values())],
        ]);

        $proposal->update($data);
        $this->activity->record($proposal, $request->user(), 'Updated funding status', [
            'funding_status' => $data['funding_status'],
        ]);

        return back()->with('success', 'Funding status updated.');
    }

    private function guardProposal(Request $request, Proposal $proposal): void
    {
        if ($request->user()->dataScope() === 'province') {
            abort_unless($proposal->province === $request->user()->province, 403);
        }
    }
}
