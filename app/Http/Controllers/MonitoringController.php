<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\Province;
use App\Models\RentalRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    private const EXTERNAL_HONORARIUM = 1500;

    public function rtec(Request $request): View
    {
        $range = new \App\Support\DateRangeFilter($request);
        $user = $request->user();
        $visibleProposalIds = Proposal::query()
            ->visibleTo($user)
            ->select('proposals.id');

        $years = $this->rtecYears($user, $visibleProposalIds);
        $year = (int) $request->integer('year', now()->year);

        if (! in_array($year, $years, true)) {
            $years[] = $year;
            rsort($years);
        }

        $provinceDefinitions = $this->provinceDefinitions();

        $rtecProposals = Proposal::query()
            ->visibleTo($user)
            ->tap(fn ($q) => $range->apply($q, 'date_submitted', $year))
            ->whereHas('evaluators')
            ->get(['id', 'province', 'sector', 'status', 'amount', 'date_submitted', 'quarter_submitted']);

        $setupProposals = Proposal::query()
            ->visibleTo($user)
            ->where('sector', 'SETUP')
            ->tap(fn ($q) => $range->apply($q, 'date_submitted', $year))
            ->get(['id', 'province', 'status', 'amount', 'date_submitted', 'quarter_submitted']);

        $approvedSetupProposals = Proposal::query()
            ->visibleTo($user)
            ->where('sector', 'SETUP')
            ->where(fn ($q) => $q->whereNotNull('date_funded')->orWhereIn('status', ['Approved', 'Funded']))
            ->whereNotNull('date_approved')
            ->tap(fn ($q) => $range->apply($q, 'date_approved', $year))
            ->get(['id', 'province', 'status', 'amount', 'date_approved', 'quarter_approved']);

        $evaluatorRows = $this->evaluatorSummary($visibleProposalIds, $year, $range);
        $externalEvaluators = $evaluatorRows->where('classification', 'External')->values();
        $internalEvaluators = $evaluatorRows->where('classification', 'Internal')->values();

        $rtecByProvince = $this->countByProvinceQuarter(
            $rtecProposals,
            $provinceDefinitions,
            fn ($proposal) => $this->proposalQuarter($proposal->date_submitted, $proposal->quarter_submitted)
        );

        $statusTables = collect([
            'Ongoing' => ['Ongoing Evaluation'],
            'Approved' => ['Approved', 'Funded'],
            'Disapproved' => ['Disapproved'],
            'Parked' => ['Parked'],
            'Cancelled' => ['Cancelled'],
        ])->mapWithKeys(function (array $statuses, string $label) use ($rtecProposals, $provinceDefinitions) {
            $filtered = $rtecProposals->whereIn('status', $statuses)->values();

            return [$label => $this->countByProvinceQuarter(
                $filtered,
                $provinceDefinitions,
                fn ($proposal) => $this->proposalQuarter($proposal->date_submitted, $proposal->quarter_submitted)
            )];
        });

        $proposedSetupFund = $this->amountByProvinceQuarter(
            $setupProposals,
            $provinceDefinitions,
            fn ($proposal) => $this->proposalQuarter($proposal->date_submitted, $proposal->quarter_submitted)
        );

        $approvedSetupFund = $this->amountByProvinceQuarter(
            $approvedSetupProposals,
            $provinceDefinitions,
            fn ($proposal) => $this->proposalQuarter($proposal->date_approved, $proposal->quarter_approved)
        );

        $completedEvaluations = $evaluatorRows->sum('completed');
        $externalCompleted = $externalEvaluators->sum('completed');
        $honorariumTotal = $externalEvaluators->sum('honorarium');

        $summary = [
            'rtec_proposals' => $rtecProposals->count(),
            'completed_evaluations' => $completedEvaluations,
            'external_completed' => $externalCompleted,
            'honorarium_total' => $honorariumTotal,
            'proposed_setup_fund' => $setupProposals->sum(fn ($proposal) => (float) $proposal->amount),
            'approved_setup_fund' => $approvedSetupProposals->sum(fn ($proposal) => (float) $proposal->amount),
        ];

        return view('monitoring.rtec', compact(
            'range',
            'year',
            'years',
            'summary',
            'externalEvaluators',
            'internalEvaluators',
            'rtecByProvince',
            'statusTables',
            'proposedSetupFund',
            'approvedSetupFund'
        ));
    }

    public function rental(Request $request): View
    {
        $range = new \App\Support\DateRangeFilter($request);
        $user = $request->user();
        $search = trim((string) $request->query('search', ''));
        $province = trim((string) $request->query('province', ''));
        $sector = trim((string) $request->query('sector', ''));
        $fundingYearValue = trim((string) $request->query('funding_year', ''));
        $showAllFundingYears = $fundingYearValue === 'all';
        $fundingYear = ctype_digit($fundingYearValue) ? (int) $fundingYearValue : null;
        $defaultYearFrom = now()->year - 4;
        $defaultYearTo = now()->year;

        $eligible = Proposal::query()
            ->visibleTo($user)
            ->whereNotNull('date_funded')
            ->where('amount', '>', 0);
        $fundingYearExpression = $this->yearExpression('date_funded');

        $fundingYears = (clone $eligible)
            ->selectRaw($fundingYearExpression.' AS funding_year')
            ->distinct()->orderBy('funding_year')->pluck('funding_year')->map(fn ($year) => (int) $year);
        $provinceOptions = (clone $eligible)->whereNotNull('province')->distinct()->orderBy('province')->pluck('province');
        $sectorOptions = (clone $eligible)->whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector');

        $filtered = clone $eligible;
        $filtered->when($province !== '', fn ($query) => $query->where('province', $province));
        $filtered->when($sector !== '', fn ($query) => $query->where('sector', $sector));

        if ($range->active()) {
            $range->apply($filtered, 'date_funded');
        } elseif ($fundingYear !== null) {
            $filtered->whereYear('date_funded', $fundingYear);
        } elseif (! $showAllFundingYears) {
            $filtered->whereBetween('date_funded', ["{$defaultYearFrom}-01-01", "{$defaultYearTo}-12-31"]);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $filtered->where(function ($query) use ($like, $search) {
                $query->where('proponent', 'like', $like)
                    ->orWhere('project_title', 'like', $like)
                    ->orWhere('province', 'like', $like)
                    ->orWhere('sector', 'like', $like);
                if (preg_match('/^\d{4}$/', $search)) {
                    $query->orWhereYear('date_funded', (int) $search);
                }
            });
        }

        $refundTotals = RentalRecord::query()
            ->selectRaw('proposal_id, COALESCE(SUM(rental_payment), 0) AS refund')
            ->groupBy('proposal_id');

        $creditTotals = \App\Models\RentalScheduleAdjustment::query()->selectRaw('proposal_id, SUM(unexpended_amount) AS credit')->groupBy('proposal_id');

        $summaryFundingYearExpression = $this->yearExpression('proposals.date_funded');
        $summaryRows = (clone $filtered)
            ->leftJoinSub($refundTotals, 'rental_totals', 'rental_totals.proposal_id', '=', 'proposals.id')
            ->leftJoinSub($creditTotals, 'rental_credits', 'rental_credits.proposal_id', '=', 'proposals.id')
            ->selectRaw($summaryFundingYearExpression.' AS funding_year,
                COUNT(*) AS projects,
                COALESCE(SUM(proposals.amount), 0) AS fund,
                COALESCE(SUM(COALESCE(rental_totals.refund, 0)), 0) AS refund,
                COALESCE(SUM(COALESCE(rental_credits.credit, 0)), 0) AS credit')
            ->groupByRaw($summaryFundingYearExpression)
            ->orderBy('funding_year')
            ->get();

        $yearSummary = $summaryRows->map(function ($summary) {
            $fund = (float) $summary->fund;
            $refund = (float) $summary->refund;
            $balance = max(0, $fund - $refund - (float) $summary->credit);

            return [
                'year' => (int) $summary->funding_year,
                'projects' => (int) $summary->projects,
                'fund' => $fund,
                'refund' => $refund,
                'pulled_out' => 0.0,
                'balance' => $balance,
                'refund_rate' => $fund > 0 ? round(($refund / $fund) * 100, 2) : 0.0,
            ];
        });
        $yearProjectCounts = $yearSummary->pluck('projects', 'year');

        $totals = [
            'projects' => (int) $yearSummary->sum('projects'),
            'fund' => (float) $yearSummary->sum('fund'),
            'refund' => (float) $yearSummary->sum('refund'),
            'pulled_out' => 0.0,
            'balance' => (float) $yearSummary->sum('balance'),
        ];
        $totals['refund_rate'] = $totals['fund'] > 0
            ? round(($totals['refund'] / $totals['fund']) * 100, 2)
            : 0.0;

        $yearPaginators = $yearSummary->mapWithKeys(function (array $summary) use ($filtered, $request) {
            $year = $summary['year'];
            $pageName = 'year_page_'.$year;
            $currentPage = LengthAwarePaginator::resolveCurrentPage($pageName);
            $items = (clone $filtered)
                ->whereYear('date_funded', $year)
                ->withSum('rentalRecords as refund_total', 'rental_payment')
                ->withSum('rentalScheduleAdjustments as unexpended_total', 'unexpended_amount')
                ->orderBy('date_funded')
                ->orderBy('proponent')
                ->forPage($currentPage, 5)
                ->get(['id', 'project_title', 'proponent', 'province', 'sector', 'amount', 'status', 'date_funded'])
                ->map(fn (Proposal $proposal) => $this->buildRentalMonitoringRow($proposal));

            $paginator = new LengthAwarePaginator($items, $summary['projects'], 5, $currentPage, [
                'path' => $request->url(),
                'query' => $request->query(),
                'pageName' => $pageName,
            ]);

            return [$year => $paginator];
        });
        $groupedRows = $yearPaginators->map(fn (LengthAwarePaginator $paginator) => $paginator->getCollection());

        return view('monitoring.rental', compact(
            'range',
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
            'groupedRows',
            'yearPaginators',
            'yearProjectCounts',
            'yearSummary',
            'totals'
        ));
    }

    private function buildRentalMonitoringRow(Proposal $proposal): array
    {
        $fund = (float) $proposal->amount;
        $refund = (float) ($proposal->refund_total ?? 0);
        $balance = max(0, $fund - $refund - (float) ($proposal->unexpended_total ?? 0));

        return [
            'proposal_id' => $proposal->id,
            'beneficiary' => $proposal->proponent,
            'project_title' => $proposal->project_title,
            'province' => $proposal->province,
            'sector' => $proposal->sector,
            'funding_year' => (int) $proposal->date_funded->year,
            'date_funded' => $proposal->date_funded,
            'fund' => $fund,
            'refund' => $refund,
            // PATH does not yet persist a pulled-out amount.
            'pulled_out' => 0.0,
            'balance' => $balance,
            'refund_rate' => $fund > 0 ? round(($refund / $fund) * 100, 2) : 0.0,
        ];
    }

    public function refundRate(Request $request): View
    {
        $range = new \App\Support\DateRangeFilter($request);
        $user = $request->user();
        $year = (int) $request->integer('year', now()->year);
        $sector = trim((string) $request->query('sector', ''));
        $provinceDefinitions = $this->provinceDefinitions();
        $months = collect(range(1, 12))->mapWithKeys(fn (int $month) => [
            $month => Carbon::create(null, $month, 1)->format('F'),
        ]);

        $eligible = Proposal::query()
            ->visibleTo($user)
            ->whereNotNull('date_funded')
            ->where('amount', '>', 0);

        $sectorOptions = (clone $eligible)->whereNotNull('sector')->distinct()->orderBy('sector')->pluck('sector');
        $scoped = clone $eligible;
        $scoped->when($sector !== '', fn ($query) => $query->where('sector', $sector));

        $relevantRecords = fn ($query) => $query->where(fn ($dates) => $dates
            ->where(fn ($due) => $range->apply($due, 'due_month', $year))
            ->orWhere(fn ($paid) => $paid->where('rental_payment', '>', 0)
                ->where(fn ($receipt) => $range->apply($receipt, 'or_date', $year))));

        $proposals = (clone $scoped)
            ->where(fn ($query) => $query
                ->whereHas('rentalRecords', $relevantRecords)
                ->orWhereDoesntHave('rentalRecords'))
            ->with(['rentalRecords' => fn ($query) => $relevantRecords($query)->orderBy('installment_no')])
            ->withExists('rentalRecords')
            ->get(['id', 'project_title', 'proponent', 'province', 'sector', 'amount', 'date_funded', 'rental_schedule_customized']);

        $years = collect([now()->year, $year]);
        $recordScope = RentalRecord::query()->whereHas('proposal', fn ($query) => $query
            ->visibleTo($user)
            ->whereNotNull('date_funded')
            ->where('amount', '>', 0)
            ->when($sector !== '', fn ($proposalQuery) => $proposalQuery->where('sector', $sector)));
        $years->push(...(clone $recordScope)->whereNotNull('due_month')->pluck('due_month')->map(fn ($date) => Carbon::parse($date)->year)->all());
        $years->push(...(clone $recordScope)->whereNotNull('or_date')->pluck('or_date')->map(fn ($date) => Carbon::parse($date)->year)->all());

        foreach ((clone $scoped)->whereDoesntHave('rentalRecords')->pluck('date_funded') as $dateFunded) {
            $firstDue = $dateFunded->copy()->addYear()->startOfMonth();
            $years->push((int) $firstDue->year);
            $years->push((int) $firstDue->copy()->addMonthsNoOverflow(35)->year);
        }
        $years = $years->filter()->unique()->sortDesc()->values();

        $collection = $this->emptyMonthlyProvinceRows($provinceDefinitions);
        $receivable = $this->emptyMonthlyProvinceRows($provinceDefinitions);

        foreach ($proposals as $proposal) {
            $provinceKey = $this->provinceKey((string) $proposal->province, $provinceDefinitions);
            if ($provinceKey === null) {
                continue;
            }

            $records = $proposal->rentalRecords;

            if ($records->isEmpty()) {
                if ($proposal->rental_records_exists || $proposal->rental_schedule_customized) continue;
                foreach ($this->virtualOriginalRentalSchedule($proposal) as $virtualRecord) {
                    $dueDate = $virtualRecord['due_month'];
                    if (! $range->includes($dueDate, $year)) {
                        continue;
                    }

                    $row = $receivable->get($provinceKey);
                    $month = (int) $dueDate->month;
                    $row['m'.$month] += (float) $virtualRecord['amount_due'];
                    $row['total'] += (float) $virtualRecord['amount_due'];
                    $receivable->put($provinceKey, $row);
                }

                continue;
            }

            foreach ($records as $record) {
                if ($range->includes($record->due_month, $year)) {
                    $row = $receivable->get($provinceKey);
                    $month = (int) $record->due_month->month;
                    $amount = (float) ($record->amount_due ?? 0);
                    $row['m'.$month] += $amount;
                    $row['total'] += $amount;
                    $receivable->put($provinceKey, $row);
                }

                $paid = (float) ($record->rental_payment ?? 0);
                if ($paid <= 0) {
                    continue;
                }

                $collectionDate = $record->or_date ?: $record->due_month;
                if (! $range->includes($collectionDate, $year)) {
                    continue;
                }

                $row = $collection->get($provinceKey);
                $month = (int) $collectionDate->month;
                $row['m'.$month] += $paid;
                $row['total'] += $paid;
                $collection->put($provinceKey, $row);
            }
        }

        $collection = $collection->values();
        $receivable = $receivable->values();
        $collectionTotals = $this->monthlyTotals($collection);
        $receivableTotals = $this->monthlyTotals($receivable);

        $refundRates = $collection->map(function (array $collectionRow) use ($receivable) {
            $receivableRow = $receivable->firstWhere('key', $collectionRow['key']);
            $row = [
                'key' => $collectionRow['key'],
                'province' => $collectionRow['province'],
            ];

            for ($month = 1; $month <= 12; $month++) {
                $due = (float) ($receivableRow['m'.$month] ?? 0);
                $paid = (float) ($collectionRow['m'.$month] ?? 0);
                $row['m'.$month] = $due > 0 ? round(($paid / $due) * 100, 2) : 0.0;
            }

            $dueTotal = (float) ($receivableRow['total'] ?? 0);
            $row['total'] = $dueTotal > 0
                ? round(((float) $collectionRow['total'] / $dueTotal) * 100, 2)
                : 0.0;

            return $row;
        })->values();

        $monthlyRates = ['province' => 'MONTHLY'];
        for ($month = 1; $month <= 12; $month++) {
            $due = (float) $receivableTotals['m'.$month];
            $monthlyRates['m'.$month] = $due > 0
                ? round(((float) $collectionTotals['m'.$month] / $due) * 100, 2)
                : 0.0;
        }
        $monthlyRates['total'] = $receivableTotals['total'] > 0
            ? round(($collectionTotals['total'] / $receivableTotals['total']) * 100, 2)
            : 0.0;

        $quarterlyRates = [];
        foreach ([1 => [1, 2, 3], 2 => [4, 5, 6], 3 => [7, 8, 9], 4 => [10, 11, 12]] as $quarter => $quarterMonths) {
            $paid = collect($quarterMonths)->sum(fn (int $month) => (float) $collectionTotals['m'.$month]);
            $due = collect($quarterMonths)->sum(fn (int $month) => (float) $receivableTotals['m'.$month]);
            $quarterlyRates['q'.$quarter] = $due > 0 ? round(($paid / $due) * 100, 2) : 0.0;
        }
        $quarterlyRates['total'] = $monthlyRates['total'];

        return view('monitoring.refund-rate', compact(
            'range',
            'year',
            'years',
            'sector',
            'sectorOptions',
            'months',
            'collection',
            'receivable',
            'collectionTotals',
            'receivableTotals',
            'refundRates',
            'monthlyRates',
            'quarterlyRates'
        ));
    }

    private function emptyMonthlyProvinceRows(array $provinceDefinitions): Collection
    {
        return collect($provinceDefinitions)->mapWithKeys(function (array $definition, string $key) {
            $row = [
                'key' => $key,
                'province' => $definition['label'],
                'total' => 0.0,
            ];

            for ($month = 1; $month <= 12; $month++) {
                $row['m'.$month] = 0.0;
            }

            return [$key => $row];
        });
    }

    private function monthlyTotals(Collection $rows): array
    {
        $totals = ['total' => 0.0];

        for ($month = 1; $month <= 12; $month++) {
            $totals['m'.$month] = (float) $rows->sum('m'.$month);
        }

        $totals['total'] = (float) $rows->sum('total');

        return $totals;
    }

    private function virtualOriginalRentalSchedule(Proposal $proposal): Collection
    {
        if (! $proposal->date_funded || (float) $proposal->amount <= 0) {
            return collect();
        }

        $projectCents = (int) round(((float) $proposal->amount) * 100);
        $baseCents = (int) round($projectCents / 36);
        $firstDue = $proposal->date_funded->copy()->addYear()->startOfMonth();

        return collect(range(1, 36))->map(function (int $installment) use ($projectCents, $baseCents, $firstDue) {
            $amountCents = $installment === 36
                ? $projectCents - ($baseCents * 35)
                : $baseCents;

            return [
                'installment_no' => $installment,
                'due_month' => $firstDue->copy()->addMonthsNoOverflow($installment - 1),
                'amount_due' => $amountCents / 100,
            ];
        });
    }

    private function renderModule(string $title, string $shortLabel, string $icon): View
    {
        return view('monitoring.index', compact('title', 'shortLabel', 'icon'));
    }

    private function evaluatorSummary(Builder $visibleProposalIds, int $year, \App\Support\DateRangeFilter $range): Collection
    {
        $base = DB::table('evaluator_proposal as ep')
            ->join('evaluators as e', 'e.id', '=', 'ep.evaluator_id')
            ->whereIn('ep.proposal_id', clone $visibleProposalIds);

        $assignments = (clone $base)
            ->tap(fn ($q) => $range->apply($q, 'ep.created_at', $year))
            ->get([
                'e.id', 'e.first_name', 'e.middle_initial', 'e.last_name', 'e.classification', 'e.deleted_at',
                'ep.created_at', 'ep.evaluation_status',
            ]);

        $completions = (clone $base)
            ->whereNotNull('ep.evaluated_at')
            ->tap(fn ($q) => $range->apply($q, 'ep.evaluated_at', $year))
            ->get([
                'e.id', 'e.first_name', 'e.middle_initial', 'e.last_name', 'e.classification', 'e.deleted_at',
                'ep.evaluated_at',
            ]);

        $rows = collect();

        foreach ($assignments as $assignment) {
            $id = (int) $assignment->id;
            $row = $rows->get($id, $this->emptyEvaluatorRow($assignment));
            $quarter = $this->quarterFromDate($assignment->created_at);
            $row['q'.$quarter]++;
            $row['assigned']++;
            $rows->put($id, $row);
        }

        foreach ($completions as $completion) {
            $id = (int) $completion->id;
            $row = $rows->get($id, $this->emptyEvaluatorRow($completion));
            $row['completed']++;
            $rows->put($id, $row);
        }

        return $rows
            ->map(function (array $row) {
                $row['honorarium'] = $row['classification'] === 'External'
                    ? $row['completed'] * self::EXTERNAL_HONORARIUM
                    : 0;

                return $row;
            })
            ->sortBy([
                ['classification', 'asc'],
                ['last_name', 'asc'],
                ['first_name', 'asc'],
            ])
            ->values();
    }

    private function emptyEvaluatorRow(object $record): array
    {
        $middle = trim((string) ($record->middle_initial ?? ''));
        $middle = $middle !== '' ? rtrim($middle, '.').'.' : '';
        $classification = strcasecmp((string) ($record->classification ?? ''), 'External') === 0
            ? 'External'
            : 'Internal';

        return [
            'id' => (int) $record->id,
            'first_name' => (string) $record->first_name,
            'last_name' => (string) $record->last_name,
            'name' => trim("{$record->first_name} {$middle} {$record->last_name}"),
            'classification' => $classification,
            'archived' => ! empty($record->deleted_at),
            'q1' => 0,
            'q2' => 0,
            'q3' => 0,
            'q4' => 0,
            'assigned' => 0,
            'completed' => 0,
            'honorarium' => 0,
        ];
    }

    private function countByProvinceQuarter(Collection $records, array $provinceDefinitions, callable $quarterResolver): Collection
    {
        $rows = $this->emptyProvinceRows($provinceDefinitions);

        foreach ($records as $record) {
            $provinceKey = $this->provinceKey((string) $record->province, $provinceDefinitions);
            if ($provinceKey === null) {
                continue;
            }

            $quarter = (int) $quarterResolver($record);
            if ($quarter < 1 || $quarter > 4) {
                continue;
            }

            $row = $rows->get($provinceKey);
            $row['q'.$quarter]++;
            $row['total']++;
            $rows->put($provinceKey, $row);
        }

        return $rows->values();
    }

    private function amountByProvinceQuarter(Collection $records, array $provinceDefinitions, callable $quarterResolver): Collection
    {
        $rows = $this->emptyProvinceRows($provinceDefinitions, true);

        foreach ($records as $record) {
            $provinceKey = $this->provinceKey((string) $record->province, $provinceDefinitions);
            if ($provinceKey === null) {
                continue;
            }

            $quarter = (int) $quarterResolver($record);
            if ($quarter < 1 || $quarter > 4) {
                continue;
            }

            $amount = (float) $record->amount;
            $row = $rows->get($provinceKey);
            $row['q'.$quarter] += $amount;
            $row['total'] += $amount;
            $rows->put($provinceKey, $row);
        }

        return $rows->values();
    }

    private function emptyProvinceRows(array $provinceDefinitions, bool $money = false): Collection
    {
        return collect($provinceDefinitions)->mapWithKeys(function (array $definition, string $key) use ($money) {
            $zero = $money ? 0.0 : 0;

            return [$key => [
                'key' => $key,
                'province' => $definition['label'],
                'q1' => $zero,
                'q2' => $zero,
                'q3' => $zero,
                'q4' => $zero,
                'total' => $zero,
            ]];
        });
    }

    private function provinceDefinitions(): array
    {
        return Province::query()
            ->ordered()
            ->get(['name'])
            ->mapWithKeys(fn (Province $province) => [
                $province->name => [
                    'label' => $province->name,
                    'aliases' => [$province->name],
                ],
            ])
            ->all();
    }

    private function provinceKey(string $province, array $provinceDefinitions): ?string
    {
        foreach ($provinceDefinitions as $key => $definition) {
            foreach ($definition['aliases'] as $alias) {
                if (strcasecmp(trim($province), $alias) === 0) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function proposalQuarter(mixed $date, mixed $storedQuarter): int
    {
        if ($date) {
            return $this->quarterFromDate($date);
        }

        return (int) $storedQuarter;
    }

    private function quarterFromDate(mixed $date): int
    {
        return (int) ceil(Carbon::parse($date)->month / 3);
    }

    private function rtecYears($user, Builder $visibleProposalIds): array
    {
        $years = collect([now()->year]);

        $proposalYears = Proposal::query()->visibleTo($user);
        $years->push(...(clone $proposalYears)->whereNotNull('date_submitted')->pluck('date_submitted')->map(fn ($date) => Carbon::parse($date)->year)->all());
        $years->push(...(clone $proposalYears)->whereNotNull('date_approved')->pluck('date_approved')->map(fn ($date) => Carbon::parse($date)->year)->all());

        $evaluationYears = DB::table('evaluator_proposal')->whereIn('proposal_id', clone $visibleProposalIds);
        $years->push(...(clone $evaluationYears)->whereNotNull('created_at')->pluck('created_at')->map(fn ($date) => Carbon::parse($date)->year)->all());
        $years->push(...(clone $evaluationYears)->whereNotNull('evaluated_at')->pluck('evaluated_at')->map(fn ($date) => Carbon::parse($date)->year)->all());

        return $years
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    private function yearExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(strftime('%Y', {$column}) AS INTEGER)"
            : "YEAR({$column})";
    }
}
