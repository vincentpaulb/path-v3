@extends('layouts.app')
@section('title', 'Import Batch Data')
@section('page-title', 'Import Batch Data')

@section('top-back')
    <a class="back topbar-back" href="{{ route('proposals.bulk-import.index') }}">← Back to bulk upload</a>
@endsection

@section('content')
@php
    $dateLabel = function ($value) {
        if (!$value) return '—';
        try { return \Illuminate\Support\Carbon::parse($value)->format('M d, Y'); }
        catch (\Throwable $e) { return (string) $value; }
    };
@endphp

<div class="bulk-import-batch-page">
    <div class="page-intro"><div><h1>{{ $batch->batch_ref }}</h1></div></div>

    <section class="panel bulk-import-batch-summary">
        <div class="bulk-import-batch-meta">
            <div><span>File</span><b>{{ $batch->original_filename }}</b></div>
            <div><span>Imported by</span><b>{{ $batch->creator?->full_name ?? 'System' }}</b></div>
            <div><span>Date imported</span><b>{{ $batch->created_at?->format('M d, Y g:i A') }}</b></div>
            <div><span>Batch status</span><b>{{ $batch->status }}</b></div>
        </div>
        <div class="bulk-import-batch-kpis">
            <div><span>Total rows</span><b>{{ number_format($batch->total_rows) }}</b></div>
            <div class="valid"><span>Imported</span><b>{{ number_format($batch->imported_rows) }}</b></div>
            <div><span>Skipped</span><b>{{ number_format($batch->skipped_rows) }}</b></div>
            <div class="error"><span>Failed</span><b>{{ number_format($batch->failed_rows) }}</b></div>
        </div>
    </section>

    <form class="filter-bar bulk-import-batch-filter" method="GET">
        <input name="search" value="{{ $search }}" placeholder="Search proposal, firm, province, program sector, industry sector or status…">
        
        @include('partials.date-range-filter', ['dateBasis' => 'Import date', 'overridesYear' => false])
        <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        @if($search !== '')
            <a class="btn ghost" href="{{ route('proposals.bulk-import.history-data', $batch) }}">Reset</a>
        @endif
    </form>

    <section class="panel bulk-import-batch-data-panel">
        <div class="panel-head bulk-import-batch-data-head">
            <div><h2>Imported proposals</h2></div>
            <span>{{ number_format($items->total()) }} record{{ $items->total() === 1 ? '' : 's' }}</span>
        </div>

        @if($items->count() && $items->contains(fn ($item) => !$item['snapshot_available']))
            <div class="bulk-import-history-note">
                <i class="fa-solid fa-circle-info"></i>
                <span>This batch was imported before import snapshots were enabled. PATH is showing the current saved proposal data linked to this batch. New imports preserve the exact imported snapshot.</span>
            </div>
        @endif

        <div class="table-wrap bulk-import-batch-table-wrap">
            <table class="bulk-import-batch-table">
                <thead>
                    <tr>
                        <th>Source Row</th>
                        <th>Proposal / Proponent</th>
                        <th>Province / Sector</th>
                        <th>Historical Dates</th>
                        <th>Statuses</th>
                        <th>Evaluators</th>
                        <th>Required Documents</th>
                        <th class="num">Amount</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php
                            $docs = $item['document_summary'];
                            $evaluators = collect($item['evaluators']);
                            $documentStatuses = collect($item['document_statuses']);
                        @endphp
                        <tr>
                            <td class="bulk-import-source-row">{{ $item['source_row'] ?: '—' }}</td>
                            <td>
                                <b class="bulk-import-batch-project">{{ $item['project_title'] }}</b>
                                @if(!empty($item['address']))<small><i class="fa-solid fa-location-dot"></i> {{ $item['address'] }}</small>@endif
                                @if(!empty($item['contact_person']) || !empty($item['contact_phone']) || !empty($item['contact_email']))
                                    <small><i class="fa-solid fa-address-card"></i> {{ collect([$item['contact_person'] ?? null, $item['contact_phone'] ?? null, $item['contact_email'] ?? null])->filter()->implode(' · ') }}</small>
                                @endif
                                <small>{{ $item['proponent'] }}</small>
                                @if($item['archived'])<span class="bulk-import-archived">Archived</span>@endif
                                @if($item['proposal_link'])
                                    <small><a href="{{ $item['proposal_link'] }}" target="_blank" rel="noopener">Proposal link ↗</a></small>
                                @endif
                            </td>
                            <td>
                                <b>{{ $item['province'] }}</b>
                                <small>{{ $item['sector'] }}</small>
                                @if(!empty($item['industry_sector_code']) || !empty($item['industry_sector_name']))
                                    <small>{{ $item['industry_sector_code'] }}{{ !empty($item['industry_sector_code']) && !empty($item['industry_sector_name']) ? ' — ' : '' }}{{ $item['industry_sector_name'] }}</small>
                                @endif
                            </td>
                            <td class="bulk-import-date-stack">
                                <span><small>Submitted</small>{{ $dateLabel($item['date_submitted']) }}</span>
                                <span><small>Approved</small>{{ $dateLabel($item['date_approved']) }}</span>
                                <span><small>Funded</small>{{ $dateLabel($item['date_funded']) }}</span>
                            </td>
                            <td class="bulk-import-status-stack">
                                <span><small>Proposal</small>@if($item['status'])<x-status :value="$item['status']" />@else — @endif</span>
                                <span><small>Funding</small>{{ $item['funding_status'] ?: '—' }}</span>
                                <span><small>MOA</small>{{ $item['moa_status'] ?: '—' }}</span>
                                <span><small>Check</small>{{ $item['check_status'] ?: '—' }}</span>
                            </td>
                            <td>
                                @if($evaluators->isNotEmpty())
                                    <b>{{ $evaluators->count() }} evaluator{{ $evaluators->count() === 1 ? '' : 's' }}</b>
                                    <small>{{ $evaluators->pluck('name')->take(2)->implode(', ') }}{{ $evaluators->count() > 2 ? ' +' . ($evaluators->count() - 2) : '' }}</small>
                                    <details class="bulk-import-batch-details">
                                        <summary>View evaluators</summary>
                                        <div class="bulk-import-batch-detail-list">
                                            @foreach($evaluators as $evaluator)
                                                <div>
                                                    <b>{{ $evaluator['name'] ?? 'Evaluator' }}</b>
                                                    <span>{{ $evaluator['classification'] ?? 'Evaluator' }} · {{ $evaluator['evaluation_status'] ?? '—' }}</span>
                                                    <span>Assigned: {{ $dateLabel($evaluator['assigned_at'] ?? null) }} · Evaluated: {{ $dateLabel($evaluator['evaluated_at'] ?? null) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @else
                                    <span class="muted">None</span>
                                @endif
                            </td>
                            <td>
                                <div class="bulk-import-doc-counts">
                                    <span class="submitted">{{ $docs['submitted'] ?? 0 }} submitted</span>
                                    @if(($docs['lacking'] ?? 0) > 0)<span class="lacking">{{ $docs['lacking'] }} lacking</span>@endif
                                    @if(($docs['na'] ?? 0) > 0)<span>{{ $docs['na'] }} N/A</span>@endif
                                    @if(($docs['pending'] ?? 0) > 0)<span class="pending">{{ $docs['pending'] }} pending</span>@endif
                                </div>
                                @if($documentStatuses->isNotEmpty())
                                    <details class="bulk-import-batch-details">
                                        <summary>View checklist</summary>
                                        <div class="bulk-import-document-detail-list">
                                            @foreach($documentStatuses as $document)
                                                <div>
                                                    <span>{{ $document['name'] ?? 'Required document' }}</span>
                                                    <b class="doc-status-{{ \Illuminate\Support\Str::slug($document['status'] ?? 'pending') }}">{{ $document['status'] ?? 'Pending' }}</b>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </td>
                            <td class="num"><b>₱{{ number_format((float) $item['amount'], 2) }}</b></td>
                            <td>
                                @if($item['proposal_id'] && !$item['archived'] && (auth()->user()->hasPermission('proposals.view') || auth()->user()->hasPermission('funded.view')))
                                    <a class="row-action" href="{{ route('proposals.show', $item['proposal_id']) }}">Open →</a>
                                @elseif($item['archived'])
                                    <span class="muted">Archived</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty">No imported proposal data matches this batch{{ $search !== '' ? ' and search' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($items->hasPages())
            <div class="pagination">{{ $items->links() }}</div>
        @endif
    </section>
</div>
@endsection
