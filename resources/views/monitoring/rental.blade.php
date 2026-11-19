@extends('layouts.app')
@section('title', 'Rental Monitoring')
@section('page-title', 'Rental Monitoring')

@section('top-actions')
    @if(auth()->user()->hasPermission('rental_records.bulk_import'))
        <a class="btn" href="{{ route('rental-records.bulk-import.index') }}"><i class="fa-solid fa-file-import"></i> Bulk Upload Rental Records</a>
    @endif
    @include('monitoring.partials.export-buttons', ['routeName' => 'monitoring.rental.export'])
@endsection

@section('content')
@php
    $money = fn ($amount) => '₱'.number_format((float) $amount, 2);
    $rateClass = fn ($rate) => $rate >= 75 ? 'high' : ($rate >= 40 ? 'medium' : 'low');
@endphp

<div class="rental-proposal-page">
    <div class="page-intro rental-proposal-intro">
        <h1>Rental Monitoring</h1>
    </div>

    <form method="GET" action="{{ route('monitoring.rental') }}" class="rental-proposal-filters">
        <label class="rental-proposal-search">
            <span>Search</span>
            <div class="rental-search-field">
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" name="search" value="{{ $search }}" placeholder="Firm or proposal...">
            </div>
        </label>
        <label>
            <span>Funding year</span>
            <select name="funding_year">
                <option value="" @selected(!$showAllFundingYears && $fundingYear === null)>Last 5 years ({{ $defaultYearFrom }}–{{ $defaultYearTo }})</option>
                <option value="all" @selected($showAllFundingYears)>All funding years</option>
                @foreach($fundingYears as $yearOption)
                    <option value="{{ $yearOption }}" @selected($fundingYear === $yearOption)>{{ $yearOption }}</option>
                @endforeach
            </select>
        </label>
        @include('partials.date-range-filter', ['dateBasis' => 'Funding date', 'overridesYear' => true])
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
        <div class="rental-filter-actions">
            
        
        <button class="btn primary" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
            <a class="btn" href="{{ route('monitoring.rental') }}">Reset</a>
        </div>
    </form>


    <div class="rental-sheet-layout">
        <section class="panel rental-sheet-main">
            <div class="rental-sheet-table-wrap">
                <table class="rental-sheet-table">
                    <thead>
                        <tr>
                            <th class="firm-col">{{ $sector !== '' ? $sector.' Proposals' : 'Funded Proposals' }}</th>
                            <th>Fund</th>
                            <th>Refund</th>
                            <th>Pulled Out</th>
                            <th>Balance</th>
                            <th>Refund Rate</th>
                            <th class="open-col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($groupedRows as $year => $yearRows)
                            <tr class="rental-year-band">
                                <th colspan="7">
                                    <span>{{ $year }} {{ $sector !== '' ? strtoupper($sector) : 'FUNDED' }} PROPOSALS</span>
                                    @php($yearProjectCount = $yearProjectCounts->get($year, $yearRows->count()))
                                    <small>{{ number_format($yearProjectCount) }} {{ $yearProjectCount === 1 ? 'proposal' : 'proposals' }}</small>
                                </th>
                            </tr>
                            @foreach($yearRows as $row)
                                <tr>
                                    <td class="rental-sheet-firm">
                                        <b>{{ $row['beneficiary'] }}</b>
                                        <small>{{ $row['project_title'] }} · {{ $row['sector'] }} · {{ $row['province'] }}</small>
                                    </td>
                                    <td class="money-cell">{{ $money($row['fund']) }}</td>
                                    <td class="money-cell refund-cell">{{ $money($row['refund']) }}</td>
                                    <td class="money-cell pulled-cell">{{ $row['pulled_out'] > 0 ? $money($row['pulled_out']) : '—' }}</td>
                                    <td class="money-cell balance-cell">{{ $money($row['balance']) }}</td>
                                    <td class="rate-cell rate-{{ $rateClass($row['refund_rate']) }}">{{ number_format($row['refund_rate'], 2) }}%</td>
                                    <td class="open-col">
                                        @if(auth()->user()->hasPermission('rental_records.view'))
                                            <a class="row-action" href="{{ route('rental-records.index', $row['proposal_id']) }}">Open →</a>
                                        @elseif(auth()->user()->hasPermission('proposals.view'))
                                            <a class="row-action" href="{{ route('proposals.show', $row['proposal_id']) }}">View →</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            @if($yearPaginators[$year]->hasPages())
                                <tr class="monitoring-year-pagination-row">
                                    <td colspan="7">{{ $yearPaginators[$year]->links() }}</td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="7" class="rental-sheet-empty">No funded proposals match the selected filters.</td></tr>
                        @endforelse
                    </tbody>
                    @if($groupedRows->isNotEmpty())
                        <tfoot>
                            <tr>
                                <th>OVERALL TOTAL · {{ number_format($totals['projects']) }} PROPOSALS</th>
                                <th class="money-cell">{{ $money($totals['fund']) }}</th>
                                <th class="money-cell refund-cell">{{ $money($totals['refund']) }}</th>
                                <th class="money-cell pulled-cell">{{ $totals['pulled_out'] > 0 ? $money($totals['pulled_out']) : '—' }}</th>
                                <th class="money-cell balance-cell">{{ $money($totals['balance']) }}</th>
                                <th class="rate-cell rate-{{ $rateClass($totals['refund_rate']) }}">{{ number_format($totals['refund_rate'], 2) }}%</th>
                                <th></th>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </section>

        <aside class="panel rental-year-summary-panel">
            <div class="rental-year-summary-head">
                <h2>Fund Summary by Year</h2>
            </div>
            <div class="rental-year-summary-wrap">
                <table class="rental-year-summary-table">
                    <thead>
                        <tr>
                            <th></th>
                            @foreach($yearSummary as $yearRow)
                                <th>{{ $yearRow['year'] }} FUND</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th>SETUP FUND</th>
                            @foreach($yearSummary as $yearRow)<td>{{ $money($yearRow['fund']) }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>REFUND</th>
                            @foreach($yearSummary as $yearRow)<td class="refund-cell">{{ $money($yearRow['refund']) }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>PULLED OUT</th>
                            @foreach($yearSummary as $yearRow)<td class="pulled-cell">{{ $yearRow['pulled_out'] > 0 ? $money($yearRow['pulled_out']) : '—' }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>BALANCE</th>
                            @foreach($yearSummary as $yearRow)<td class="balance-cell">{{ $money($yearRow['balance']) }}</td>@endforeach
                        </tr>
                        <tr>
                            <th>REFUND RATE</th>
                            @foreach($yearSummary as $yearRow)<td class="rate-cell rate-{{ $rateClass($yearRow['refund_rate']) }}">{{ number_format($yearRow['refund_rate'], 2) }}%</td>@endforeach
                        </tr>
                    </tbody>
                </table>
            </div>

            @if($yearSummary->isEmpty())
                <p class="rental-sheet-empty">No yearly summary is available.</p>
            @endif
        </aside>
    </div>

    <p class="rental-pulled-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Pulled Out is retained to match the existing monitoring sheet. Current PATH records do not yet store a pulled-out amount, so it is shown as “—” until that field/workflow is added.</p>
</div>
@endsection
