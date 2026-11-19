@extends('layouts.app')
@section('title', 'Liquidation Monitoring')
@section('page-title', 'Liquidation Monitoring')

@section('top-actions')
    <a class="btn" href="{{ route('monitoring.liquidation.equipment-provided') }}">Equipment Provided</a>
    @include('monitoring.partials.export-buttons', ['routeName' => 'monitoring.liquidation.export'])
@endsection

@section('content')
@php
    $money = fn ($amount) => '₱'.number_format((float) $amount, 2);
    $percent = function ($rate) {
        $rate = (float) $rate;
        return abs($rate - round($rate)) < 0.005 ? number_format($rate, 0).'%' : number_format($rate, 2).'%';
    };
    $canManage = auth()->user()->hasPermission('monitoring.liquidation.manage');
    $canOpenDetailed = auth()->user()->hasPermission('liquidation.view');
    $hasActions = $canManage || $canOpenDetailed;

    // Table 1 overall summary. Keep this tied to the currently filtered project set
    // so the totals always agree with what is visible in the monitoring table.
    $yearSummariesByYear = $yearSummaries->keyBy('year');
    $table1Released = (float) $yearSummaries->sum('released');
    $table1Liquidated = (float) $yearSummaries->sum('amount_liquidated');
    $table1NotYetLiquidated = max(0, $table1Released - $table1Liquidated);
    $table1PercentageLiquidated = $table1Released > 0
        ? ($table1Liquidated / $table1Released) * 100
        : 0;
    $table1Years = $yearSummaries->pluck('year')->filter()->map(fn ($year) => (int) $year)->unique()->sort()->values();
    $table1YearLabel = $table1Years->isEmpty()
        ? ''
        : ($table1Years->count() === 1
            ? (string) $table1Years->first()
            : $table1Years->first().'–'.$table1Years->last());

    // Compact operational overview for the space beside the financial totals.
    $table1ProjectCount = (int) $yearSummaries->sum('released_projects');
    $table1FullyLiquidatedCount = (int) $yearSummaries->sum('fully_liquidated_projects');
    $table1NoReportCount = (int) $yearSummaries->sum('no_report_projects');
    $table1PartialCount = max(0, $table1ProjectCount - $table1NoReportCount - $table1FullyLiquidatedCount);
    $table1ReportedUnexpended = (float) $yearSummaries->sum('reported_unexpended');
    $table1DocumentedRefund = (float) $yearSummaries->sum('refunded');
@endphp

