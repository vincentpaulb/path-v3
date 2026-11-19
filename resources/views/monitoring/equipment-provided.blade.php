@extends('layouts.app')
@section('title', 'Equipment Provided')
@section('page-title', 'Equipment Provided')

@section('top-back')
    <a class="back topbar-back" href="{{ route('monitoring.liquidation') }}">← Back to Liquidation Monitoring</a>
@endsection

@section('top-actions')
    <a class="btn" href="{{ route('monitoring.liquidation.equipment-provided.export', $filters) }}"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Export Excel</a>
@endsection

@section('content')
<div class="liquidation-monitor-page">
    <div class="page-intro liquidation-monitor-intro">
        <h1>Equipment Provided</h1>
    </div>

    @if($errors->hasAny(['year', 'date_from', 'date_to']))
        <div class="field-error" role="alert">
            @foreach(['year', 'date_from', 'date_to'] as $field)
                @foreach($errors->get($field) as $error)<div>{{ $error }}</div>@endforeach
            @endforeach
        </div>
    @endif

    <form method="GET" action="{{ route('monitoring.liquidation.equipment-provided') }}" class="liquidation-monitor-filters equipment-provided-filters">
        <label>
            <span>Acquisition year</span>
            <select name="year" aria-describedby="equipment-date-help">
                <option value="">All years</option>
                @foreach($years as $year)
                    <option value="{{ $year }}" @selected((string) old('year', $filters['year']) === (string) $year)>{{ $year }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <span>Date acquired from</span>
            <input type="date" name="date_from" value="{{ old('date_from', $filters['date_from']) }}">
        </label>
        <label>
            <span>Date acquired to</span>
            <input type="date" name="date_to" value="{{ old('date_to', $filters['date_to']) }}">
        </label>
        <div class="liquidation-filter-actions">
            <button class="btn primary" type="submit"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
            <a class="btn" href="{{ route('monitoring.liquidation.equipment-provided') }}">Reset</a>
        </div>
        <p id="equipment-date-help" class="equipment-date-help">A date range takes priority over the year. Leave all filters blank to include records without an acquisition date.</p>
    </form>

    <section class="panel liquidation-section" aria-label="Provided equipment results">
        <div class="liquidation-section-summary">
            <span><b>{{ number_format($equipment->total()) }}</b> equipment {{ Str::plural('record', $equipment->total()) }}</span>
        </div>
        <div class="liquidation-table-wrap">
            <table class="liquidation-data-table equipment-provided-table">
                <thead>
                    <tr>
                        <th scope="col">Equipment / Technology</th>
                        <th scope="col">Project / Beneficiary</th>
                        <th scope="col">Province</th>
                        <th scope="col">Quantity / Unit</th>
                        <th scope="col" class="number">Purchased Amount</th>
                        <th scope="col">Date Acquired</th>
                        <th scope="col">Date Liquidated</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($equipment as $item)
                        <tr>
                            <td>
                                <b>{{ $item->actual_item_name }}</b>
                                @if($item->property_number)<small>Property no. {{ $item->property_number }}</small>@endif
                            </td>
                            <td>
                                @if($canOpenDetailed)
                                    <a href="{{ route('proposals.liquidation.index', $item->proposal) }}">{{ $item->proposal->project_title }}</a>
                                @else
                                    {{ $item->proposal->project_title }}
                                @endif
                                <small>{{ $item->proposal->proponent }}</small>
                            </td>
                            <td>{{ $item->proposal->province }}</td>
                            <td>{{ number_format((float) $item->quantity, 2) }} {{ $item->unit ?: $item->budgetLine?->unit }}</td>
                            <td class="number">₱{{ number_format((float) $item->actual_cost, 2) }}</td>
                            <td>{{ $item->date_acquired?->format('M d, Y') ?? '—' }}</td>
                            <td>{{ $item->date_liquidated?->format('M d, Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">No provided equipment matches these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($equipment->hasPages())
            <div class="equipment-provided-pagination">{{ $equipment->links() }}</div>
        @endif
    </section>
</div>
@endsection
