@extends('layouts.app')
@section('title', 'Rental Import Batch Data')
@section('page-title', 'Rental Import Batch Data')

@section('top-back')
    <a class="back topbar-back" href="{{ route('rental-records.bulk-import.index') }}">← Back to rental bulk upload</a>
@endsection

@section('content')
<div class="bulk-import-batch-page rental-import-history-page">
    <div class="page-intro"><div><h1>{{ $batch->batch_ref }}</h1></div></div>

    <section class="panel bulk-import-batch-summary">
        <div class="bulk-import-batch-meta">
            <div><span>Source file</span><b>{{ $batch->original_filename }}</b></div>
            <div><span>Imported by</span><b>{{ $batch->creator?->full_name ?? 'System' }}</b></div>
            <div><span>Date imported</span><b>{{ $batch->created_at?->format('M d, Y g:i A') }}</b></div>
            <div><span>Batch status</span><b>{{ $batch->status }}</b></div>
        </div>
        @php
            $batchMeta = is_array($batch->metadata) ? $batch->metadata : [];
        @endphp
        <div class="bulk-import-batch-kpis rental-import-batch-kpis">
            <div><span>Files</span><b>{{ number_format($batch->total_files) }}</b></div>
            <div class="valid"><span>Imported files</span><b>{{ number_format($batch->imported_files) }}</b></div>
            <div class="valid"><span>Payment rows</span><b>{{ number_format($batch->imported_rows) }}</b></div>
            <div class="valid"><span>Special sections</span><b>{{ number_format((int)($batchMeta['special_imported'] ?? 0)) }}</b></div>
            <div class="warning"><span>Skipped rows</span><b>{{ number_format($batch->skipped_rows + (int)($batchMeta['special_skipped'] ?? 0)) }}</b></div>
            <div class="error"><span>Failed / conflicts</span><b>{{ number_format($batch->failed_rows + (int)($batchMeta['special_failed'] ?? 0)) }}</b></div>
        </div>
    </section>

    <form class="filter-bar bulk-import-batch-filter" method="GET">
        <input name="search" value="{{ $search }}" placeholder="Search file, proposal, firm, province, sector or result…">
        
        @include('partials.date-range-filter', ['dateBasis' => 'Import date', 'overridesYear' => false])
        <button class="btn" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
        @if($search !== '')<a class="btn ghost" href="{{ route('rental-records.bulk-import.history-data', $batch) }}">Reset</a>@endif
    </form>

    <section class="panel bulk-import-batch-data-panel">
        <div class="panel-head bulk-import-batch-data-head"><div><h2>Proposal rental files</h2></div><span>{{ number_format($files->total()) }} file{{ $files->total() === 1 ? '' : 's' }}</span></div>

        <div class="table-wrap rental-import-history-table-wrap">
            <table class="rental-import-history-table">
                <colgroup>
                    <col class="rental-import-col-file">
                    <col class="rental-import-col-proposal">
                    <col class="rental-import-col-result">
                    <col class="rental-import-col-rows">
                    <col class="rental-import-col-data">
                    <col class="rental-import-col-action">
                </colgroup>
                <thead><tr><th>Rental File</th><th>Proposal / Proponent</th><th>Result</th><th>Rows</th><th>Data / Issues</th><th></th></tr></thead>
                <tbody>
                    @forelse($files as $file)
                        @php
                            $meta = is_array($file->metadata) ? $file->metadata : [];
                            $rowResults = collect($meta['row_results'] ?? []);
                            $importedResults = $rowResults->where('status', 'Imported')->values();
                            $skippedResults = $rowResults->where('status', 'Skipped')->values();
                            $failedResults = $rowResults->filter(fn($row) => in_array($row['status'] ?? '', ['Failed', 'Conflict'], true))->values();
                            $specialResults = collect($meta['special_results'] ?? []);
                            $importErrors = collect($meta['errors'] ?? []);
                            $importWarnings = collect($meta['warnings'] ?? []);
                        @endphp
                        <tr>
                            <td><b>{{ $file->original_filename }}</b><small>File #{{ $file->id }}</small></td>
                            <td>
                                <b>{{ $file->project_title_snapshot ?: 'Unmatched proposal' }}</b>
                                <small>{{ $file->proponent_snapshot ?: '—' }}</small>
                                @if($file->proposal)<small>{{ $file->proposal->province }} · {{ $file->proposal->sector }} · Proposal #{{ $file->proposal_id }}</small>@endif
                            </td>
                            <td>
                                @if(in_array($file->status, ['Imported','Partial']))
                                    <span class="bulk-import-result {{ $file->status === 'Partial' ? 'warning' : 'valid' }}">{{ $file->status }}</span>
                                @else
                                    <span class="bulk-import-result {{ $file->status === 'Skipped' ? 'warning' : 'error' }}">{{ $file->status }}</span>
                                @endif
                                @if($importErrors->isNotEmpty() || $importWarnings->isNotEmpty())
                                    <details class="bulk-import-row-details">
                                        <summary>Messages</summary>
                                        @foreach($importErrors as $msg)<p class="error">{{ $msg }}</p>@endforeach
                                        @foreach($importWarnings as $msg)<p class="warning">{{ $msg }}</p>@endforeach
                                    </details>
                                @endif
                            </td>
                            <td>
                                <div class="bulk-import-history-result">
                                    <span>{{ $file->total_rows }} total</span>
                                    @if($file->imported_rows)<span class="imported">{{ $file->imported_rows }} imported</span>@endif
                                    @if($file->skipped_rows)<span>{{ $file->skipped_rows }} skipped</span>@endif
                                    @if($file->failed_rows)<span class="failed">{{ $file->failed_rows }} failed</span>@endif
                                </div>
                            </td>
                            <td>
                                @if($specialResults->isNotEmpty())
                                    <details class="bulk-import-batch-details rental-import-row-result-details">
                                        <summary>View {{ $specialResults->count() }} special section{{ $specialResults->count() === 1 ? '' : 's' }}</summary>
                                        <div class="rental-import-row-result-list">
                                            @foreach($specialResults as $special)
                                                <div class="{{ strtolower($special['status'] ?? '') }}">
                                                    @if(($special['kind'] ?? '') === 'otp')
                                                        <span><b>OTP · Option to Purchase</b></span>
                                                        <span>{{ $special['status'] ?? 'Result' }} · {{ $special['message'] ?? '' }}</span>
                                                        @if(isset($special['expected_amount']))<small>PATH OTP: ₱{{ number_format((float)$special['expected_amount'],2) }}</small>@endif
                                                    @else
                                                        <span><b>{{ $special['adjustment_type'] ?? 'Schedule adjustment' }}</b> · {{ $special['effective_month'] ?? '—' }} → {{ $special['resume_month'] ?? '—' }}</span>
                                                        <span>{{ $special['status'] ?? 'Result' }} · {{ $special['message'] ?? '' }}</span>
                                                        @if(!empty($special['label']))<small>{{ $special['label'] }}</small>@endif
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                @if($failedResults->isNotEmpty())
                                    <details class="bulk-import-batch-details rental-import-row-result-details rental-result-failed" open>
                                        <summary><i class="fa-solid fa-circle-xmark"></i> View {{ $failedResults->count() }} failed / conflict row{{ $failedResults->count() === 1 ? '' : 's' }}</summary>
                                        <div class="rental-import-row-result-list">
                                            @foreach($failedResults as $row)
                                                <div class="{{ strtolower($row['status'] ?? 'failed') }}">
                                                    <span><b>Sheet row {{ $row['source_row'] ?? '—' }}</b> · Installment #{{ $row['installment_no'] ?? '—' }} @if(!empty($row['month']))· {{ $row['month'] }}@endif</span>
                                                    <span>{{ $row['status'] ?? 'Failed' }} · {{ $row['message'] ?? 'Import failed.' }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                @if($skippedResults->isNotEmpty())
                                    <details class="bulk-import-batch-details rental-import-row-result-details rental-result-skipped">
                                        <summary><i class="fa-solid fa-forward-step"></i> View {{ $skippedResults->count() }} skipped row{{ $skippedResults->count() === 1 ? '' : 's' }}</summary>
                                        <div class="rental-import-row-result-list">
                                            @foreach($skippedResults as $row)
                                                <div class="skipped">
                                                    <span><b>Sheet row {{ $row['source_row'] ?? '—' }}</b> · Installment #{{ $row['installment_no'] ?? '—' }} @if(!empty($row['month']))· {{ $row['month'] }}@endif</span>
                                                    <span>Skipped · {{ $row['message'] ?? 'No new data.' }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                @if($importedResults->isNotEmpty())
                                    <details class="bulk-import-batch-details rental-import-row-result-details rental-result-imported">
                                        <summary><i class="fa-solid fa-circle-check"></i> View {{ $importedResults->count() }} imported row{{ $importedResults->count() === 1 ? '' : 's' }}</summary>
                                        <div class="rental-import-row-result-list">
                                            @foreach($importedResults as $row)
                                                <div class="imported">
                                                    <span><b>Sheet row {{ $row['source_row'] ?? '—' }}</b> · Installment #{{ $row['installment_no'] ?? '—' }} @if(!empty($row['month']))· {{ $row['month'] }}@endif</span>
                                                    <span>Imported · {{ $row['message'] ?? 'Rental record updated.' }}</span>
                                                    <small>
                                                        @if(isset($row['rental_payment']))Payment: ₱{{ number_format((float)$row['rental_payment'],2) }} · @endif
                                                        OR: {{ $row['or_number'] ?: '—' }} · {{ $row['or_date'] ?: '—' }}
                                                    </small>
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif

                                @if($rowResults->isEmpty() && $specialResults->isEmpty())
                                    <span class="muted">No imported data</span>
                                @endif
                            </td>
                            <td>
                                @if($file->proposal && !$file->proposal->trashed() && auth()->user()->hasPermission('rental_records.view'))
                                    <a class="row-action" href="{{ route('rental-records.index', $file->proposal) }}">Open Rental Records →</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">No rental import files match this batch{{ $search !== '' ? ' and search' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($files->hasPages())<div class="pagination">{{ $files->links() }}</div>@endif
    </section>
</div>
@endsection
