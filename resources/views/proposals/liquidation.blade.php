@extends('layouts.app')
@section('title', 'Equipment & Liquidation')
@section('page-title', 'Equipment & Liquidation')

@section('top-back')
    <a class="back topbar-back" href="{{ route('proposals.show', $proposal) }}">← Back to proposal</a>
@endsection

@section('top-actions')
    <a class="btn" href="{{ route('proposals.liquidation.export', $proposal) }}"><i class="fa-solid fa-file-excel"></i> Export Equipment Database</a>
    @if(auth()->user()->hasPermission('monitoring.liquidation.view'))
        <a class="btn" href="{{ route('monitoring.liquidation', ['proposal_id' => $proposal->id, 'funding_year' => 'all']) }}"><i class="fa-solid fa-chart-line"></i> Monitoring</a>
    @endif
@endsection

@section('content')
@php
    $money = fn ($value) => '₱'.number_format((float) $value, 2);
    $approvedTotal = (float) $financials['approved_equipment'];
    $realignedTotal = (float) $financials['realigned_amount'];
    $purchasedTotal = (float) $financials['purchased_amount'];
    $liquidatedTotal = (float) $financials['liquidated'];
    $fundBalance = (float) $financials['fund_balance'];
    $notLiquidated = (float) $financials['purchased_not_liquidated'];
@endphp

