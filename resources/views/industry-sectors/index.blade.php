@extends('layouts.app')
@section('title','Industry Sectors')
@section('page-title','Industry Sector Management')

@section('top-actions')
@if(auth()->user()->hasPermission('industry_sectors.create'))
    <button class="btn primary" type="button" data-sector-add><i class="fa-solid fa-plus"></i> Add sector</button>
@endif
@endsection

@section('content')
<div class="page-intro"><div><h1>Industry sectors</h1><p>Classification codes used when generating Proposal Codes.</p></div></div>

<section class="province-summary-grid">
    <div class="province-summary-card"><small>ACTIVE</small><strong>{{ number_format($activeCount) }}</strong><span>Available on new and editable proposals</span></div>
    <div class="province-summary-card"><small>INACTIVE</small><strong>{{ number_format($inactiveCount) }}</strong><span>Hidden from new selections</span></div>
    <div class="province-summary-card"><small>REFERENCED</small><strong>{{ number_format($usedCount) }}</strong><span>Already used by proposal records</span></div>
</section>

<section class="panel province-guidance">
    <div class="province-guidance-icon"><i class="fa-solid fa-circle-info"></i></div>
    <div><b>Industry Sector is separate from Program Sector.</b><p>SETUP, GIA, CEST, and SMART remain Program Sectors for analytics. These industry codes are used only for classification and Proposal Code generation. Codes already used by a proposal are locked.</p></div>
</section>

