<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\MissingDocument;
use App\Models\Proposal;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $range = new \App\Support\DateRangeFilter($request);
        $user = $request->user();
        $year = (int) $request->integer('year', now()->year);

        // Every dashboard query starts from the user's visible proposal scope.
        // For Evaluators this means ONLY proposals assigned to their matching
        // evaluator-directory record (matched by email).
        $visible = Proposal::query()->visibleTo($user);
        $base = (clone $visible)->tap(fn ($q) => $range->apply($q, 'date_submitted', $year));

        // One conditional aggregate replaces nine scans of the same visible set.
        $aggregate = (clone $base)->selectRaw(<<<'SQL'
            COUNT(CASE WHEN document_status = 'Complete' THEN 1 END) AS complete,
            COALESCE(SUM(CASE WHEN document_status = 'Complete' THEN amount ELSE 0 END), 0) AS complete_amount,
            COUNT(CASE WHEN document_status = 'Incomplete' THEN 1 END) AS incomplete,
            COALESCE(SUM(CASE WHEN document_status = 'Incomplete' THEN amount ELSE 0 END), 0) AS incomplete_amount,
            COUNT(CASE WHEN status = 'Approved' AND date_funded IS NULL THEN 1 END) AS approved,
            COALESCE(SUM(CASE WHEN status = 'Approved' AND date_funded IS NULL THEN amount ELSE 0 END), 0) AS approved_amount,
            COUNT(CASE WHEN (date_funded IS NOT NULL OR status IN ('Approved', 'Funded')) THEN 1 END) AS approved_cumulative,
            COALESCE(SUM(CASE WHEN (date_funded IS NOT NULL OR status IN ('Approved', 'Funded')) THEN amount ELSE 0 END), 0) AS approved_cumulative_amount,
            COUNT(CASE WHEN (date_funded IS NOT NULL OR status = 'Funded') THEN 1 END) AS funded,
            COALESCE(SUM(amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN (date_funded IS NOT NULL OR status = 'Funded') THEN amount ELSE 0 END), 0) AS funded_amount
        SQL)->first();

        $stats = collect($aggregate->getAttributes())
            ->only(['complete', 'complete_amount', 'incomplete', 'incomplete_amount', 'approved', 'approved_amount', 'approved_cumulative', 'approved_cumulative_amount', 'funded', 'total_amount', 'funded_amount'])
            ->all();

        $provinceExpression = "CASE WHEN province IN ('Negros', 'Negros Oriental') THEN 'Negros' ELSE province END";

        $provinceSectorQuery = fn ($query) => $query
            ->selectRaw("{$provinceExpression} as province, sector, count(*) as total")
            ->groupByRaw("{$provinceExpression}, sector")
            ->orderBy('province')
            ->get();

        $submittedRows = $provinceSectorQuery(clone $base);
        $approvedRows = $provinceSectorQuery((clone $base)->where(fn ($q) => $q->whereNotNull('date_funded')->orWhereIn('status', ['Approved', 'Funded'])));
        $chartProvinces = $submittedRows->pluck('province')->unique()->values();
        $buildProgramChart = fn ($rows) => [
            'labels' => $chartProvinces,
            'datasets' => collect(['SETUP', 'GIA', 'CEST', 'SMART'])->map(fn (string $sector) => [
                'label' => $sector,
                'data' => $chartProvinces->map(fn (string $province) => (int) ($rows
                    ->first(fn ($row) => $row->province === $province && $row->sector === $sector)?->total ?? 0)),
            ])->values(),
        ];
        $programCharts = [
            'submitted' => $buildProgramChart($submittedRows),
            'approved' => $buildProgramChart($approvedRows),
        ];

        $missing = MissingDocument::query()
            ->where('missing_documents.id', '=', function ($query) {
                $query->select('latest_missing.id')
                    ->from('missing_documents as latest_missing')
                    ->whereColumn('latest_missing.proposal_id', 'missing_documents.proposal_id')
                    ->orderByDesc('latest_missing.created_at')
                    ->orderByDesc('latest_missing.id')
                    ->limit(1);
            })
            ->whereNotNull('description')
            ->whereRaw("TRIM(description) <> ''")
            ->whereRaw('LOWER(TRIM(description)) <> ?', ['no lacking documents'])
            ->whereHas('proposal', fn ($query) => $query
                ->visibleTo($user)
                ->tap(fn ($q) => $range->apply($q, 'date_submitted', $year))
                ->where(function ($statusQuery) {
                    $statusQuery->whereNull('document_status')
                        ->orWhere('document_status', '!=', 'Complete');
                }))
            ->with('proposal:id,project_title,proponent')
            ->latest('created_at')
            ->limit(25)
            ->get(['id', 'proposal_id', 'description', 'created_at']);

        $activity = ActivityLog::query()
            ->whereHas('proposal', fn ($query) => $query->visibleTo($user)->tap(fn ($q) => $range->apply($q, 'date_submitted', $year)))
            ->when($user->isEvaluator(), fn ($query) => $query->where('user_id', $user->id))
            ->with([
                'proposal:id,project_title',
                'user:id,first_name,middle_initial,last_name',
            ])
            ->latest('created_at')
            ->limit(25)
            ->get(['id', 'proposal_id', 'user_id', 'action', 'created_at']);

        // Do not expose years that only exist in proposals outside the user's scope.
        $years = Proposal::query()
            ->visibleTo($user)
            ->whereNotNull('date_submitted')
            ->selectRaw((\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite' ? "CAST(strftime('%Y', date_submitted) AS INTEGER)" : 'YEAR(date_submitted)').' as year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year');

        if (! $years->contains($year)) {
            $years->prepend($year);
        }

        return view('dashboard', compact(
            'range',
            'year',
            'years',
            'stats',
            'programCharts',
            'missing',
            'activity'
        ));
    }
}
