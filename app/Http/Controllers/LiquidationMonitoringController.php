<?php

namespace App\Http\Controllers;

use App\Models\LiquidationRecord;
use App\Models\Proposal;
use App\Services\ActivityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LiquidationMonitoringController extends Controller
{
    public function __construct(private ActivityService $activity) {}

    public function index(Request $request): View
    {
        $range = new \App\Support\DateRangeFilter($request);
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $province = trim((string) $request->query('province', ''));
        $fundingYearValue = trim((string) $request->query('funding_year', ''));
        $showAllFundingYears = $fundingYearValue === 'all';
        $fundingYear = ctype_digit($fundingYearValue) ? (int) $fundingYearValue : null;
        $defaultYearFrom = now()->year - 4;
        $defaultYearTo = now()->year;
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        // An explicit funding-date range is more precise than a single funding year.
        // When either range boundary is supplied, use the date range and ignore the
        // quick Funding Year selector for filtering purposes.
        $hasDateRange = $dateFrom !== '' || $dateTo !== '';

        $eligible = Proposal::query()
            ->visibleTo($user)
            ->whereNotNull('date_funded')
            ->where('amount', '>', 0);

        $sectorOptions = (clone $eligible)->whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector');
        $sector = trim((string) $request->query('sector', ''));
        if (! $request->has('sector') && $sectorOptions->contains('SETUP')) {
            $sector = 'SETUP';
        }

        $fundingYears = (clone $eligible)
            ->selectRaw($this->yearExpression('date_funded').' AS funding_year')
            ->distinct()->orderBy('funding_year')->pluck('funding_year')->map(fn ($year) => (int) $year);
        $provinceOptions = (clone $eligible)->whereNotNull('province')->distinct()->orderBy('province')->pluck('province');

        $filtered = clone $eligible;
        $targetProposalId = (int) $request->integer('proposal_id');
        $filtered->when($targetProposalId > 0, fn ($query) => $query->whereKey($targetProposalId));
        $filtered->when($province !== '', fn ($query) => $query->where('province', $province));
        $filtered->when($sector !== '', fn ($query) => $query->where('sector', $sector));

        if ($hasDateRange) {
            if ($dateFrom !== '') {
                $filtered->whereDate('date_funded', '>=', $dateFrom);
            }

            if ($dateTo !== '') {
                $filtered->whereDate('date_funded', '<=', $dateTo);
            }
        } elseif ($fundingYear !== null) {
            $filtered->whereYear('date_funded', $fundingYear);
        } elseif (! $showAllFundingYears) {
            $filtered->whereBetween('date_funded', ["{$defaultYearFrom}-01-01", "{$defaultYearTo}-12-31"]);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $filtered->where(fn ($query) => $query
                ->where('proponent', 'like', $like)
                ->orWhere('project_title', 'like', $like)
                ->orWhere('province', 'like', $like)
                ->orWhere('sector', 'like', $like));
        }

        // Build summaries from the same source used for the visible rows. This keeps
        // equipment-based liquidation (Date Liquidated + Purchased Amount), legacy
        // ledger entries, and manual monitoring records consistent everywhere.
        $summaryRows = (clone $filtered)
            ->select(['id', 'project_title', 'proponent', 'province', 'sector', 'amount', 'status', 'date_funded'])
            ->with('liquidationRecord')
            ->withCount(['acquisitions', 'liquidationTransactions'])
            ->withSum(['acquisitions as equipment_liquidated_sum' => fn ($query) => $query->whereNotNull('date_liquidated')], 'actual_cost')
            ->withSum(['liquidationTransactions as ledger_disbursement_sum' => fn ($query) => $query->where('status', 'Posted')], 'disbursement_amount')
            ->withSum(['liquidationTransactions as ledger_refund_sum' => fn ($query) => $query->where('status', 'Posted')], 'refund_amount')
            ->orderBy('date_funded')
            ->orderBy('proponent')
            ->get()
            ->map(fn (Proposal $proposal) => $this->buildRow($proposal));

        $yearSummaries = $summaryRows
            ->groupBy('funding_year')
            ->map(fn (Collection $rows, $year) => $this->buildYearSummary((int) $year, $rows))
            ->values();
        $yearProjectCounts = $yearSummaries->pluck('released_projects', 'year');

        $yearPaginators = $yearSummaries->mapWithKeys(function (array $summary) use ($filtered, $request) {
            $year = $summary['year'];
            $pageName = 'year_page_'.$year;
            $currentPage = LengthAwarePaginator::resolveCurrentPage($pageName);
            $items = (clone $filtered)
                ->whereYear('date_funded', $year)
                ->select(['id', 'project_title', 'proponent', 'province', 'sector', 'amount', 'status', 'date_funded'])
                ->with('liquidationRecord')
                ->withCount(['acquisitions', 'liquidationTransactions'])
                ->withSum(['acquisitions as equipment_liquidated_sum' => fn ($query) => $query->whereNotNull('date_liquidated')], 'actual_cost')
                ->withSum(['liquidationTransactions as ledger_disbursement_sum' => fn ($query) => $query->where('status', 'Posted')], 'disbursement_amount')
                ->withSum(['liquidationTransactions as ledger_refund_sum' => fn ($query) => $query->where('status', 'Posted')], 'refund_amount')
                ->orderBy('date_funded')
                ->orderBy('proponent')
                ->forPage($currentPage, 5)
                ->get()
                ->map(fn (Proposal $proposal) => $this->buildRow($proposal));

            $paginator = new LengthAwarePaginator($items, $summary['released_projects'], 5, $currentPage, [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => $pageName,
            ]);

            return [$year => $paginator];
        });
        $groupedRows = $yearPaginators->map(fn (LengthAwarePaginator $paginator) => $paginator->getCollection());

        return view('monitoring.liquidation', compact(
            'search',
            'province',
            'provinceOptions',
            'sector',
            'sectorOptions',
            'fundingYear',
            'fundingYears',
            'showAllFundingYears',
            'defaultYearFrom',
            'defaultYearTo',
            'dateFrom',
            'dateTo',
            'hasDateRange',
            'groupedRows',
            'yearPaginators',
            'yearProjectCounts',
            'yearSummaries'
        ));
    }

    public function update(Request $request, Proposal $proposal): RedirectResponse
    {
        $scopedProposal = Proposal::query()
            ->visibleTo($request->user())
            ->whereKey($proposal->getKey())
            ->firstOrFail();

        abort_unless($scopedProposal->date_funded && (float) $scopedProposal->amount > 0, 422, 'Liquidation monitoring is available only for funded proposals.');

        $hasEquipmentDatabase = $scopedProposal->acquisitions()->exists();
        $hasDetailedLedger = $scopedProposal->liquidationTransactions()->exists();
        $hasAutomaticLiquidation = $hasEquipmentDatabase || $hasDetailedLedger;

        $data = $request->validate([
            'amount_released' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'amount_liquidated' => [$hasAutomaticLiquidation ? 'nullable' : 'required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'reported_unexpended_balance' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'refunded_unexpended_amount' => [$hasDetailedLedger ? 'nullable' : 'required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'liquidation_report_status' => ['required', Rule::in(['No Report', 'Partial', 'Complete'])],
            'proof_of_refund_received' => ['nullable', 'boolean'],
            'attachments_complete' => ['nullable', 'boolean'],
            'status_report_submitted' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:5000'],
        ]);

        $amountReleased = ($data['amount_released'] ?? null) === null
            ? (float) $scopedProposal->amount
            : (float) $data['amount_released'];
        $amountLiquidated = $hasEquipmentDatabase
            ? max(0, (float) $scopedProposal->acquisitions()->whereNotNull('date_liquidated')->sum('actual_cost'))
            : ($hasDetailedLedger
                ? max(0, (float) $scopedProposal->liquidationTransactions()->where('status', 'Posted')->sum('disbursement_amount'))
                : (float) $data['amount_liquidated']);
        $reportedUnexpended = (float) $data['reported_unexpended_balance'];
        $refundedUnexpended = $hasDetailedLedger
            ? max(0, (float) $scopedProposal->liquidationTransactions()->where('status', 'Posted')->sum('refund_amount'))
            : (float) $data['refunded_unexpended_amount'];

        if ($amountLiquidated > $amountReleased + 0.005) {
            return back()->withErrors(['amount_liquidated' => 'Amount liquidated cannot exceed the amount released.'])->withInput();
        }

        if ($refundedUnexpended > $reportedUnexpended + 0.005) {
            return back()->withErrors(['refunded_unexpended_amount' => 'Refunded unexpended amount cannot exceed the reported unexpended balance.'])->withInput();
        }

        if ($amountLiquidated + $refundedUnexpended > $amountReleased + 0.005) {
            return back()->withErrors(['refunded_unexpended_amount' => 'Amount liquidated plus refunded unexpended balance cannot exceed the amount released.'])->withInput();
        }

        $payload = [
            'amount_released' => $amountReleased,
            'amount_liquidated' => $amountLiquidated,
            'reported_unexpended_balance' => $reportedUnexpended,
            'refunded_unexpended_amount' => $refundedUnexpended,
            'proof_of_refund_received' => $request->boolean('proof_of_refund_received'),
            'liquidation_report_status' => $data['liquidation_report_status'],
            'attachments_complete' => $request->boolean('attachments_complete'),
            'status_report_submitted' => $request->boolean('status_report_submitted'),
            'remarks' => $data['remarks'] ?? null,
            'updated_by' => $request->user()->id,
        ];

        $before = $scopedProposal->liquidationRecord?->only(array_keys($payload));

        LiquidationRecord::query()->updateOrCreate(
            ['proposal_id' => $scopedProposal->id],
            $payload
        );

        $this->activity->record($scopedProposal, $request->user(), 'Updated liquidation monitoring record', [
            'before' => $before,
            'after' => $payload,
        ]);

        return back()->with('success', 'Liquidation monitoring record updated.');
    }

    private function yearExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', {$column}) AS INTEGER)"
            : "YEAR({$column})";
    }

    private function buildRow(Proposal $proposal): array
    {
        $record = $proposal->liquidationRecord;
        $released = $record?->amount_released !== null
            ? (float) $record->amount_released
            : (float) $proposal->amount;
        $hasEquipmentDatabase = (int) ($proposal->acquisitions_count ?? 0) > 0;
        $hasDetailedLedger = (int) ($proposal->liquidation_transactions_count ?? 0) > 0;
        $liquidated = $hasEquipmentDatabase
            ? max(0, (float) ($proposal->equipment_liquidated_sum ?? 0))
            : ($hasDetailedLedger
                ? max(0, (float) ($proposal->ledger_disbursement_sum ?? 0))
                : (float) ($record?->amount_liquidated ?? 0));
        $reportedUnexpended = (float) ($record?->reported_unexpended_balance ?? 0);
        $refundedUnexpended = $hasDetailedLedger
            ? max(0, (float) ($proposal->ledger_refund_sum ?? 0))
            : (float) ($record?->refunded_unexpended_amount ?? 0);
        $proofReceived = (bool) ($record?->proof_of_refund_received ?? false);
        $reportStatus = (string) ($record?->liquidation_report_status ?? 'No Report');
        $attachmentsComplete = (bool) ($record?->attachments_complete ?? false);
        $statusReportSubmitted = (bool) ($record?->status_report_submitted ?? false);

        $countedRefund = $proofReceived ? min($refundedUnexpended, $reportedUnexpended) : 0.0;
        $effectiveLiquidated = min($released, max(0, $liquidated + $countedRefund));
        $unliquidated = max(0, $released - $effectiveLiquidated);
        $unrefundedReportedBalance = max(0, $reportedUnexpended - $countedRefund);
        $fullyLiquidated = $reportStatus === 'Complete' && $attachmentsComplete && $unliquidated <= 0.005;
        $liquidatedProject = $reportStatus !== 'No Report'
            && $unliquidated <= 0.005
            && ($reportedUnexpended <= 0.005 || ($proofReceived && $refundedUnexpended + 0.005 >= $reportedUnexpended));

        return [
            'proposal_id' => $proposal->id,
            'beneficiary' => $proposal->proponent,
            'project_title' => $proposal->project_title,
            'province' => $proposal->province,
            'sector' => $proposal->sector,
            'funding_year' => (int) $proposal->date_funded->year,
            'date_funded' => $proposal->date_funded,
            'released' => $released,
            'amount_liquidated' => $liquidated,
            'reported_unexpended' => $reportedUnexpended,
            'refunded_unexpended' => $refundedUnexpended,
            'proof_received' => $proofReceived,
            'report_status' => $reportStatus,
            'attachments_complete' => $attachmentsComplete,
            'status_report_submitted' => $statusReportSubmitted,
            'remarks' => (string) ($record?->remarks ?? ''),
            'unexpended_remarks' => $this->unexpendedRemark($reportedUnexpended, $refundedUnexpended, $proofReceived),
            'unliquidated' => $unliquidated,
            'unrefunded_reported_balance' => $unrefundedReportedBalance,
            'effective_liquidated' => $effectiveLiquidated,
            'liquidated_project' => $liquidatedProject,
            'fully_liquidated' => $fullyLiquidated,
            'generated_remarks' => $this->generatedRemark($reportStatus, $attachmentsComplete, $statusReportSubmitted, $unliquidated),
            'has_record' => (bool) $record,
            'has_equipment_database' => $hasEquipmentDatabase,
            'has_detailed_ledger' => $hasDetailedLedger,
        ];
    }

    private function buildYearSummary(int $year, Collection $rows): array
    {
        $released = (float) $rows->sum('released');
        $projects = $rows->count();
        $liquidated = (float) $rows->sum('effective_liquidated');
        $liquidatedProjects = $rows->where('liquidated_project', true)->count();
        $unliquidated = (float) $rows->sum('unliquidated');
        $unliquidatedProjects = $rows->filter(fn (array $row) => $row['unliquidated'] > 0.005)->count();

        $fullyRows = $rows->where('fully_liquidated', true);
        $fullyLiquidated = (float) $fullyRows->sum('released');
        $fullyLiquidatedProjects = $fullyRows->count();

        $noReportRows = $rows->where('report_status', 'No Report');
        $noReportAmount = (float) $noReportRows->sum('released');
        $noReportProjects = $noReportRows->count();

        $reportedUnexpended = (float) $rows->sum('reported_unexpended');
        $reportedProjects = $rows->filter(fn (array $row) => $row['reported_unexpended'] > 0.005)->count();
        $refunded = (float) $rows->sum(fn (array $row) => $row['proof_received']
            ? min($row['refunded_unexpended'], $row['reported_unexpended'])
            : 0.0);
        $refundedProjects = $rows->filter(fn (array $row) => $row['proof_received'] && $row['refunded_unexpended'] > 0.005)->count();
        $noProof = (float) $rows->sum('unrefunded_reported_balance');
        $noProofProjects = $rows->filter(fn (array $row) => $row['unrefunded_reported_balance'] > 0.005)->count();

        $rate = fn (float $part, float $whole) => $whole > 0 ? round(($part / $whole) * 100, 2) : 0.0;

        return [
            'year' => $year,
            'released' => $released,
            'released_projects' => $projects,
            'liquidated' => $liquidated,
            'liquidated_projects' => $liquidatedProjects,
            'percentage_liquidated' => $rate($liquidated, $released),
            'unliquidated' => $unliquidated,
            'unliquidated_projects' => $unliquidatedProjects,
            'percentage_unliquidated' => $rate($unliquidated, $released),
            'fully_liquidated' => $fullyLiquidated,
            'fully_liquidated_projects' => $fullyLiquidatedProjects,
            'percentage_fully_liquidated' => $rate($fullyLiquidated, $released),
            'no_report_amount' => $noReportAmount,
            'no_report_projects' => $noReportProjects,
            'percentage_unutilized' => $rate($noReportAmount, $released),
            'reported_unexpended' => $reportedUnexpended,
            'reported_unexpended_projects' => $reportedProjects,
            'refunded' => $refunded,
            'refunded_projects' => $refundedProjects,
            'percentage_refund' => $rate($refunded, $reportedUnexpended),
            'no_proof' => $noProof,
            'no_proof_projects' => $noProofProjects,
            'percentage_non_refund' => $rate($noProof, $reportedUnexpended),
        ];
    }

    private function unexpendedRemark(float $reported, float $refunded, bool $proofReceived): string
    {
        if ($reported <= 0.005) {
            return '—';
        }

        if (! $proofReceived || $refunded <= 0.005) {
            return 'No proof of refund';
        }

        if ($refunded + 0.005 >= $reported) {
            return 'Refunded';
        }

        return 'Partially refunded';
    }

    private function generatedRemark(string $reportStatus, bool $attachmentsComplete, bool $statusReportSubmitted, float $unliquidated): string
    {
        if ($reportStatus === 'No Report') {
            return 'No report on fund utilization';
        }

        if ($reportStatus === 'Complete' && $attachmentsComplete && $unliquidated <= 0.005) {
            return $statusReportSubmitted
                ? 'Fully liquidated w/ status report'
                : 'Fully liquidated & no status report';
        }

        if ($reportStatus === 'Complete') {
            return $statusReportSubmitted
                ? 'Liquidation report complete · balance remains'
                : 'Liquidation report complete · no status report';
        }

        return $statusReportSubmitted
            ? 'Partial liquidation & status report'
            : 'Partial liquidation & no status report';
    }
}
