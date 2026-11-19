<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Support\DateRangeFilter;
use Illuminate\Http\Request;

class AnnualMonitoringController extends Controller
{
    public function export(Request $request)
    {
        // Reuse the dashboard's authorized queries and calculations exactly.
        $data = $this->__invoke($request)->getData();
        $sections = [];
        $sections[] = [
            'title' => 'Regional summary',
            'headers' => ['Coverage', 'Funds released', 'Funds refunded', 'Projects funded', 'Funds to be settled', 'Target average', 'Actual average'],
            'types' => ['text', 'money', 'money', 'integer', 'money', 'percent', 'percent'],
            'rows' => collect($data['summaries'])->map(fn ($row, $name) => [$name, $row['fund'], $row['paid'], $row['count'], $row['balance'], $data['targetAverages'][$name], $row['average']])->values()->all(),
        ];
        $sections[] = [
            'title' => 'Selected funded project status: '.$data['selectedStatus'],
            'headers' => ['Coverage', 'Percent of projects'], 'types' => ['text', 'percent'],
            'rows' => $data['statusShares']->map(fn ($share, $name) => [$name, $share])->values()->all(),
        ];
        $sections[] = [
            'title' => 'Provincial funding and cumulative refund progress',
            'headers' => ['Province', 'Funds released', 'Funds refunded', 'Funds to be settled', 'Projects funded', 'Refunded share'],
            'types' => ['text', 'money', 'money', 'money', 'integer', 'percent'],
            'rows' => $data['provinceRows']->map(fn ($row, $name) => [$name, $row['fund'], $row['paid'], $row['balance'], $row['count'], $row['fund'] > 0 ? $row['paid'] / $row['fund'] * 100 : null])->values()->all(),
        ];
        $visualScope = ($data['region'] === 'cv' ? 'Central Visayas' : ($data['region'] === 'nir' ? 'NIR' : 'All regions')).' / '.($data['province'] ?: 'All provinces');
        $sections[] = [
            'title' => 'Sex disaggregated data — '.$visualScope,
            'headers' => ['Owner / contact person sex', 'Projects funded', 'Funds released', 'Projects with no refunds'],
            'types' => ['text', 'integer', 'money', 'integer'],
            'rows' => $data['sexRows']->map(fn ($row, $sex) => [$sex, $row['count'], $row['fund'], $row['no_refunds']])->values()->all(),
        ];
        $total = $data['statuses']->sum();
        $sections[] = [
            'title' => 'Percent of projects funded per status — '.$visualScope,
            'headers' => ['Funded Project Status', 'Projects', 'Percent'], 'types' => ['text', 'integer', 'percent'],
            'rows' => $data['statuses']->map(fn ($count, $status) => [$status, $count, $count / max(1, $total) * 100])->values()->all(),
        ];
        $sections[] = [
            'title' => 'Projects with no refunds since rental start — '.$visualScope,
            'headers' => ['Province', 'Male', 'Female', 'Prefer not to say', 'Not recorded', 'Total'],
            'types' => ['text', 'integer', 'integer', 'integer', 'integer', 'integer'],
            'rows' => $data['noRefundRows']->map(fn ($counts, $name) => [$name, $counts['Male'], $counts['Female'], $counts['Prefer not to say'], $counts['Not recorded'], $counts->sum()])->values()->all(),
        ];
        $sections[] = [
            'title' => 'Reporting basis', 'headers' => ['Item', 'Definition'],
            'rows' => [
                ['Reporting year', (string) $data['year']],
                ['Date From', $data['range']->from ?: 'Not selected'],
                ['Date To', $data['range']->to ?: 'Not selected'],
                ['Effective cutoff', $data['cutoff']->toDateString()],
                ['Scope', 'Accumulated funded SETUP projects through the cutoff, including earlier funding years. Region/province filters apply to the visual breakdowns.'],
                ['Target average', 'Mean of scheduled dues through cutoff divided by project fund, for projects with started schedules.'],
                ['Actual average', 'Mean of saved refunds through cutoff divided by project fund. Penalties excluded.'],
                ['Funds to be settled', 'Funds less refunds and dated unexpended balances. Returned equipment values are not recorded.'],
                ['Classifications', 'Status and sex use currently saved project information.'],
            ],
        ];
        $path = app(\App\Services\MonitoringSpreadsheetExportService::class)->createXlsx([
            'title' => 'SETUP Annual Monitoring',
            'subtitle' => 'Reporting year '.$data['year'].' | Through '.$data['cutoff']->format('M d, Y'),
            'sections' => $sections,
        ]);
        return response()->download($path, 'annual-monitoring-'.$data['cutoff']->format('Y-m-d').'.xlsx', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function __invoke(Request $request)
    {
        $request->validate(['year' => ['nullable', 'integer', 'between:1900,2100'], 'region' => ['nullable', 'in:all,cv,nir'], 'province' => ['nullable', 'string', 'max:100']]);
        $year = $request->integer('year', now()->year);
        $range = new DateRangeFilter($request);
        $selectedDate = $range->to ?? ($range->active() ? now()->toDateString() : null);
        $cutoff = ($selectedDate ? \Carbon\Carbon::parse($selectedDate)->endOfDay() : \Carbon\Carbon::create($year, 12, 31)->endOfDay())->min(now());
        $base = Proposal::query()->visibleTo($request->user())->where('sector', 'SETUP')->whereNotNull('date_funded')->where('amount', '>', 0);
        $years = (clone $base)->pluck('date_funded')->map(fn ($date) => $date->year)->push($year)->push(now()->year)->unique()->sortDesc();
        // Accumulated rates include older funded projects through the reporting date.
        $targetDate = $cutoff->copy()->startOfDay();
        $targetProjects = (clone $base)
            ->whereDate('date_funded', '<=', $targetDate->toDateString())
            ->whereHas('rentalRecords', fn ($query) => $query->whereDate('due_month', '<=', $targetDate->toDateString()))
            ->withSum(['rentalRecords as accumulated_target_due' => fn ($query) => $query->whereDate('due_month', '<=', $targetDate->toDateString())], 'amount_due')
            ->get(['id', 'province', 'amount']);
        $targetAverages = collect([
            'Central Visayas' => ['Cebu', 'Bohol'],
            'Central Visayas & NIR' => ['Cebu', 'Bohol', 'Negros', 'Negros Oriental', 'Siquijor'],
        ])->map(fn ($provinces) => $targetProjects->whereIn('province', $provinces)
            ->avg(fn ($project) => (float) $project->accumulated_target_due / (float) $project->amount * 100));
        $projects = (clone $base)
            ->whereDate('date_funded', '<=', $cutoff->toDateString())
            ->with(['rentalRecords', 'rentalScheduleAdjustments'])->get();
        $rows = $projects->map(function ($project) use ($cutoff) {
            $paid = $project->rentalRecords->filter(fn ($r) => ($r->or_date ?? $r->due_month)?->lte($cutoff))->sum('rental_payment');
            $credit = $project->rentalScheduleAdjustments->filter(fn ($a) => $a->effective_month->lte($cutoff))->sum('unexpended_amount');
            $firstDue = $project->rentalRecords->min('due_month') ?? $project->date_funded->copy()->addYear()->startOfMonth();
            return ['province' => $project->province, 'region' => in_array($project->province, ['Cebu', 'Bohol']) ? 'cv' : (in_array($project->province, ['Negros', 'Negros Oriental', 'Siquijor']) ? 'nir' : 'other'),
                'fund' => (float) $project->amount, 'paid' => (float) $paid, 'balance' => max(0, (float) $project->amount - $paid - $credit),
                'rate' => $paid / (float) $project->amount * 100, 'sex' => $project->owner_contact_sex ?: 'Not recorded',
                'status' => $project->funded_project_status ?: 'New', 'no_refunds' => $firstDue->lte($cutoff) && $paid <= 0];
        });
        $summarize = fn ($items) => ['count' => $items->count(), 'fund' => $items->sum('fund'), 'paid' => $items->sum('paid'), 'balance' => $items->sum('balance'), 'average' => $items->isEmpty() ? null : $items->avg('rate')];
        $summaries = ['Central Visayas' => $summarize($rows->where('region', 'cv')), 'Central Visayas & NIR' => $summarize($rows->whereIn('region', ['cv', 'nir']))];
        $provinces = $rows->pluck('province')->unique()->sort()->values();
        $provinceRows = $rows->groupBy('province')->map($summarize);
        $region = $request->input('region') ?: 'all';
        $province = $request->input('province') ?: '';
        $visualRows = $rows->filter(fn ($r) => ($region === 'all' || $r['region'] === $region) && ($province === '' || $r['province'] === $province));
        $sexRows = collect(['Male', 'Female', 'Prefer not to say', 'Not recorded'])->mapWithKeys(fn ($sex) => [$sex => $summarize($visualRows->where('sex', $sex)) + ['no_refunds' => $visualRows->where('sex', $sex)->where('no_refunds', true)->count()]]);
        $statuses = $visualRows->countBy('status');
        $statusOptions = collect(\App\Enums\FundedProjectStatus::values());
        $selectedStatus = $request->string('monitoring_status')->toString();
        if (! $statusOptions->contains($selectedStatus)) {
            $selectedStatus = 'Graduated';
        }
        $statusShares = collect(['Central Visayas' => ['cv'], 'Central Visayas & NIR' => ['cv', 'nir']])->map(function ($regions) use ($rows, $selectedStatus) {
            $group = $rows->whereIn('region', $regions);
            return $group->isEmpty() ? null : $group->where('status', $selectedStatus)->count() / $group->count() * 100;
        });
        $noRefundRows = $visualRows->groupBy('province')->map(fn ($items) => $sexRows->map(fn ($unused, $sex) => $items->where('no_refunds', true)->where('sex', $sex)->count()));
        return view('monitoring.annual', compact('year', 'range', 'cutoff', 'years', 'summaries', 'provinces', 'provinceRows', 'region', 'province', 'sexRows', 'statuses', 'statusOptions', 'selectedStatus', 'statusShares', 'noRefundRows', 'targetDate', 'targetAverages'));
    }
}
