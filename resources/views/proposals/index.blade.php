@extends('layouts.app')
@section('title','Proposals')
@section('page-title','Proposal Pipeline')

@section('top-actions')
    @if(auth()->user()->hasPermission('proposals.bulk_import'))
        <a class="btn" href="{{ route('proposals.bulk-import.index') }}"><i class="fa-solid fa-file-import"></i> Bulk Upload</a>
    @endif
    @if(auth()->user()->hasPermission('proposals.create'))
        <a class="btn primary" href="{{ route('proposals.create') }}">+ New Proposal</a>
    @endif
@endsection

@section('content')
<div class="page-intro"><div><h1>Active proposals</h1></div></div>

<form class="filter-bar proposal-filter-bar">
    <input name="search" value="{{ request('search') }}" placeholder="Search code, proposal, or proponent…">
    <select name="sector" aria-label="Program sector">
        <option value="">All programs</option>
        @foreach($sectors as $value)
            <option value="{{ $value }}" @selected(request('sector') === $value)>{{ $value }}</option>
        @endforeach
    </select>
    <select name="industry_sector_id" aria-label="Industry sector">
        <option value="">All industry sectors</option>
        @foreach($industrySectors as $industrySector)
            <option value="{{ $industrySector->id }}" @selected((string) request('industry_sector_id') === (string) $industrySector->id)>
                {{ $industrySector->code }} — {{ $industrySector->name }}{{ $industrySector->is_active ? '' : ' (Inactive)' }}
            </option>
        @endforeach
    </select>
    <select name="province" aria-label="Province">
        <option value="">All provinces</option>
        @foreach($provinces as $value)
            <option value="{{ $value }}" @selected(request('province') === $value)>{{ $value }}</option>
        @endforeach
    </select>
    <select name="document_status" aria-label="Document status">
        <option value="">Document status</option>
        @foreach($documentStatuses as $value)
            <option value="{{ $value }}" @selected(request('document_status') === $value)>{{ $value }}</option>
        @endforeach
    </select>
    <select name="status" aria-label="Proposal status">
        <option value="">Proposal status</option>
        @foreach($statuses as $value)
            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $value }}</option>
        @endforeach
    </select>
    
        @include('partials.date-range-filter', ['dateBasis' => 'Submission date', 'overridesYear' => true])
        <button class="btn" type="submit">Filter</button>
    <a class="btn ghost" href="{{ route('proposals.index') }}">Reset</a>
</form>

<section class="panel table-panel">
    <div class="table-wrap">
        <table class="proposal-list-table active-proposal-table">
            <thead><tr><th>Proposal Code / Proponent</th><th>Program</th><th>Province</th><th>Submitted</th><th>Documents</th><th>Status</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
            @forelse($proposals as $proposal)
                <tr>
                    <td class="proposal-project-cell">
                        <a class="title-link proposal-coded-title" href="{{ route('proposals.show', $proposal) }}">
                            @if($proposal->proposal_code)
                                <span class="proposal-code-pill">{{ $proposal->proposal_code }}</span>
                            @endif
                            <span class="proposal-proponent-name">{{ $proposal->proponent }}</span>
                        </a>
                        <small title="{{ $proposal->project_title }}">{{ \Illuminate\Support\Str::words($proposal->project_title, 12, '…') }}</small>
                    </td>
                    <td><span class="sector-badge {{ strtolower($proposal->sector) }}">{{ $proposal->sector }}</span></td>
                    <td>{{ $proposal->province }}</td>
                    <td>{{ $proposal->date_submitted?->format('M d, Y') }}<small>Q{{ $proposal->quarter_submitted }}</small></td>
                    <td><x-status :value="$proposal->document_status" /></td>
                    <td><x-status :value="$proposal->status" /></td>
                    <td class="num">₱{{ number_format($proposal->amount, 2) }}</td>
                    <td><a class="row-action" href="{{ route('proposals.show', $proposal) }}">Open →</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty">No proposals match the current filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination">{{ $proposals->links() }}</div>
</section>
@endsection
