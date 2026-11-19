@extends('layouts.app')
@section('title', 'Bulk Upload Rental Records')
@section('page-title', 'Bulk Upload Rental Records')

@section('top-back')
    @php
        $rentalBulkBack = auth()->user()->hasPermission('monitoring.rental.view')
            ? route('monitoring.rental')
            : (auth()->user()->hasPermission('proposals.view') ? route('proposals.index') : route('dashboard'));
    @endphp
    <a class="back topbar-back" href="{{ $rentalBulkBack }}">← Back</a>
@endsection

@section('content')
<div class="bulk-import-page rental-bulk-import-page">
    <div class="page-intro"><div><h1>Bulk upload rental records</h1></div></div>

    <section class="panel bulk-import-upload-panel">
        <div class="bulk-import-upload-grid">
            <div>
                <span class="bulk-import-step">01</span>
                <h2>Upload Rental Monitoring data</h2>
                <p>Use the same <b>Rental Monitoring Excel format you already use</b>. Upload one XLSX/CSV when encoding a single proposal, or upload one ZIP containing many proposal spreadsheets for a bulk migration. PATH validates everything before saving anything.</p>

                <div class="bulk-import-template-action">
                    <a class="btn" href="{{ route('rental-records.bulk-import.template') }}"><i class="fa-solid fa-file-excel"></i> Download Current Rental Format</a>
                    <small>Same columns/layout as your existing Rental Monitoring workbook</small>
                </div>

                <form method="POST" action="{{ route('rental-records.bulk-import.preview') }}" enctype="multipart/form-data" class="bulk-import-upload-form">
                    @csrf
                    <label class="bulk-import-file-field">
                        <span><i class="fa-solid fa-file-import"></i></span>
                        <div>
                            <b>Select ZIP, XLSX, or CSV</b>
                            <small>Single XLSX/CSV or ZIP batch · maximum upload 100 MB</small>
                        </div>
                        <input type="file" name="import_file" accept=".zip,.xlsx,.csv,.txt,application/zip,application/x-zip-compressed,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
                    </label>
                    @error('import_file')<span class="field-error">{{ $message }}</span>@enderror
                    @error('import_zip')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="btn primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Validate & Preview</button>
                </form>
            </div>

            <div class="bulk-import-rules rental-bulk-rules">
                <div><b>1</b><span>Firm / proposal per spreadsheet</span></div>
                <div><b>1 / 200</b><span>Single file or files per ZIP</span></div>
                <div><b>0</b><span>Records changed before confirmation</span></div>
                <p>Saved rental values are protected. Bulk import only fills blank PATH fields or accepts values that already match; conflicting saved values are never silently overwritten.</p>
            </div>
        </div>

        <div class="bulk-import-guidance rental-bulk-guidance">
            <div><i class="fa-solid fa-fingerprint"></i><span><b>Proposal matching</b> — your current format is matched using the exact <code>FIRM</code> + <code>AMOUNT INVESTED BY SETUP</code>. If that combination is not unique, include the Proposal ID in the filename, for example <code>Proposal-125-Rental-Records.xlsx</code>.</span></div>
            <div><i class="fa-solid fa-table-list"></i><span><b>Rental rows</b> — PATH reads <code>MONTH</code>, <code>AMOUNT DUE</code>, <code>RENTAL PAYMENT</code>, <code>OR NUMBER</code>, <code>OR DATE (MM.DD.YY)</code>, <code>AMOUNT IN OR</code>, and <code>REMARKS</code> directly from your existing layout.</span></div>
            <div><i class="fa-solid fa-file-excel"></i><span><b>Single or batch upload</b> — upload one existing XLSX/CSV directly, or gather many one-proposal workbooks into a ZIP. The downloadable template follows your current Rental Monitoring layout.</span></div>
            <div><i class="fa-solid fa-shield-halved"></i><span><b>Legacy schedule sections</b> — PATH reads historical <code>Revised start…</code>, <code>Restructured…</code>, <code>extension of phase…</code>, <code>Proposal Extension</code>, and <code>OTP</code> sections. Consecutive extension blocks are treated as separate schedule adjustments. TOTAL/REFUND RATE rows remain summary-only.</span></div>
        </div>
    </section>

    @if($preview)
        @php
            $files = collect($preview['files'] ?? []);
        @endphp
        <section class="panel bulk-import-preview-panel rental-bulk-preview-panel">
            <div class="panel-head bulk-import-preview-head">
                <div>
                    <span class="bulk-import-step">02</span>
                    <h2>Validation preview</h2>
                    <small>{{ $preview['original_filename'] ?? 'Uploaded file' }} · {{ ($preview['source_type'] ?? 'zip') === 'zip' ? 'ZIP batch' : 'Single spreadsheet' }}</small>
                </div>
                <div class="bulk-import-preview-actions">
                    <form method="POST" action="{{ route('rental-records.bulk-import.discard') }}">
                        @csrf
                        <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                        <button class="btn ghost" type="submit">Discard preview</button>
                    </form>
                    @if(($preview['importable_files'] ?? 0) > 0 && ((($preview['ready_rows'] ?? 0) + ($preview['ready_special_actions'] ?? 0)) > 0))
                        <form method="POST" action="{{ route('rental-records.bulk-import.commit') }}"
                              data-confirm-title="Import rental records from {{ $preview['importable_files'] }} proposal file(s)?"
                              data-confirm-message="PATH will import only validated rows. Existing conflicting saved values will not be overwritten."
                              data-confirm-button="Import valid rental data">
                            @csrf
                            <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                            <button class="btn primary" type="submit"><i class="fa-solid fa-file-import"></i> Import Valid Rental Data</button>
                        </form>
                    @endif
                </div>
            </div>

            <div class="bulk-import-kpis rental-bulk-kpis">
                <div><span>Files checked</span><b>{{ $preview['total_files'] ?? 0 }}</b></div>
                <div class="valid"><span>Matched proposals</span><b>{{ $preview['matched_files'] ?? 0 }}</b></div>
                <div class="valid"><span>Importable files</span><b>{{ $preview['importable_files'] ?? 0 }}</b></div>
                <div class="warning"><span>Warning files</span><b>{{ $preview['warning_files'] ?? 0 }}</b></div>
                <div><span>Rental rows</span><b>{{ $preview['total_rows'] ?? 0 }}</b></div>
                <div class="valid"><span>Ready rows</span><b>{{ $preview['ready_rows'] ?? 0 }}</b></div>
                <div class="valid"><span>Special sections</span><b>{{ $preview['ready_special_actions'] ?? 0 }}</b></div>
                <div><span>Already / blank</span><b>{{ $preview['skipped_rows'] ?? 0 }}</b></div>
                <div class="error"><span>Error rows</span><b>{{ $preview['error_rows'] ?? 0 }}</b></div>
            </div>

            <div class="rental-bulk-file-list">
                @forelse($files as $file)
                    @php
                        $fileHasError = count($file['errors'] ?? []) > 0;
                        $rowCollection = collect($file['rows'] ?? []);
                        $readyRows = $rowCollection->where('status', 'ready')->count();
                        $skippedRowCollection = $rowCollection->where('status', 'skip')->values();
                        $errorRowCollection = $rowCollection->where('is_valid', false)->values();
                        $skipRows = $skippedRowCollection->count();
                        $badRows = $errorRowCollection->count();
                        $specialSections = $file['special_sections'] ?? [];
                        $specialAdjustments = collect($specialSections['schedule_adjustments'] ?? []);
                        $otpSection = $specialSections['otp'] ?? null;
                        $specialReady = (int) ($file['ready_special_actions'] ?? 0);
                        $specialDetected = $specialAdjustments->count() + ($otpSection ? 1 : 0);
                        $hasWarnings = count($file['warnings'] ?? []) > 0 || $rowCollection->contains(fn($r) => count($r['warnings'] ?? []) > 0);
                    @endphp
                    <article class="rental-bulk-file {{ $fileHasError ? 'has-error' : ($hasWarnings ? 'has-warning' : 'is-ready') }}">
                        <div class="rental-bulk-file-head">
                            <div class="rental-bulk-file-icon"><i class="fa-solid fa-file-excel"></i></div>
                            <div class="rental-bulk-file-title">
                                <b>{{ $file['filename'] }}</b>
                                @if($file['proposal_id'])
                                    <span>Proposal #{{ $file['proposal_id'] }} · {{ $file['project_title'] }} · {{ $file['proponent'] }}</span>
                                    <small>{{ $file['province'] }} · {{ $file['sector'] }} · matched via {{ str_replace('_',' ', $file['identity_source'] ?? 'sheet') }}</small>
                                @else
                                    <span>Proposal could not be matched</span>
                                @endif
                            </div>
                            <div class="rental-bulk-file-status">
                                @if($fileHasError)
                                    <span class="bulk-import-result error"><i class="fa-solid fa-circle-xmark"></i> File error</span>
                                @elseif($readyRows > 0 || $specialReady > 0)
                                    <span class="bulk-import-result valid"><i class="fa-solid fa-circle-check"></i> {{ $readyRows + $specialReady }} ready</span>
                                @else
                                    <span class="bulk-import-result warning"><i class="fa-solid fa-minus-circle"></i> No new data</span>
                                @endif
                            </div>
                        </div>

                        <div class="rental-bulk-file-counts">
                            <span><b>{{ $file['total_rows'] ?? 0 }}</b> rows</span>
                            <span class="ready"><b>{{ $readyRows }}</b> ready</span>
                            <span><b>{{ $skipRows }}</b> skipped</span>
                            <span class="error"><b>{{ $badRows }}</b> errors</span>
                            @if($specialDetected > 0)<span class="ready"><b>{{ $specialDetected }}</b> special</span>@endif
                        </div>

                        @foreach($file['errors'] ?? [] as $message)
                            <div class="rental-bulk-message error"><i class="fa-solid fa-circle-xmark"></i><span>{{ $message }}</span></div>
                        @endforeach
                        @foreach($file['warnings'] ?? [] as $message)
                            <div class="rental-bulk-message warning"><i class="fa-solid fa-triangle-exclamation"></i><span>{{ $message }}</span></div>
                        @endforeach

                        @if($skipRows > 0 || $badRows > 0)
                            <div class="rental-bulk-result-breakdown">
                                @if($skipRows > 0)
                                    <details class="rental-bulk-result-group skipped">
                                        <summary><span><i class="fa-solid fa-forward-step"></i> Skipped rows</span><b>{{ $skipRows }}</b></summary>
                                        <div class="rental-bulk-result-list">
                                            @foreach($skippedRowCollection as $row)
                                                <div>
                                                    <span><b>Sheet row {{ $row['source_row'] ?? '—' }}</b> · Installment #{{ $row['installment_no'] ?? '—' }} · {{ $row['month'] ?? '—' }}</span>
                                                    <small>{{ $row['skip_reason'] ?? 'No new importable rental data.' }}</small>
                                                    @foreach($row['warnings'] ?? [] as $msg)<small class="warning">{{ $msg }}</small>@endforeach
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                                @if($badRows > 0)
                                    <details class="rental-bulk-result-group failed" open>
                                        <summary><span><i class="fa-solid fa-circle-xmark"></i> Error / conflict rows</span><b>{{ $badRows }}</b></summary>
                                        <div class="rental-bulk-result-list">
                                            @foreach($errorRowCollection as $row)
                                                <div>
                                                    <span><b>Sheet row {{ $row['source_row'] ?? '—' }}</b> · Installment #{{ $row['installment_no'] ?? '—' }} · {{ $row['month'] ?? '—' }}</span>
                                                    @foreach($row['errors'] ?? [] as $msg)<small class="error">{{ $msg }}</small>@endforeach
                                                    @foreach($row['warnings'] ?? [] as $msg)<small class="warning">{{ $msg }}</small>@endforeach
                                                </div>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>
                        @endif

                        @if($specialDetected > 0)
                            <div class="rental-bulk-special-sections">
                                <b><i class="fa-solid fa-arrows-rotate"></i> Special Rental Monitoring sections</b>
                                @foreach($specialAdjustments as $adjustment)
                                    <div class="rental-bulk-special-item {{ $adjustment['status'] ?? 'ready' }}">
                                        <span>
                                            <strong>{{ $adjustment['adjustment_type'] ?? 'Schedule adjustment' }}</strong>
                                            {{ $adjustment['effective_month'] ?? '—' }} → {{ $adjustment['resume_month'] ?? '—' }}
                                            @if(!empty($adjustment['label']))<small>{{ $adjustment['label'] }}</small>@endif
                                        </span>
                                        <em>{{ ucfirst($adjustment['status'] ?? 'ready') }}</em>
                                    </div>
                                @endforeach
                                @if($otpSection)
                                    <div class="rental-bulk-special-item {{ $otpSection['status'] ?? 'ready' }}">
                                        <span>
                                            <strong>OTP · Option to Purchase</strong>
                                            ₱{{ number_format((float)($otpSection['expected_amount'] ?? 0), 2) }} · 0.5% of Proposal Amount
                                            <small>Sheet row {{ $otpSection['source_row'] ?? '—' }}</small>
                                        </span>
                                        <em>{{ ucfirst($otpSection['status'] ?? 'ready') }}</em>
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if($rowCollection->isNotEmpty())
                            <details class="rental-bulk-row-details" {{ $fileHasError ? 'open' : '' }}>
                                <summary>View rental rows</summary>
                                <div class="table-wrap rental-bulk-row-table-wrap">
                                    <table class="rental-bulk-row-table">
                                        <thead><tr><th>Sheet Row</th><th>Installment</th><th>Month</th><th>Rental Payment</th><th>OR No.</th><th>OR Date</th><th>Amount in OR</th><th>Remarks</th><th>Validation</th></tr></thead>
                                        <tbody>
                                            @foreach($rowCollection as $row)
                                                <tr class="{{ !$row['is_valid'] ? 'has-error' : (($row['status'] ?? '') === 'skip' ? 'is-skip' : (count($row['warnings'] ?? []) ? 'has-warning' : 'is-valid')) }}">
                                                    <td>{{ $row['source_row'] ?? '—' }}</td>
                                                    <td><b>#{{ $row['installment_no'] ?? '—' }}</b></td>
                                                    <td>{{ $row['month'] ?? '—' }}</td>
                                                    <td>{{ isset($row['updates']['rental_payment']) ? '₱'.number_format((float)$row['updates']['rental_payment'],2) : '—' }}</td>
                                                    <td>{{ $row['updates']['or_number'] ?? '—' }}</td>
                                                    <td>{{ $row['updates']['or_date'] ?? '—' }}</td>
                                                    <td>{{ isset($row['updates']['amount_in_or']) ? '₱'.number_format((float)$row['updates']['amount_in_or'],2) : '—' }}</td>
                                                    <td>{{ $row['updates']['remarks'] ?? '—' }}</td>
                                                    <td>
                                                        @if(!$row['is_valid'])
                                                            <span class="bulk-import-result error">Error</span>
                                                        @elseif(($row['status'] ?? '') === 'skip')
                                                            <span class="bulk-import-result warning">Skip</span>
                                                        @elseif(count($row['warnings'] ?? []))
                                                            <span class="bulk-import-result warning">Ready + warning</span>
                                                        @else
                                                            <span class="bulk-import-result valid">Ready</span>
                                                        @endif
                                                        @if(count($row['errors'] ?? []) || count($row['warnings'] ?? []) || !empty($row['skip_reason']))
                                                            <details class="bulk-import-row-details">
                                                                <summary>Details</summary>
                                                                @foreach($row['errors'] ?? [] as $msg)<p class="error">{{ $msg }}</p>@endforeach
                                                                @foreach($row['warnings'] ?? [] as $msg)<p class="warning">{{ $msg }}</p>@endforeach
                                                                @if(!empty($row['skip_reason']))<p>{{ $row['skip_reason'] }}</p>@endif
                                                            </details>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        @endif
                    </article>
                @empty
                    <div class="empty">No supported rental-record files were found.</div>
                @endforelse
            </div>
        </section>
    @endif

    <section class="panel bulk-import-history-panel">
        <div class="panel-head"><div><h2>Rental import history</h2></div></div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Batch</th><th>Source File</th><th>Imported by</th><th>Files</th><th>Rows</th><th>Date</th><th></th></tr></thead>
                <tbody>
                    @forelse($history as $batch)
                        <tr>
                            <td><b>{{ $batch->batch_ref }}</b><small>{{ $batch->status }}</small></td>
                            <td>{{ $batch->original_filename }}</td>
                            <td>{{ $batch->creator?->full_name ?? 'System' }}</td>
                            <td>
                                <div class="bulk-import-history-result">
                                    <span class="imported">{{ $batch->imported_files }} imported</span>
                                    @if($batch->failed_files)<span class="failed">{{ $batch->failed_files }} failed</span>@endif
                                    <span>{{ $batch->total_files }} total</span>
                                </div>
                            </td>
                            <td>
                                @php
                                    $batchMeta = is_array($batch->metadata) ? $batch->metadata : [];
                                @endphp
                                <div class="bulk-import-history-result">
                                    <span class="imported">{{ $batch->imported_rows }} payment rows</span>
                                    @if(($batchMeta['special_imported'] ?? 0) > 0)<span class="imported">{{ $batchMeta['special_imported'] }} special</span>@endif
                                    @if($batch->skipped_rows)<span>{{ $batch->skipped_rows }} skipped</span>@endif
                                    @if($batch->failed_rows)<span class="failed">{{ $batch->failed_rows }} failed</span>@endif
                                </div>
                            </td>
                            <td>{{ $batch->created_at?->format('M d, Y g:i A') }}</td>
                            <td class="bulk-import-history-action"><a class="row-action" href="{{ route('rental-records.bulk-import.history-data', $batch) }}">View Data →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty">No rental imports have been completed yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
