@extends('layouts.app')
@section('title', 'Funded Proposals')
@section('page-title', 'Funded Proposals')

@section('content')
<div class="page-intro">
    <div><h1>Funding & release tracker</h1></div>
    <div class="summary-pill"><span>Total shown</span><b>&#8369;{{ number_format($proposals->sum('amount'), 2) }}</b></div>
</div>

<form class="filter-bar">
    <input name="search" value="{{ request('search') }}" placeholder="Search funded proposal&hellip;">
    <select name="sector">
        <option value="">All sectors</option>
        @foreach($sectors as $value)<option {{ request('sector') === $value ? 'selected' : '' }}>{{ $value }}</option>@endforeach
    </select>
    <select name="province">
        <option value="">All provinces</option>
        @foreach($provinces as $value)<option {{ request('province') === $value ? 'selected' : '' }}>{{ $value }}</option>@endforeach
    </select>
    <select name="funding_status">
        <option value="">All processing states</option>
        @foreach($fundingStatuses as $value)<option {{ request('funding_status') === $value ? 'selected' : '' }}>{{ $value }}</option>@endforeach
    </select>
    
        @include('partials.date-range-filter', ['dateBasis' => 'Funding date', 'overridesYear' => false])
        <button class="btn">Filter</button>
</form>

<section class="panel table-panel">
    <div class="table-wrap">
        <table class="proposal-list-table funded-project-table">
            <thead><tr><th>Proponent / Proposal</th><th>Sector</th><th>Province</th><th>Date funded</th><th>Funding status</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            @forelse($proposals as $proposal)
                <tr>
                    <td class="proposal-project-cell"><a class="title-link" href="{{ route('proposals.show', ['proposal' => $proposal, 'from' => 'funded']) }}">{{ $proposal->proponent }}</a><small title="{{ $proposal->project_title }}">{{ \Illuminate\Support\Str::words($proposal->project_title, 12, '…') }}</small></td>
                    <td><span class="sector-badge {{ strtolower($proposal->sector) }}">{{ $proposal->sector }}</span></td>
                    <td>{{ $proposal->province }}</td>
                    <td>{{ $proposal->date_funded?->format('M d, Y') ?: '—' }}</td>
                    <td>
                        @if(auth()->user()->hasPermission('funded.update_status'))
                            <form method="POST" action="{{ route('funded.status', $proposal) }}" class="inline-select">
                                @csrf
                                @method('PATCH')
                                <select name="funding_status" onchange="this.form.submit()">
                                    <option value="">Not set</option>
                                    @foreach($fundingStatuses as $value)<option {{ $proposal->funding_status === $value ? 'selected' : '' }}>{{ $value }}</option>@endforeach
                                </select>
                            </form>
                        @else
                            <x-status :value="$proposal->funding_status" />
                        @endif
                    </td>
                    <td class="num">&#8369;{{ number_format($proposal->amount, 2) }}</td>
                    <td><a class="row-action" href="{{ route('proposals.show', ['proposal' => $proposal, 'from' => 'funded']) }}">Open &rarr;</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">No funded proposals found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $proposals->links() }}
</section>
@endsection
