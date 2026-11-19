@extends('layouts.app')
@section('title', 'Equipment & Liquidation')
@section('page-title', 'Equipment & Liquidation')

@section('top-back')
    <a class="back topbar-back" href="{{ route('proposals.show', $proposal) }}">← Back to proposal</a>
@endsection

@section('top-actions')
    <a class="btn" href="#realignment-history">Realignment History</a>
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
    $originalLines = $proposal->budgetLines->where('added_by_realignment', false);
    $activeLines = $proposal->budgetLines->where('removed_by_realignment', false);
    $acquisitionsByLine = $proposal->acquisitions->groupBy('budget_line_id');
    $budgetLinesById = $proposal->budgetLines->keyBy('id');
    $realignableLines = $originalLines->filter(fn ($line) => $line->canRealign($acquisitionsByLine->has($line->id)));
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
        <article><span>Originally Approved Equipment</span><b>{{ $money($approvedTotal) }}</b><small>{{ $originalLines->count() }} originally approved item(s)</small></article>
        <article><span>Realigned Amount</span><b>{{ $money($realignedTotal) }}</b><small>Amounts recorded through realignment</small></article>
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
            <div><span class="eyebrow">APPROVED EQUIPMENT / TECHNOLOGY</span><h2>Approved equipment plan</h2></div>
            @if($canManage)
                <div class="equipment-plan-actions">
                    <button type="button" class="btn" data-open-dialog="addBudgetLine"><i class="fa-solid fa-plus"></i> Add Approved Equipment</button>
                    <button type="button" class="btn primary" data-open-dialog="equipmentRealignment">Realignment</button>
                </div>
            @endif
        </div>
        <div class="liquidation-section-summary">
            <span><b>{{ $activeLines->count() }}</b> item(s) in the current plan</span>
            <span><b>{{ $money($financials['current_approved_equipment']) }}</b> current approved total</span>
        </div>
        <div class="liquidation-table-wrap">
            <table class="liquidation-data-table equipment-approved-table">
                <thead><tr><th>Equipment / Technology</th><th>Specifications as Approved</th><th>Qty / Unit</th><th class="number">Original Amount</th><th class="number">Current Amount</th><th>Acquisition</th><th class="actions">Actions</th></tr></thead>
                <tbody>
                @forelse($proposal->budgetLines as $line)
                    @php
                        $linkedCount = $acquisitionsByLine->get($line->id)?->count() ?? 0;
                        $realignmentStatus = $line->realignmentStatus();
                        $realignmentSource = $budgetLinesById->get($line->realignment_source_id);
                    @endphp
                    <tr>
                        <td>
                            <b>{{ $line->item_name }}</b>
                            @if($realignmentStatus)<br><span class="equipment-state">{{ $realignmentStatus }}</span>@endif
                            @if($realignmentSource)<small class="equipment-spec-text">Realigned from {{ $realignmentSource->item_name }}</small>@endif
                        </td>
                        <td><span class="equipment-spec-text">{{ $line->description ?: '—' }}</span></td>
                        <td><b>{{ number_format((float)$line->quantity, 2) }} {{ $line->unit ?: '' }}</b></td>
                        <td class="number"><b>{{ $line->added_by_realignment ? '—' : $money($line->approved_amount) }}</b></td>
                        <td class="number"><b>{{ $line->removed_by_realignment ? '—' : $money($line->currentApprovedAmount()) }}</b></td>
                        <td>
                            @if($linkedCount === 0 && ! $line->removed_by_realignment)
                                <span class="equipment-state pending">Not yet acquired</span>
                            @elseif($linkedCount > 0)
                                <span class="equipment-state acquired">{{ $linkedCount }} acquisition(s)</span>
                            @endif
                        </td>
                        <td class="actions">@if($canManage && ! $line->added_by_realignment && ! $line->removed_by_realignment)<button type="button" class="icon-action" title="Edit" aria-label="Edit approved equipment {{ $line->item_name }}" data-open-dialog="editBudget{{ $line->id }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No approved equipment or technology recorded yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel liquidation-section" id="realignment-history">
        <div class="panel-head"><h2>Realignment History</h2></div>
        @if($realignmentHistory->isNotEmpty())
            <div class="liquidation-table-wrap">
                <table class="liquidation-data-table">
                    <thead>
                        <tr>
                            <th scope="col">Recorded</th>
                            <th scope="col">Equipment / Technology</th>
                            <th scope="col">Change</th>
                            <th scope="col" class="number">Previous Amount</th>
                            <th scope="col" class="number">Updated Amount</th>
                            <th scope="col">Recorded By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($realignmentHistory as $entry)
                            @php
                                $details = $entry->metadata ?? [];
                                $action = match ($details['operation'] ?? null) {
                                    'add' => 'Equipment added',
                                    'remove' => 'Removed from plan',
                                    'revise' => 'Approved amount revised',
                                    default => 'Realignment recorded',
                                };
                                $line = $budgetLinesById->get($details['budget_line_id'] ?? null);
                                $sourceId = $details['original_budget_line_id'] ?? $line?->realignment_source_id;
                                $sourceName = ($details['operation'] ?? null) === 'add'
                                    ? ($details['original_item'] ?? $budgetLinesById->get($sourceId)?->item_name)
                                    : null;
                            @endphp
                            <tr>
                                <td><time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->format('M d, Y · g:i A') }}</time></td>
                                <td>
                                    <b>{{ $details['item'] ?? $line?->item_name ?? '—' }}</b>
                                    @if($sourceName)<small class="equipment-spec-text">Realigned from {{ $sourceName }}</small>@endif
                                </td>
                                <td>{{ $action }}</td>
                                <td class="number">{{ isset($details['previous_amount']) ? $money($details['previous_amount']) : '—' }}</td>
                                <td class="number">{{ isset($details['current_amount']) ? $money($details['current_amount']) : '—' }}</td>
                                <td>{{ $entry->user?->full_name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="empty equipment-empty">No equipment realignments have been recorded for this proposal.</p>
        @endif
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
                    $budgetLine = $acquisition->budgetLine;
                    $approved = $budgetLine && ! $budgetLine->added_by_realignment ? (float) $budgetLine->approved_amount : null;
                    $realigned = $budgetLine?->realigned_amount ?? $acquisition->realigned_amount;
                    $basis = $realigned ?? $approved;
                    $hasPlanRealignment = $budgetLine?->realignmentStatus() !== null;
                    $purchased = $hasPlanRealignment ? $acquisitionsByLine->get($budgetLine->id)->sum('actual_cost') : $acquisition->actual_cost;
                    $balance = $basis !== null && ! $budgetLine?->removed_by_realignment ? (float) $basis - (float) $purchased : null;
                    $isLiquidated = $acquisition->date_liquidated !== null;
                    $state = $isLiquidated ? 'Liquidated' : ($acquisition->date_acquired ? 'Acquired / For Liquidation' : 'For Acquisition');
                @endphp
                <article class="equipment-record">
                    <div class="equipment-record-head">
                        <div>
                            <span class="liquidation-mini-label">{{ $acquisition->budgetLine?->item_name ? 'Linked to: '.$acquisition->budgetLine->item_name : 'New / realigned equipment' }}</span>
                            @if($budgetLine?->removed_by_realignment)<span class="equipment-state">Removed by realignment</span>@endif
                            <h3>{{ $acquisition->actual_item_name }}</h3>
                            @if($acquisition->specifications_liquidated)<p>{{ $acquisition->specifications_liquidated }}</p>@endif
                        </div>
                        <span class="equipment-state {{ $isLiquidated ? 'liquidated' : ($acquisition->date_acquired ? 'acquired' : 'pending') }}">{{ $state }}</span>
                    </div>

                    <div class="equipment-record-grid">
                        <div><span>Quantity / Unit</span><b>{{ number_format((float)$acquisition->quantity, 2) }} {{ $acquisition->unit ?: $acquisition->budgetLine?->unit }}</b></div>
                        <div><span>Amount Approved</span><b>{{ $approved !== null ? $money($approved) : '—' }}</b></div>
                        <div><span>Amount Realigned</span><b>{{ $realigned !== null && ! $budgetLine?->removed_by_realignment ? $money($realigned) : '—' }}</b></div>
                        <div class="highlight"><span>Purchased Amount</span><b>{{ $money($acquisition->actual_cost) }}</b></div>
                        <div><span>{{ $hasPlanRealignment ? 'Equipment Plan Balance' : 'Balance' }}</span><b class="{{ $balance !== null && $balance < -0.005 ? 'negative-text' : '' }}">{{ $balance !== null ? $money($balance) : '—' }}</b></div>
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
<dialog id="equipmentRealignment" class="modal liquidation-dialog" aria-labelledby="equipmentRealignmentTitle">
    <form method="POST" action="{{ route('proposals.liquidation.realignments.store', $proposal) }}" class="modal-card liquidation-form-card">
        @csrf
        <div class="panel-head">
            <h2 id="equipmentRealignmentTitle">Equipment realignment</h2>
            <button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close realignment dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="liquidation-form-grid equipment-realignment-fields">
            @if($errors->realignment->any())
                <ul class="equipment-realignment-errors" role="alert">
                    @foreach($errors->realignment->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            @if($realignableLines->isEmpty())
                <p class="equipment-realignment-message">There are no originally approved items without acquisition records in the current equipment plan.</p>
            @else
                <label class="span-2">Originally approved equipment or technology
                    <select name="budget_line_id" required>
                        <option value="">Select equipment</option>
                        @foreach($realignableLines as $line)
                            <option value="{{ $line->id }}" @selected((string) old('budget_line_id') === (string) $line->id)>
                                {{ $line->item_name }} · {{ $money($line->approved_amount) }} originally approved
                            </option>
                        @endforeach
                    </select>
                    <small class="field-help">Eligible items have no acquisition records.</small>
                </label>
                <label class="span-2">Change to record
                    <select name="operation" required>
                        <option value="">Select a change</option>
                        <option value="revise" @selected(old('operation') === 'revise')>Revise approved amount</option>
                        <option value="add" @selected(old('operation') === 'add')>Add equipment linked to this item</option>
                        <option value="remove" @selected(old('operation') === 'remove')>Remove this item from the plan</option>
                    </select>
                </label>
                <fieldset data-realignment-operations="revise" hidden disabled>
                    <label>Revised approved amount
                        <input type="number" name="revised_amount" step="0.01" min="0" max="9999999999999.99" value="{{ old('revised_amount') }}" required>
                    </label>
                </fieldset>
                <fieldset data-realignment-operations="add" hidden disabled>
                    <p class="field-help">The selected item stays in the plan when you add equipment.</p>
                    @include('proposals.partials.liquidation-budget-fields', ['line' => null, 'forRealignment' => true])
                </fieldset>
                <fieldset data-realignment-operations="remove" hidden disabled>
                    <p class="field-help">The original approval stays on record when the item is removed from the current plan.</p>
                </fieldset>
            @endif
        </div>
        <div class="form-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            @if($realignableLines->isNotEmpty())<button class="btn primary">Save Realignment</button>@endif
        </div>
    </form>
</dialog>

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

@foreach($activeLines->where('added_by_realignment', false) as $line)
<dialog id="editBudget{{ $line->id }}" class="modal liquidation-dialog"><form method="POST" action="{{ route('proposals.liquidation.budget-lines.update', [$proposal, $line]) }}" class="modal-card liquidation-form-card">@csrf @method('PUT')
    <div class="panel-head"><div><span class="eyebrow">APPROVED EQUIPMENT</span><h2>Edit approved equipment</h2></div><button type="button" class="liquidation-modal-close" data-close-dialog aria-label="Close edit approved equipment dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
    @include('proposals.partials.liquidation-budget-fields', ['line' => $line])
    <div class="form-actions"><button type="button" class="btn" data-close-dialog>Cancel</button><button class="btn primary">Save Changes</button></div>
</form>
@if($line->realignmentStatus() === null)
    <form method="POST" action="{{ route('proposals.liquidation.budget-lines.destroy', [$proposal, $line]) }}" class="liquidation-dialog-delete" data-confirm-title="Delete approved equipment?" data-confirm-message="This can only be deleted when no acquisition references it." data-confirm-button="Delete">@csrf @method('DELETE')<button class="btn danger">Delete Equipment</button></form>
@endif
</dialog>
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

    const realignment = document.getElementById('equipmentRealignment');
    if (realignment) {
        const operation = realignment.querySelector('[name="operation"]');
        const syncFields = () => {
            realignment.querySelectorAll('[data-realignment-operations]').forEach(fieldset => {
                const active = fieldset.dataset.realignmentOperations.split(' ').includes(operation.value);
                fieldset.hidden = !active;
                fieldset.disabled = !active;
            });
        };
        if (operation) {
            operation.addEventListener('change', syncFields);
            syncFields();
        }
        if (realignment.querySelector('.equipment-realignment-errors')) {
            realignment.showModal();
        }
    }
});
</script>
@endpush