<div class="proposal-liquidation-page simple-equipment-liquidation">
    <section class="panel liquidation-project-hero">
        <div>
            <span class="eyebrow">FUNDED PROPOSAL</span>
            <h1>{{ $proposal->project_title }}</h1>
            <p>{{ $proposal->proponent }} · {{ $proposal->province }} · Funded {{ $proposal->date_funded?->format('F d, Y') }}</p>
        </div>
        <div class="liquidation-project-hero-meta">
            <span class="liquidation-workflow-pill status-completed">Funded</span>
            <strong>{{ $money($financials['released']) }}</strong>
            <small>SETUP fund released</small>
        </div>
    </section>

    <section class="liquidation-kpi-grid">
        <article><span>Fund Released</span><b>{{ $money($financials['released']) }}</b><small>Project funding basis</small></article>
        <article><span>Approved Equipment</span><b>{{ $money($approvedTotal) }}</b><small>{{ $proposal->budgetLines->count() }} approved item(s)</small></article>
        <article><span>Realigned Amount</span><b>{{ $money($realignedTotal) }}</b><small>Only entered realignments</small></article>
        <article><span>Purchased Amount</span><b>{{ $money($purchasedTotal) }}</b><small>{{ $proposal->acquisitions->count() }} acquisition record(s)</small></article>
        <article class="primary"><span>Liquidated</span><b>{{ $money($liquidatedTotal) }}</b><small>{{ $money($notLiquidated) }} purchased, not yet liquidated</small></article>
        <article class="{{ $fundBalance < -0.005 ? 'danger-card' : '' }}"><span>Fund Balance</span><b>{{ $money($fundBalance) }}</b><small>Released minus purchased</small></article>
    </section>

    <div class="liquidation-simple-guide">
        <i class="fa-solid fa-circle-info"></i>
        <div><b>Simple rule:</b> encode the approved equipment, then encode what was actually acquired. A purchased amount counts as <b>Liquidated</b> only when its <b>Date Liquidated</b> is filled in.</div>
    </div>

    <section class="panel liquidation-section" id="approved-budget">
        <div class="panel-head">
            <div><span class="eyebrow">APPROVED EQUIPMENT / TECHNOLOGY</span><h2>What the project was originally approved to acquire</h2></div>
            @if($canManage)<button type="button" class="btn" data-open-dialog="addBudgetLine"><i class="fa-solid fa-plus"></i> Add Approved Equipment</button>@endif
        </div>
        <div class="liquidation-section-summary">
            <span><b>{{ $proposal->budgetLines->count() }}</b> approved item(s)</span>
            <span><b>{{ $money($approvedTotal) }}</b> total approved equipment</span>
        </div>
        <div class="liquidation-table-wrap">
            <table class="liquidation-data-table equipment-approved-table">
                <thead><tr><th>Equipment / Technology</th><th>Specifications as Approved</th><th>Qty / Unit</th><th class="number">Amount as Approved</th><th>Acquisition</th><th class="actions">Actions</th></tr></thead>
                <tbody>
                @forelse($proposal->budgetLines as $line)
                    @php
                        $linked = $proposal->acquisitions->where('budget_line_id', $line->id);
                    @endphp
                    <tr>
                        <td><b>{{ $line->item_name }}</b></td>
                        <td><span class="equipment-spec-text">{{ $line->description ?: '—' }}</span></td>
                        <td><b>{{ number_format((float)$line->quantity, 2) }} {{ $line->unit ?: '' }}</b></td>
                        <td class="number"><b>{{ $money($line->approved_amount) }}</b></td>
                        <td>
                            @if($linked->isEmpty())
                                <span class="equipment-state pending">Not yet acquired</span>
                            @else
                                <span class="equipment-state acquired">{{ $linked->count() }} acquisition(s)</span>
                            @endif
                        </td>
                        <td class="actions">@if($canManage)<button type="button" class="icon-action" title="Edit" aria-label="Edit approved equipment {{ $line->item_name }}" data-open-dialog="editBudget{{ $line->id }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty">No approved equipment or technology recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel liquidation-section" id="acquisitions">
        <div class="panel-head">
            <div><span class="eyebrow">EQUIPMENT ACQUISITION & LIQUIDATION</span><h2>What was actually acquired and liquidated</h2></div>
            @if($canManage)<button type="button" class="btn" data-open-dialog="addAcquisition"><i class="fa-solid fa-plus"></i> Add Equipment Acquisition</button>@endif
        </div>
        <div class="liquidation-section-summary">
            <span><b>{{ $money($purchasedTotal) }}</b> purchased</span>
            <span><b>{{ $money($liquidatedTotal) }}</b> liquidated</span>
            <span><b>{{ $money($notLiquidated) }}</b> awaiting liquidation</span>
        </div>

        <div class="equipment-record-list">
            @forelse($proposal->acquisitions->sortBy('id') as $acquisition)
                @php
                    $approved = $acquisition->budgetLine ? (float)$acquisition->budgetLine->approved_amount : null;
                    $realigned = $acquisition->realigned_amount !== null ? (float)$acquisition->realigned_amount : null;
                    $basis = $realigned !== null ? $realigned : $approved;
                    $balance = $basis !== null ? $basis - (float)$acquisition->actual_cost : null;
                    $isLiquidated = $acquisition->date_liquidated !== null;
                    $state = $isLiquidated ? 'Liquidated' : ($acquisition->date_acquired ? 'Acquired / For Liquidation' : 'For Acquisition');
                @endphp
                <article class="equipment-record">
                    <div class="equipment-record-head">
                        <div>
                            <span class="liquidation-mini-label">{{ $acquisition->budgetLine?->item_name ? 'Linked to: '.$acquisition->budgetLine->item_name : 'New / realigned equipment' }}</span>
                            <h3>{{ $acquisition->actual_item_name }}</h3>
                            @if($acquisition->specifications_liquidated)<p>{{ $acquisition->specifications_liquidated }}</p>@endif
                        </div>
                        <span class="equipment-state {{ $isLiquidated ? 'liquidated' : ($acquisition->date_acquired ? 'acquired' : 'pending') }}">{{ $state }}</span>
                    </div>

                    <div class="equipment-record-grid">
                        <div><span>Quantity / Unit</span><b>{{ number_format((float)$acquisition->quantity, 2) }} {{ $acquisition->unit ?: $acquisition->budgetLine?->unit }}</b></div>
                        <div><span>Amount Approved</span><b>{{ $approved !== null ? $money($approved) : '—' }}</b></div>
                        <div><span>Amount Realigned</span><b>{{ $realigned !== null ? $money($realigned) : '—' }}</b></div>
                        <div class="highlight"><span>Purchased Amount</span><b>{{ $money($acquisition->actual_cost) }}</b></div>
                        <div><span>Balance</span><b class="{{ $balance !== null && $balance < -0.005 ? 'negative-text' : '' }}">{{ $balance !== null ? $money($balance) : '—' }}</b></div>
                        <div><span>SETUP Property No.</span><b>{{ $acquisition->property_number ?: '—' }}</b></div>
                        <div><span>Supplier</span><b>{{ $acquisition->supplier_name ?: '—' }}</b></div>
                        <div><span>Date Acquired</span><b>{{ $acquisition->date_acquired?->format('M d, Y') ?: '—' }}</b></div>
                        <div class="{{ $isLiquidated ? 'success-box' : '' }}"><span>Date Liquidated</span><b>{{ $acquisition->date_liquidated?->format('M d, Y') ?: 'Not yet liquidated' }}</b></div>
                    </div>

                    @if($acquisition->remarks)<p class="equipment-record-remarks"><b>Remarks:</b> {{ $acquisition->remarks }}</p>@endif

                    @if($canManage)
                        <div class="acquisition-card-actions"><button type="button" class="btn small" data-open-dialog="editAcquisition{{ $acquisition->id }}">Edit Equipment</button></div>
                    @endif
                </article>
            @empty
                <p class="empty equipment-empty">No equipment acquisitions recorded yet.</p>
            @endforelse
        </div>
    </section>

    <section class="panel liquidation-section compact-attachments" id="supporting-documents">
        <div class="panel-head">
            <div><span class="eyebrow">ATTACHMENTS</span><h2>Optional supporting files</h2></div>
            <span class="finding-count">{{ $proposal->liquidationDocuments->count() }}</span>
        </div>
        @if($canManage)
            <form method="POST" action="{{ route('proposals.liquidation.documents.store', $proposal) }}" enctype="multipart/form-data" class="liquidation-upload-form simple-upload-form">@csrf
                <select name="document_type" required><option value="">Document type...</option>@foreach($documentTypes as $type)<option value="{{ $type }}">{{ $type }}</option>@endforeach</select>
                <select name="acquisition_id"><option value="">Project-level attachment</option>@foreach($proposal->acquisitions as $a)<option value="{{ $a->id }}">{{ $a->actual_item_name }}</option>@endforeach</select>
                <input type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.webp,.zip" required aria-label="Choose supporting document">
                <button class="btn">Upload</button>
            </form>
        @endif
        <div class="liquidation-document-list compact-document-list">
            @forelse($proposal->liquidationDocuments->sortByDesc('created_at') as $document)
                <article>
                    <i class="fa-regular fa-file-lines"></i>
                    <div><b>{{ $document->document_type }}</b><a href="{{ route('proposals.liquidation.documents.download', [$proposal, $document]) }}">{{ $document->name }}</a><small>{{ $document->acquisition?->actual_item_name ?: 'Project-level' }} · {{ $document->created_at->format('M d, Y') }}</small></div>
                    @if($canManage)<form method="POST" action="{{ route('proposals.liquidation.documents.destroy', [$proposal, $document]) }}" data-confirm-title="Delete attachment?" data-confirm-message="This file will be permanently removed." data-confirm-button="Delete">@csrf @method('DELETE')<button class="icon-action danger" title="Delete" aria-label="Delete attachment {{ $document->name }}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>@endif
                </article>
            @empty
                <p class="empty">No attachments uploaded.</p>
            @endforelse
        </div>
    </section>
