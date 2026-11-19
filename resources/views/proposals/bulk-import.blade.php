@extends('layouts.app')
@section('title', 'Bulk Upload Proposals')
@section('page-title', 'Bulk Upload Proposals')

@section('top-back')
    <a class="back topbar-back" href="{{ route('proposals.index') }}">← Back to proposals</a>
@endsection

@section('top-actions')
    <a class="btn" href="{{ route('proposals.bulk-import.template') }}"><i class="fa-solid fa-file-excel"></i> Download Excel Template</a>
    <a class="btn ghost" href="{{ route('proposals.bulk-import.template', ['format' => 'csv']) }}"><i class="fa-solid fa-file-csv"></i> CSV</a>
@endsection

@section('content')
<div class="bulk-import-page">
    <div class="page-intro"><div><h1>Bulk upload proposals</h1></div></div>

    <section class="panel bulk-import-upload-panel">
        <div class="bulk-import-upload-grid">
            <div>
                <span class="bulk-import-step">01</span>
                <h2>Upload & validate</h2>
                <p>One row equals one proposal. PATH accepts <b>.CSV</b> and <b>.XLSX</b> files and validates every row before anything is saved.</p>

                <form method="POST" action="{{ route('proposals.bulk-import.preview') }}" enctype="multipart/form-data" class="bulk-import-upload-form">
                    @csrf
                    <label class="bulk-import-file-field">
                        <span><i class="fa-solid fa-cloud-arrow-up"></i></span>
                        <div>
                            <b>Select proposal import file</b>
                            <small>CSV or XLSX · maximum 10 MB</small>
                        </div>
                        <input type="file" name="import_file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                    </label>
                    @error('import_file')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="btn primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Validate & Preview</button>
                </form>
            </div>

            <div class="bulk-import-rules">
                <div><b>{{ $maxEvaluators }}</b><span>Evaluator slots per proposal</span></div>
                <div><b>{{ $requiredDocumentCount }}</b><span>Current Required Document columns</span></div>
                <div><b>0</b><span>Rows saved before confirmation</span></div>
                <p>Historical provinces may be imported even when they are inactive in Province Management. Exact duplicates are blocked.</p>
            </div>
        </div>

        <div class="bulk-import-guidance">
            <div><i class="fa-solid fa-industry"></i><span><b>Industry Sector</b> — use the Industry Sector code (recommended), exact name, or <code>Code — Name</code>. Historical inactive classifications are preserved.</span></div>
            <div><i class="fa-solid fa-location-dot"></i><span><b>Address & map location</b> — Address is optional. For an exact map pin, enter both Latitude and Longitude in decimal degrees; either coordinate by itself will fail validation.</span></div>
            <div><i class="fa-solid fa-address-card"></i><span><b>Contact details</b> — Contact Person, Contact Phone, and Contact Email are optional. Email values are validated before import.</span></div>
            <div><i class="fa-solid fa-user-check"></i><span><b>Evaluators</b> — enter the exact evaluator full name or email. Multiple evaluators use Evaluator 1 through Evaluator {{ $maxEvaluators }}.</span></div>
            <div><i class="fa-solid fa-list-check"></i><span><b>Required Documents</b> — each current document is its own <code>DOC | ...</code> column. Use Submitted, Lacking, N/A, Pending, or leave blank for Pending.</span></div>
            <div><i class="fa-solid fa-calendar-days"></i><span><b>Historical dates</b> — Date Submitted, Approved, Funded, Assigned, and Evaluated are preserved as entered.</span></div>
        </div>
    </section>

    @if($preview)
        @php
            $validRows = collect($preview['rows'] ?? [])->where('is_valid', true);
            $previewRows = collect($preview['rows'] ?? [])->take(120);
        @endphp
        <section class="panel bulk-import-preview-panel">
            <div class="panel-head bulk-import-preview-head">
                <div>
                    <span class="bulk-import-step">02</span>
                    <h2>Validation preview</h2>
                    <small>{{ $preview['original_filename'] ?? 'Uploaded file' }}</small>
                </div>
                <div class="bulk-import-preview-actions">
                    <form method="POST" action="{{ route('proposals.bulk-import.discard') }}">
                        @csrf
                        <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                        <button class="btn ghost" type="submit">Discard preview</button>
                    </form>
                    @if(($preview['valid_rows'] ?? 0) > 0)
                        <form method="POST" action="{{ route('proposals.bulk-import.commit') }}"
                              data-confirm-title="Import {{ $preview['valid_rows'] }} proposal(s)?"
                              data-confirm-message="Only rows that passed validation will be created. Rows with validation errors will be skipped."
                              data-confirm-button="Import valid rows">
                            @csrf
                            <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                            <button class="btn primary" type="submit"><i class="fa-solid fa-file-import"></i> Import {{ $preview['valid_rows'] }} Valid Row{{ ($preview['valid_rows'] ?? 0) === 1 ? '' : 's' }}</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="bulk-import-kpis">
                <div><span>Total rows</span><b>{{ $preview['total_rows'] ?? 0 }}</b></div>
                <div class="valid"><span>Valid</span><b>{{ $preview['valid_rows'] ?? 0 }}</b></div>
                <div class="warning"><span>Valid with warning</span><b>{{ $preview['warning_rows'] ?? 0 }}</b></div>
                <div class="error"><span>Needs correction</span><b>{{ $preview['error_rows'] ?? 0 }}</b></div>
            </div>

            <div class="table-wrap bulk-import-preview-table-wrap">
                <table class="bulk-import-preview-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Proposal / Proponent</th>
                            <th>Province</th>
                            <th>Status</th>
                            <th>Evaluators</th>
                            <th>Documents</th>
                            <th>Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($previewRows as $row)
                            @php $docs = $row['document_summary'] ?? ['submitted'=>0,'lacking'=>0,'na'=>0,'pending'=>0]; @endphp
                            <tr class="{{ $row['is_valid'] ? (count($row['warnings'] ?? []) ? 'has-warning' : 'is-valid') : 'has-error' }}">
                                <td class="bulk-import-row-no">{{ $row['row_number'] }}</td>
                                <td>
                                    <b>{{ $row['project_title'] ?: '—' }}</b>
                                    <small>{{ $row['proponent'] ?: '—' }}</small>
                                    @if(!empty($row['address']))<small><i class="fa-solid fa-location-dot"></i> {{ $row['address'] }}</small>@endif
                                    @if(!empty($row['project_code']))<small>Project Code: {{ $row['project_code'] }}</small>@endif
                                    @if(!empty($row['contact_person']) || !empty($row['contact_phone']) || !empty($row['contact_email']))
                                        <small><i class="fa-solid fa-address-card"></i> {{ collect([$row['contact_person'] ?? null, $row['contact_phone'] ?? null, $row['contact_email'] ?? null])->filter()->implode(' · ') }}</small>
                                    @endif
                                    @if($row['amount'] !== null)<small>₱{{ number_format((float)$row['amount'], 2) }} · {{ $row['sector'] }}</small>@endif
                                    @if(!empty($row['industry_sector_code']) || !empty($row['industry_sector_name']))<small><i class="fa-solid fa-industry"></i> {{ $row['industry_sector_code'] }}{{ !empty($row['industry_sector_code']) && !empty($row['industry_sector_name']) ? ' — ' : '' }}{{ $row['industry_sector_name'] }}</small>@endif
                                </td>
                                <td>{{ $row['province'] ?: '—' }}</td>
                                <td><x-status :value="$row['status'] ?: 'Unknown'" /></td>
                                <td>
                                    @if(count($row['evaluators'] ?? []))
                                        <b>{{ count($row['evaluators']) }} assigned</b>
                                        <small>{{ collect($row['evaluators'])->pluck('name')->implode(', ') }}</small>
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
                                </td>
                                <td class="bulk-import-validation-cell">
                                    @if(!$row['is_valid'])
                                        <span class="bulk-import-result error"><i class="fa-solid fa-circle-xmark"></i> Error</span>
                                    @elseif(count($row['warnings'] ?? []))
                                        <span class="bulk-import-result warning"><i class="fa-solid fa-triangle-exclamation"></i> Warning</span>
                                    @else
                                        <span class="bulk-import-result valid"><i class="fa-solid fa-circle-check"></i> Ready</span>
                                    @endif

                                    @if(count($row['errors'] ?? []) || count($row['warnings'] ?? []))
                                        <details class="bulk-import-row-details">
                                            <summary>Show details</summary>
                                            @foreach($row['errors'] ?? [] as $message)<p class="error">{{ $message }}</p>@endforeach
                                            @foreach($row['warnings'] ?? [] as $message)<p class="warning">{{ $message }}</p>@endforeach
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="empty">No proposal rows found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if(($preview['total_rows'] ?? 0) > 120)
                <p class="bulk-import-preview-note">Showing the first 120 rows. All {{ $preview['total_rows'] }} rows were validated and will follow the same import rules.</p>
            @endif
        </section>
    @endif

    <section class="panel bulk-import-history-panel">
        <div class="panel-head"><div><h2>Import history</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Batch</th><th>File</th><th>Imported by</th><th>Result</th><th>Date</th><th></th></tr></thead>
                <tbody>
                    @forelse($history as $batch)
                        <tr>
                            <td><b>{{ $batch->batch_ref }}</b></td>
                            <td>{{ $batch->original_filename }}</td>
                            <td>{{ $batch->creator?->full_name ?? 'System' }}</td>
                            <td>
                                <div class="bulk-import-history-result">
                                    <span class="imported">{{ $batch->imported_rows }} imported</span>
                                    @if($batch->skipped_rows)<span>{{ $batch->skipped_rows }} skipped</span>@endif
                                    @if($batch->failed_rows)<span class="failed">{{ $batch->failed_rows }} failed</span>@endif
                                </div>
                            </td>
                            <td>{{ $batch->created_at?->format('M d, Y g:i A') }}</td>
                            <td class="bulk-import-history-action">
                                <a class="row-action" href="{{ route('proposals.bulk-import.history-data', $batch) }}">View Data →</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">No bulk imports have been completed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