<section class="panel table-panel province-table-panel">
    <div class="table-wrap">
        <table class="province-table">
            <thead><tr><th>Code</th><th>Industry sector</th><th>Status</th><th class="num">Proposals</th><th style="width:190px;text-align:right">Actions</th></tr></thead>
            <tbody>
            @forelse($sectors as $sector)
                <tr>
                    <td><span class="sector-badge setup">{{ $sector->code }}</span><small>Sort order {{ $sector->sort_order }}</small></td>
                    <td><b>{{ $sector->name }}</b>@if($sector->description)<small>{{ $sector->description }}</small>@endif</td>
                    <td><span class="province-status {{ $sector->is_active ? 'active' : 'inactive' }}"><i></i>{{ $sector->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="num">{{ number_format($sector->proposals_count) }}</td>
                    <td><div class="province-row-actions">
                        @if(auth()->user()->hasPermission('industry_sectors.edit'))
                            <button class="icon-action edit" type="button" title="Edit sector" aria-label="Edit {{ $sector->code }} industry sector" data-sector-edit data-code="{{ $sector->code }}" data-name="{{ $sector->name }}" data-description="{{ $sector->description }}" data-sort-order="{{ $sector->sort_order }}" data-code-locked="{{ $sector->proposals_count > 0 ? '1' : '0' }}" data-action="{{ route('industry-sectors.update',$sector) }}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
                        @endif
                        @if(auth()->user()->hasPermission('industry_sectors.toggle'))
                            <form method="POST" action="{{ route('industry-sectors.toggle',$sector) }}" data-confirm-title="{{ $sector->is_active ? 'Deactivate industry sector?' : 'Activate industry sector?' }}" data-confirm-message="Existing proposal records and generated codes will remain unchanged." data-confirm-button="{{ $sector->is_active ? 'Deactivate' : 'Activate' }}">@csrf @method('PATCH')<button class="icon-action {{ $sector->is_active ? 'warning' : 'success' }}" type="submit" aria-label="{{ $sector->is_active ? 'Deactivate' : 'Activate' }} {{ $sector->code }} industry sector"><i class="fa-solid {{ $sector->is_active ? 'fa-toggle-on' : 'fa-toggle-off' }}" aria-hidden="true"></i></button></form>
                        @endif
                        @if(auth()->user()->hasPermission('industry_sectors.delete') && $sector->proposals_count === 0)
                            <form method="POST" action="{{ route('industry-sectors.destroy',$sector) }}" data-confirm-title="Delete unused industry sector?" data-confirm-message="{{ $sector->code }} will be permanently removed." data-confirm-button="Delete sector">@csrf @method('DELETE')<button class="icon-action danger" type="submit" aria-label="Delete {{ $sector->code }} industry sector"><i class="fa-solid fa-trash" aria-hidden="true"></i></button></form>
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No industry sectors are configured.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

@if(auth()->user()->hasPermission('industry_sectors.create'))
<div class="province-modal" id="sectorAddModal" aria-hidden="true"><div class="province-modal-backdrop" data-sector-add-close></div><section class="province-modal-card" role="dialog" aria-modal="true" aria-labelledby="sectorAddTitle"><div class="province-modal-head"><div><span class="eyebrow">INDUSTRY MASTER</span><h2 id="sectorAddTitle">Add industry sector</h2></div><button type="button" class="province-modal-close" data-sector-add-close aria-label="Close add industry sector dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><form method="POST" action="{{ route('industry-sectors.store') }}" class="province-master-form">@csrf
    <div class="province-form-grid"><label>Sector code<input name="code" required maxlength="10" placeholder="e.g. 01A"></label><label>Sector name<input name="name" required maxlength="255"></label><label class="span-2">Description<textarea name="description" maxlength="2000"></textarea></label><label>Sort order<input type="number" name="sort_order" min="0" max="9999" placeholder="Automatic"></label><label class="province-active-check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked><span><b>Active</b><small>Available on proposal forms</small></span></label></div>
    <div class="province-modal-actions"><button type="button" class="btn" data-sector-add-close>Cancel</button><button class="btn primary">Add sector</button></div>
</form></section></div>
@endif

@if(auth()->user()->hasPermission('industry_sectors.edit'))
<div class="province-modal" id="sectorEditModal" aria-hidden="true"><div class="province-modal-backdrop" data-sector-edit-close></div><section class="province-modal-card" role="dialog" aria-modal="true" aria-labelledby="sectorEditTitle"><div class="province-modal-head"><div><span class="eyebrow">INDUSTRY MASTER</span><h2 id="sectorEditTitle">Edit industry sector</h2></div><button type="button" class="province-modal-close" data-sector-edit-close aria-label="Close edit industry sector dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div><form method="POST" id="sectorEditForm" class="province-master-form">@csrf @method('PUT')
    <div class="province-form-grid"><label>Sector code<input id="sectorEditCode" name="code" required maxlength="10"><small id="sectorCodeHelp"></small></label><label>Sector name<input id="sectorEditName" name="name" required maxlength="255"></label><label class="span-2">Description<textarea id="sectorEditDescription" name="description" maxlength="2000"></textarea></label><label>Sort order<input id="sectorEditSort" type="number" name="sort_order" min="0" max="9999" required></label></div>
    <div class="province-modal-actions"><button type="button" class="btn" data-sector-edit-close>Cancel</button><button class="btn primary">Save changes</button></div>
</form></section></div>
@endif

<script>
document.addEventListener('DOMContentLoaded', () => {
    const setModal = (modal, open) => { if (!modal) return; modal.classList.toggle('is-open', open); modal.setAttribute('aria-hidden', open ? 'false' : 'true'); document.body.style.overflow = open ? 'hidden' : ''; };
    const addModal = document.getElementById('sectorAddModal');
    document.querySelector('[data-sector-add]')?.addEventListener('click', () => setModal(addModal, true));
    addModal?.querySelectorAll('[data-sector-add-close]').forEach(el => el.addEventListener('click', () => setModal(addModal, false)));
    const editModal = document.getElementById('sectorEditModal');
    const editForm = document.getElementById('sectorEditForm');
    const code = document.getElementById('sectorEditCode');
    const help = document.getElementById('sectorCodeHelp');
    document.querySelectorAll('[data-sector-edit]').forEach(button => button.addEventListener('click', () => {
        editForm.action = button.dataset.action; code.value = button.dataset.code || ''; code.readOnly = button.dataset.codeLocked === '1';
        help.textContent = code.readOnly ? 'Locked because this code is already used by a proposal.' : 'Letters and numbers only.';
        document.getElementById('sectorEditName').value = button.dataset.name || '';
        document.getElementById('sectorEditDescription').value = button.dataset.description || '';
        document.getElementById('sectorEditSort').value = button.dataset.sortOrder || '0';
        setModal(editModal, true);
    }));
    editModal?.querySelectorAll('[data-sector-edit-close]').forEach(el => el.addEventListener('click', () => setModal(editModal, false)));
    document.addEventListener('keydown', event => { if (event.key === 'Escape') { setModal(addModal, false); setModal(editModal, false); } });
});
</script>
@endsection