<div class="liquidation-monitor-page" data-async-pagination>
    <div class="page-intro liquidation-monitor-intro">
        <h1>Liquidation Monitoring</h1>
    </div>

    <form method="GET" action="{{ route('monitoring.liquidation') }}" class="liquidation-monitor-filters">
        <label class="liquidation-search-filter">
            <span>Search</span>
            <div class="liquidation-search-field">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $search }}" placeholder="Firm or proposal...">
            </div>
        </label>
        <label>
            <span>Funding year</span>
            <select name="funding_year" id="liquidationFundingYear">
                <option value="" @selected(!$showAllFundingYears && $fundingYear === null && !$hasDateRange)>Last 5 years ({{ $defaultYearFrom }}–{{ $defaultYearTo }})</option>
                <option value="all" @selected($showAllFundingYears && !$hasDateRange)>All funding years</option>
                @foreach($fundingYears as $yearOption)
                    <option value="{{ $yearOption }}" @selected(!$hasDateRange && $fundingYear === $yearOption)>{{ $yearOption }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Date funded from</span>
            <input type="date" name="date_from" id="liquidationDateFrom" value="{{ $dateFrom }}">
        </label>
        <label>
            <span>Date funded to</span>
            <input type="date" name="date_to" id="liquidationDateTo" value="{{ $dateTo }}">
        </label>
        <label>
            <span>Province</span>
            <select name="province">
                <option value="">All provinces</option>
                @foreach($provinceOptions as $provinceOption)
                    <option value="{{ $provinceOption }}" @selected($province === $provinceOption)>{{ $provinceOption }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Sector</span>
            <select name="sector">
                <option value="">All sectors</option>
                @foreach($sectorOptions as $sectorOption)
                    <option value="{{ $sectorOption }}" @selected($sector === $sectorOption)>{{ $sectorOption }}</option>
                @endforeach
            </select>
        </label>
        <div class="liquidation-filter-actions">
            <button class="btn primary" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
            <a class="btn" href="{{ route('monitoring.liquidation') }}">Reset</a>
        </div>
    </form>


    <section class="panel liquidation-sheet-panel">
        <div class="liquidation-table-wrap">
            <table class="liquidation-sheet-table">
                <thead>
                    <tr>
                        <th class="no-col">No.</th>
                        <th class="firm-col">Firm{{ $province !== '' ? ' ('.strtoupper($province).' Province)' : ' / Beneficiary' }}</th>
                        <th>Amount Req.</th>
                        <th>Amount Liquidated</th>
                        <th>Reported Unexpended Bal.</th>
                        <th>Remarks<br><small>Unexpended balance</small></th>
                        <th>Unliquidated Amount</th>
                        <th class="remarks-col">Remarks</th>
                        @if($hasActions)<th class="action-col"></th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse($groupedRows as $year => $yearRows)
                        <tr class="liquidation-year-band">
                            <th colspan="{{ $hasActions ? 9 : 8 }}">
                                <span>{{ $year }}</span>
                                @php($yearProjectCount = $yearProjectCounts->get($year, $yearRows->count()))
                                <small>{{ number_format($yearProjectCount) }} {{ $yearProjectCount === 1 ? 'proposal' : 'proposals' }}</small>
                            </th>
                        </tr>
                        @foreach($yearRows as $index => $row)
                            <tr class="notification-target" id="liquidation-proposal-{{ $row['proposal_id'] }}">
                                <td class="no-col">{{ $index + 1 }}</td>
                                <td class="firm-col">
                                    <b>{{ $row['beneficiary'] }}</b>
                                    <small>{{ $row['project_title'] }} · {{ $row['province'] }} · {{ $row['sector'] }}</small>
                                </td>
                                <td class="money-cell">{{ $money($row['released']) }}</td>
                                <td class="money-cell">{{ $row['amount_liquidated'] > 0 ? $money($row['amount_liquidated']) : '—' }}</td>
                                <td class="money-cell">{{ $row['reported_unexpended'] > 0 ? $money($row['reported_unexpended']) : '—' }}</td>
                                <td>
                                    @if($row['unexpended_remarks'] === 'Refunded')
                                        <span class="liquidation-state refunded">Refunded</span>
                                    @elseif($row['unexpended_remarks'] === 'Partially refunded')
                                        <span class="liquidation-state partial-refund">Partially refunded</span>
                                    @elseif($row['unexpended_remarks'] === 'No proof of refund')
                                        <span class="liquidation-state no-proof">No proof of refund</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="money-cell unliquidated-cell">{{ $row['unliquidated'] > 0.005 ? $money($row['unliquidated']) : '—' }}</td>
                                <td class="remarks-col">
                                    <span class="liquidation-remark-text">{{ $row['remarks'] !== '' ? $row['remarks'] : $row['generated_remarks'] }}</span>
                                </td>
                                @if($hasActions)
                                    <td class="action-col">
                                        <div class="liquidation-row-actions">
                                            @if($canOpenDetailed)
                                                <a class="row-action" href="{{ route('proposals.liquidation.index', $row['proposal_id']) }}">Details →</a>
                                            @endif
                                            @if($canManage)
                                                <button type="button" class="row-action secondary liquidation-edit-trigger" data-dialog="liquidationEdit{{ $row['proposal_id'] }}">Summary</button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                        @php($yearSummary = $yearSummariesByYear->get((int) $year))
                        <tr class="liquidation-year-total">
                            <th colspan="2">{{ $year }} TOTAL</th>
                            <th class="money-cell">{{ $money($yearSummary['released'] ?? $yearRows->sum('released')) }}</th>
                            <th class="money-cell">{{ $money($yearSummary['liquidated'] ?? $yearRows->sum('amount_liquidated')) }}</th>
                            <th class="money-cell">{{ $money($yearSummary['reported_unexpended'] ?? $yearRows->sum('reported_unexpended')) }}</th>
                            <th></th>
                            <th class="money-cell">{{ $money($yearSummary['unliquidated'] ?? $yearRows->sum('unliquidated')) }}</th>
                            <th></th>
                            @if($hasActions)<th></th>@endif
                        </tr>
                        @if($yearPaginators[$year]->hasPages())
                            <tr class="monitoring-year-pagination-row">
                                <td colspan="{{ $hasActions ? 9 : 8 }}">{{ $yearPaginators[$year]->links() }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ $hasActions ? 9 : 8 }}" class="liquidation-empty">No funded proposals match the selected filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($yearSummaries->isNotEmpty())
            <div class="liquidation-table1-footer">
                <section class="liquidation-overview" aria-label="Liquidation overview">
                    <div class="liquidation-overview-head">
                        <div>
                            <span class="eyebrow">OVERVIEW</span>
                            <h3>Liquidation status</h3>
                        </div>
                        @if($hasDateRange)
                            <small>{{ $dateFrom !== '' ? \Illuminate\Support\Carbon::parse($dateFrom)->format('M d, Y') : 'Beginning' }} – {{ $dateTo !== '' ? \Illuminate\Support\Carbon::parse($dateTo)->format('M d, Y') : 'Present' }}</small>
                        @elseif($table1YearLabel !== '')
                            <small>Funding year {{ $table1YearLabel }}</small>
                        @endif
                    </div>
                    <div class="liquidation-overview-grid">
                        <div class="liquidation-overview-stat"><span>Proposals covered</span><b>{{ number_format($table1ProjectCount) }}</b></div>
                        <div class="liquidation-overview-stat success"><span>Fully liquidated</span><b>{{ number_format($table1FullyLiquidatedCount) }}</b></div>
                        <div class="liquidation-overview-stat warning"><span>Partial / ongoing</span><b>{{ number_format($table1PartialCount) }}</b></div>
                        <div class="liquidation-overview-stat danger"><span>No fund report</span><b>{{ number_format($table1NoReportCount) }}</b></div>
                        <div class="liquidation-overview-stat money"><span>Reported unexpended</span><b>{{ $money($table1ReportedUnexpended) }}</b></div>
                        <div class="liquidation-overview-stat money refund"><span>Documented refund</span><b>{{ $money($table1DocumentedRefund) }}</b></div>
                    </div>
                </section>

                <div class="liquidation-table1-overall" aria-label="Table 1 overall liquidation summary">
                    <div class="liquidation-table1-summary-row">
                        <span>Total Amount Released{{ $table1YearLabel !== '' ? ' '.$table1YearLabel : '' }}</span>
                        <b>{{ $money($table1Released) }}</b>
                    </div>
                    <div class="liquidation-table1-summary-row">
                        <span>Amount not yet liquidated</span>
                        <b>{{ $money($table1NotYetLiquidated) }}</b>
                    </div>
                    <div class="liquidation-table1-summary-row">
                        <span>Amount Liquidated</span>
                        <b>{{ $money($table1Liquidated) }}</b>
                    </div>
                    <div class="liquidation-table1-summary-row percentage">
                        <span>Percentage Liquidated</span>
                        <b>{{ $percent($table1PercentageLiquidated) }}</b>
                    </div>
                </div>
            </div>
        @endif
    @if($canManage)
        @foreach($groupedRows as $yearRows)
            @foreach($yearRows as $row)
                <dialog id="liquidationEdit{{ $row['proposal_id'] }}" class="modal liquidation-edit-modal">
                    <form method="POST" action="{{ route('monitoring.liquidation.update', $row['proposal_id']) }}" class="modal-card liquidation-edit-card liquidation-calculator">
                        @csrf
                        @method('PATCH')
                        <div class="liquidation-edit-head">
                            <div>
                                <span class="eyebrow">LIQUIDATION RECORD</span>
                                <h2>{{ $row['beneficiary'] }}</h2>
                                <p>{{ $row['project_title'] }} · {{ $row['funding_year'] }} · {{ $row['province'] }}</p>
                            </div>
                            <button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
                        </div>

                        <div class="liquidation-edit-grid">
                            <label>Amount released
                                <input type="number" name="amount_released" min="0" step="0.01" value="{{ number_format($row['released'], 2, '.', '') }}">
                                <small>Defaults to the Proposal Amount.</small>
                            </label>
                            <label>Amount liquidated
                                <input type="number" name="amount_liquidated" min="0" step="0.01" value="{{ number_format($row['amount_liquidated'], 2, '.', '') }}" required @readonly($row['has_equipment_database'] || $row['has_detailed_ledger'])>
                                @if($row['has_equipment_database'])<small>Calculated from equipment with a Date Liquidated.</small>@elseif($row['has_detailed_ledger'])<small>Calculated from the legacy proposal liquidation ledger.</small>@endif
                            </label>
                            <label>Reported unexpended balance
                                <input type="number" name="reported_unexpended_balance" min="0" step="0.01" value="{{ number_format($row['reported_unexpended'], 2, '.', '') }}" required>
                            </label>
                            <label>Refunded unexpended amount
                                <input type="number" name="refunded_unexpended_amount" min="0" step="0.01" value="{{ number_format($row['refunded_unexpended'], 2, '.', '') }}" required @readonly($row['has_detailed_ledger'])>
                                @if($row['has_detailed_ledger'])<small>Calculated from legacy refund ledger entries.</small>@endif
                            </label>
                            <label>Liquidation report status
                                <select name="liquidation_report_status" required>
                                    @foreach(['No Report','Partial','Complete'] as $statusOption)
                                        <option value="{{ $statusOption }}" @selected($row['report_status'] === $statusOption)>{{ $statusOption }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <div class="liquidation-derived-card">
                                <span>Calculated unliquidated amount</span>
                                <b data-unliquidated-preview>{{ $money($row['unliquidated']) }}</b>
                                <small>Released − liquidated − documented refund</small>
                            </div>
                        </div>

                        <div class="liquidation-check-grid">
                            <label class="check"><input type="checkbox" name="proof_of_refund_received" value="1" @checked($row['proof_received'])> Proof of refund received</label>
                            <label class="check"><input type="checkbox" name="attachments_complete" value="1" @checked($row['attachments_complete'])> Liquidation attachments complete</label>
                            <label class="check"><input type="checkbox" name="status_report_submitted" value="1" @checked($row['status_report_submitted'])> Status report submitted</label>
                        </div>

                        <label>Remarks
                            <textarea name="remarks" rows="4" maxlength="5000" placeholder="Optional monitoring remarks...">{{ $row['remarks'] }}</textarea>
                        </label>

                        <div class="liquidation-edit-actions">
                            <button type="button" class="btn" data-close-dialog>Cancel</button>
                            <button type="submit" class="btn primary"><i class="fa-solid fa-floppy-disk"></i> Save liquidation record</button>
                        </div>
                    </form>
                </dialog>
            @endforeach
        @endforeach
    @endif

    </section>

    <section class="panel liquidation-summary-panel">
        <div class="liquidation-table-wrap">
            <table class="liquidation-summary-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="particulars-col">Particulars</th>
                        @foreach($yearSummaries as $summary)
                            <th colspan="2" class="summary-year">{{ $summary['year'] }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach($yearSummaries as $summary)
                            <th>Amount</th><th># of proposals</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr><th>Released</th>@foreach($yearSummaries as $s)<td>{{ $money($s['released']) }}</td><td>{{ $s['released_projects'] }}</td>@endforeach</tr>
                    <tr><th>Liquidated</th>@foreach($yearSummaries as $s)<td>{{ $money($s['liquidated']) }}</td><td>{{ $s['liquidated_projects'] }}</td>@endforeach</tr>
                    <tr class="summary-rate green"><th>Percentage Liquidated</th>@foreach($yearSummaries as $s)<td colspan="2">{{ $percent($s['percentage_liquidated']) }}</td>@endforeach</tr>

                    <tr class="summary-spacer"><td colspan="{{ 1 + ($yearSummaries->count() * 2) }}"></td></tr>
                    <tr><th>Released</th>@foreach($yearSummaries as $s)<td>{{ $money($s['released']) }}</td><td>{{ $s['released_projects'] }}</td>@endforeach</tr>
                    <tr><th>Unliquidated amount</th>@foreach($yearSummaries as $s)<td>{{ $money($s['unliquidated']) }}</td><td>{{ $s['unliquidated_projects'] }}</td>@endforeach</tr>
                    <tr class="summary-rate lime"><th>Percentage Unliquidated</th>@foreach($yearSummaries as $s)<td colspan="2">{{ $percent($s['percentage_unliquidated']) }}</td>@endforeach</tr>

                    <tr class="summary-spacer"><td colspan="{{ 1 + ($yearSummaries->count() * 2) }}"></td></tr>
                    <tr><th>Released</th>@foreach($yearSummaries as $s)<td>{{ $money($s['released']) }}</td><td>{{ $s['released_projects'] }}</td>@endforeach</tr>
                    <tr><th>Fully Liquidated</th>@foreach($yearSummaries as $s)<td>{{ $money($s['fully_liquidated']) }}</td><td>{{ $s['fully_liquidated_projects'] }}</td>@endforeach</tr>
                    <tr class="summary-rate cyan"><th>Percentage Fully Liquidated</th>@foreach($yearSummaries as $s)<td colspan="2">{{ $percent($s['percentage_fully_liquidated']) }}</td>@endforeach</tr>

                    <tr class="summary-spacer"><td colspan="{{ 1 + ($yearSummaries->count() * 2) }}"></td></tr>
                    <tr><th>Released</th>@foreach($yearSummaries as $s)<td>{{ $money($s['released']) }}</td><td>{{ $s['released_projects'] }}</td>@endforeach</tr>
                    <tr><th>No report on fund utilization</th>@foreach($yearSummaries as $s)<td>{{ $money($s['no_report_amount']) }}</td><td>{{ $s['no_report_projects'] }}</td>@endforeach</tr>
                    <tr class="summary-rate orange"><th>Percentage of unutilized funds</th>@foreach($yearSummaries as $s)<td colspan="2">{{ $percent($s['percentage_unutilized']) }}</td>@endforeach</tr>

                    <tr class="summary-spacer"><td colspan="{{ 1 + ($yearSummaries->count() * 2) }}"></td></tr>
                    <tr><th>Reported Unexpended Balance</th>@foreach($yearSummaries as $s)<td>{{ $money($s['reported_unexpended']) }}</td><td>{{ $s['reported_unexpended_projects'] }}</td>@endforeach</tr>
                    <tr><th>Refunded</th>@foreach($yearSummaries as $s)<td>{{ $money($s['refunded']) }}</td><td>{{ $s['refunded_projects'] }}</td>@endforeach</tr>
                    <tr class="summary-rate coral"><th>Percentage refund of reported Unexpended balance</th>@foreach($yearSummaries as $s)<td colspan="2">{{ $percent($s['percentage_refund']) }}</td>@endforeach</tr>

                    <tr class="summary-spacer"><td colspan="{{ 1 + ($yearSummaries->count() * 2) }}"></td></tr>
                    <tr><th>Reported Unexpended Balance</th>@foreach($yearSummaries as $s)<td>{{ $money($s['reported_unexpended']) }}</td><td>{{ $s['reported_unexpended_projects'] }}</td>@endforeach</tr>
                    <tr><th>No proof of refund</th>@foreach($yearSummaries as $s)<td>{{ $money($s['no_proof']) }}</td><td>{{ $s['no_proof_projects'] }}</td>@endforeach</tr>
                    <tr class="summary-rate red"><th>Percentage non-refund of reported Unexpended balance</th>@foreach($yearSummaries as $s)<td colspan="2">{{ $percent($s['percentage_non_refund']) }}</td>@endforeach</tr>
                </tbody>
            </table>
        </div>
        @if($yearSummaries->isEmpty())<p class="liquidation-empty">No summary data is available for the selected filters.</p>@endif
    </section>

    <section class="liquidation-notes">
        <b>Monitoring definitions</b>
        <ol>
            <li><strong>Percentage Liquidated</strong> covers liquidation reported against released funds, including documented refund of reported unexpended balances.</li>
            <li><strong>Percentage Unliquidated</strong> is the remaining released amount not yet covered by liquidation or documented refund.</li>
            <li><strong>Percentage of unutilized funds</strong> covers proposals with released funds but no liquidation/fund-utilization report yet.</li>
            <li><strong>Percentage Fully Liquidated</strong> covers proposals whose liquidation report is complete, attachments are complete, and no unliquidated balance remains.</li>
            <li><strong>Percentage refund of reported Unexpended balance</strong> is documented refunded amount divided by reported unexpended balance.</li>
            <li><strong>Percentage non-refund of reported Unexpended balance</strong> is the reported unexpended balance still without documented refund.</li>
        </ol>
    </section>

</div>
@endsection

@push('scripts')
<script>
document.addEventListener('change', event => {
    const fundingYear = document.getElementById('liquidationFundingYear');
    const dateFrom = document.getElementById('liquidationDateFrom');
    const dateTo = document.getElementById('liquidationDateTo');

    if (event.target === dateFrom || event.target === dateTo) {
        if ((dateFrom?.value || '') !== '' || (dateTo?.value || '') !== '') {
            if (fundingYear) fundingYear.value = '';
        }
    } else if (event.target === fundingYear && (fundingYear?.value || '') !== '') {
        if (dateFrom) dateFrom.value = '';
        if (dateTo) dateTo.value = '';
    }
});
</script>
@endpush

@if($canManage)
@push('scripts')
<script>
(() => {
    const peso = value => new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(Math.max(0, value || 0));
    const updatePreview = form => {
        const released = form.querySelector('[name="amount_released"]');
        const liquidated = form.querySelector('[name="amount_liquidated"]');
        const reported = form.querySelector('[name="reported_unexpended_balance"]');
        const refunded = form.querySelector('[name="refunded_unexpended_amount"]');
        const proof = form.querySelector('[name="proof_of_refund_received"]');
        const output = form.querySelector('[data-unliquidated-preview]');
        const releasedAmount = Number(released?.value || 0);
        const liquidatedAmount = Number(liquidated?.value || 0);
        const reportedAmount = Number(reported?.value || 0);
        const refundedAmount = proof?.checked ? Math.min(Number(refunded?.value || 0), reportedAmount) : 0;
        if (output) output.textContent = peso(releasedAmount - liquidatedAmount - refundedAmount);
    };

    document.addEventListener('click', event => {
        const editButton = event.target.closest('.liquidation-edit-trigger');
        if (editButton) return document.getElementById(editButton.dataset.dialog)?.showModal();

        const closeButton = event.target.closest('[data-close-dialog]');
        if (closeButton) return closeButton.closest('dialog')?.close();

        if (event.target.matches('.liquidation-edit-modal')) event.target.close();
    });

    ['input', 'change'].forEach(eventName => document.addEventListener(eventName, event => {
        const form = event.target.closest('.liquidation-calculator');
        if (form) updatePreview(form);
    }));
})();
</script>
@endpush
@endif
