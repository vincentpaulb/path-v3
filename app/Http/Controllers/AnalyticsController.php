<?php

namespace App\Http\Controllers;

use App\Enums\Sector;
use App\Models\Proposal;
use App\Models\Province;
use Illuminate\Http\Request;

class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $range = new \App\Support\DateRangeFilter($request);
        $user = $request->user();
        $year = (int) $request->integer('year', now()->year);
        $quarter = $request->integer('quarter');

        // visibleTo() is the single source of truth for analytics visibility.
        // Evaluators therefore receive analytics computed only from proposals
        // explicitly assigned to their evaluator-directory account.
        $query = Proposal::query()
            ->visibleTo($user)
            ->tap(fn ($q) => $range->apply($q, 'date_submitted', $year));

        if ($quarter && ! $range->active()) {
            $query->where('quarter_submitted', $quarter);
        }

        // Fetch every province/sector metric in one grouped query. The loops
        // below only fill missing combinations with zero for the chart/table.
        $metrics = (clone $query)
            ->selectRaw(<<<'SQL'
                province,
                sector,
                COUNT(*) AS proposal_count,
                COUNT(CASE WHEN (date_funded IS NOT NULL OR status = 'Funded') THEN 1 END) AS funded_count,
                COALESCE(SUM(CASE WHEN (date_funded IS NOT NULL OR status = 'Funded') THEN amount ELSE 0 END), 0) AS funded_amount
            SQL)
            ->groupBy('province', 'sector')
            ->get()
            ->keyBy(fn ($row) => $row->province.'|'.$row->sector);

        $rows = [];
        $provinceTotals = [];
        $sectorFunded = [];

        foreach (Province::allValues() as $province) {
            foreach (Sector::values() as $sector) {
                $metric = $metrics->get($province.'|'.$sector);
                $count = (int) ($metric?->proposal_count ?? 0);
                $fundedCount = (int) ($metric?->funded_count ?? 0);
                $fundedAmount = (float) ($metric?->funded_amount ?? 0);

                $rows[] = [
                    'province' => $province,
                    'sector' => $sector,
                    'count' => $count,
                    'funded_count' => $fundedCount,
                    'funded_amount' => $fundedAmount,
                ];

                $provinceTotals[$province] = ($provinceTotals[$province] ?? 0) + $count;
                $sectorFunded[$sector] = ($sectorFunded[$sector] ?? 0) + $fundedAmount;
            }
        }

        $provinceLabels = array_keys($provinceTotals);
        $provinceCounts = array_values($provinceTotals);
        $sectorLabels = array_keys($sectorFunded);
        $sectorFundedAmounts = array_values($sectorFunded);

        // The year filter itself is scoped too, preventing an evaluator from
        // learning that unrelated proposal years exist in the database.
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

        return view('analytics.index', compact(
            'range',
            'year',
            'quarter',
            'rows',
            'years',
            'provinceLabels',
            'provinceCounts',
            'sectorLabels',
            'sectorFundedAmounts'
        ));
    }
}