</div>

@if($canManage)
<dialog id="addBudgetLine" class="modal liquidation-dialog"><form method="POST" action="{{ route('proposals.liquidation.budget-lines.store', $proposal) }}" class="modal-card liquidation-form-card">@csrf
    <div class="panel-head"><div><span class="eyebrow">APPROVED EQUIPMENT</span><h2>Add approved equipment / technology</h2></div><button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close add approved equipment dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    @include('proposals.partials.liquidation-budget-fields', ['line' => null])
    <div class="form-actions"><button type="button" class="btn" data-close-dialog>Cancel</button><button class="btn primary">Add Approved Equipment</button></div>
</form></dialog>

<dialog id="addAcquisition" class="modal liquidation-dialog"><form method="POST" action="{{ route('proposals.liquidation.acquisitions.store', $proposal) }}" class="modal-card liquidation-form-card">@csrf
    <div class="panel-head"><div><span class="eyebrow">ACQUISITION & LIQUIDATION</span><h2>Add equipment actually acquired</h2></div><button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close add equipment acquisition dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    @include('proposals.partials.liquidation-acquisition-fields', ['acquisition' => null])
    <div class="form-actions"><button type="button" class="btn" data-close-dialog>Cancel</button><button class="btn primary">Save Equipment</button></div>
</form></dialog>

@foreach($proposal->budgetLines as $line)
<dialog id="editBudget{{ $line->id }}" class="modal liquidation-dialog"><form method="POST" action="{{ route('proposals.liquidation.budget-lines.update', [$proposal, $line]) }}" class="modal-card liquidation-form-card">@csrf @method('PUT')
    <div class="panel-head"><div><span class="eyebrow">APPROVED EQUIPMENT</span><h2>Edit approved equipment</h2></div><button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close edit approved equipment dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    @include('proposals.partials.liquidation-budget-fields', ['line' => $line])
    <div class="form-actions"><button type="button" class="btn" data-close-dialog>Cancel</button><button class="btn primary">Save Changes</button></div>
</form><form method="POST" action="{{ route('proposals.liquidation.budget-lines.destroy', [$proposal, $line]) }}" class="liquidation-dialog-delete" data-confirm-title="Delete approved equipment?" data-confirm-message="This can only be deleted when no acquisition references it." data-confirm-button="Delete">@csrf @method('DELETE')<button class="btn danger">Delete Equipment</button></form></dialog>
@endforeach

@foreach($proposal->acquisitions as $acquisition)
<dialog id="editAcquisition{{ $acquisition->id }}" class="modal liquidation-dialog"><form method="POST" action="{{ route('proposals.liquidation.acquisitions.update', [$proposal, $acquisition]) }}" class="modal-card liquidation-form-card">@csrf @method('PUT')
    <div class="panel-head"><div><span class="eyebrow">ACQUISITION & LIQUIDATION</span><h2>Edit acquired equipment</h2></div><button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close edit equipment acquisition dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    @include('proposals.partials.liquidation-acquisition-fields', ['acquisition' => $acquisition])
    <div class="form-actions"><button type="button" class="btn" data-close-dialog>Cancel</button><button class="btn primary">Save Changes</button></div>
</form><form method="POST" action="{{ route('proposals.liquidation.acquisitions.destroy', [$proposal, $acquisition]) }}" class="liquidation-dialog-delete" data-confirm-title="Delete equipment acquisition?" data-confirm-message="This can only be deleted when it has no linked attachments or legacy ledger records." data-confirm-button="Delete">@csrf @method('DELETE')<button class="btn danger">Delete Acquisition</button></form></dialog>
@endforeach
@endif
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
    document.querySelectorAll('.liquidation-dialog [data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
    document.querySelectorAll('.liquidation-dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); }));
});
</script>
@endpush
