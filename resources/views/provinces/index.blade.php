@extends('layouts.app')
@section('title','Provinces')
@section('page-title','Province Management')

@section('top-actions')
    @if(auth()->user()->hasPermission('provinces.create'))
        <button class="btn primary" type="button" data-province-add>
            <i class="fa-solid fa-plus" aria-hidden="true"></i> Add province
        </button>
    @endif
@endsection

@section('content')
<div class="page-intro province-page-intro">
    <div><h1>Provinces</h1></div>
</div>

<section class="province-summary-grid">
    <div class="province-summary-card">
        <small>ACTIVE FOR NEW RECORDS</small>
        <strong>{{ number_format($activeCount) }}</strong>
        <span>Available in new proposal and provincial-user assignments</span>
    </div>
    <div class="province-summary-card">
        <small>INACTIVE / LEGACY</small>
        <strong>{{ number_format($inactiveCount) }}</strong>
        <span>Hidden from new records while historical data remains intact</span>
    </div>
    <div class="province-summary-card">
        <small>HISTORICAL PROVINCES</small>
        <strong>{{ number_format($historicalCount) }}</strong>
        <span>Inactive provinces that are still referenced by proposal records</span>
    </div>
</section>

<section class="panel province-guidance">
    <div class="province-guidance-icon"><i class="fa-solid fa-circle-info"></i></div>
    <div>
        <b>Use Active / Inactive instead of deleting historical provinces.</b>
        <p>Cebu and Bohol are active Region VII provinces by default. Negros Oriental and Siquijor remain available as inactive historical master data so existing proposals continue to display and report correctly. Only unused provinces can be permanently deleted.</p>
    </div>
</section>

<section class="panel table-panel province-table-panel">
    <div class="table-wrap">
        <table class="province-table">
            <thead>
                <tr>
                    <th>Province</th>
                    <th>Administrative Region</th>
                    <th>Proposal Code</th>
                    <th>Status</th>
                    <th class="num">Proposals</th>
                    <th class="num">Scoped Users</th>
                    <th style="width:190px;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($provinces as $province)
                    <tr>
                        <td>
                            <b>{{ $province->name }}</b>
                            <small>Sort order {{ $province->sort_order }}</small>
                        </td>
                        <td>{{ $province->region ?: '—' }}</td>
                        <td><span class="sector-badge setup">{{ $province->proposal_code ?: 'Not set' }}</span></td>
                        <td>
                            <span class="province-status {{ $province->is_active ? 'active' : 'inactive' }}">
                                <i></i>{{ $province->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="num">{{ number_format($province->proposal_count) }}</td>
                        <td class="num">{{ number_format($province->user_count) }}</td>
                        <td>
                            <div class="province-row-actions">
                                @if(auth()->user()->hasPermission('provinces.edit'))
                                    <button
                                        class="icon-action edit"
                                        type="button"
                                        title="Edit province"
                                        aria-label="Edit {{ $province->name }} province"
                                        data-province-edit
                                        data-name="{{ $province->name }}"
                                        data-region="{{ $province->region }}"
                                        data-proposal-code="{{ $province->proposal_code }}"
                                        data-proposal-code-locked="{{ $province->generated_code_count > 0 ? '1' : '0' }}"
                                        data-sort-order="{{ $province->sort_order }}"
                                        data-action="{{ route('provinces.update',$province) }}"
                                    >
                                        <i class="fa-solid fa-pen"></i>
                                    </button>
                                @endif

                                @if(auth()->user()->hasPermission('provinces.toggle'))
                                    <form method="POST" action="{{ route('provinces.toggle',$province) }}"
                                          data-confirm-title="{{ $province->is_active ? 'Deactivate province?' : 'Activate province?' }}"
                                          data-confirm-message="{{ $province->is_active
                                            ? $province->name.' will be removed from new proposal and provincial-user selections. Existing records will remain unchanged.'
                                            : $province->name.' will become available for new proposal and provincial-user selections.' }}"
                                          data-confirm-button="{{ $province->is_active ? 'Deactivate' : 'Activate' }}">
                                        @csrf @method('PATCH')
                                        <button class="icon-action {{ $province->is_active ? 'warning' : 'success' }}" type="submit" title="{{ $province->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $province->is_active ? 'Deactivate' : 'Activate' }} {{ $province->name }} province">
                                            <i class="fa-solid {{ $province->is_active ? 'fa-toggle-on' : 'fa-toggle-off' }}" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endif

                                @if(auth()->user()->hasPermission('provinces.delete') && $province->proposal_count === 0 && $province->user_count === 0)
                                    <form method="POST" action="{{ route('provinces.destroy',$province) }}"
                                          data-confirm-title="Delete unused province?"
                                          data-confirm-message="{{ $province->name }} has no proposal or user references and will be permanently removed from the province master list."
                                          data-confirm-button="Delete province">
                                        @csrf @method('DELETE')
                                        <button class="icon-action danger" type="submit" title="Delete unused province" aria-label="Delete {{ $province->name }} province">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty">No province master data is configured.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if(auth()->user()->hasPermission('provinces.create'))
<div class="province-modal" id="provinceAddModal" aria-hidden="true">
    <div class="province-modal-backdrop" data-province-add-close></div>
    <section class="province-modal-card" role="dialog" aria-modal="true" aria-labelledby="provinceAddTitle">
        <div class="province-modal-head">
            <div><span class="eyebrow">PROVINCE MASTER</span><h2 id="provinceAddTitle">Add province</h2></div>
            <button type="button" class="province-modal-close" data-province-add-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="{{ route('provinces.store') }}" class="province-master-form">
            @csrf
            <div class="province-form-grid">
                <label>Province name
                    <input name="name" value="{{ old('name') }}" required maxlength="100" placeholder="e.g. Cebu">
                </label>
                <label>Administrative region
                    <input name="region" value="{{ old('region') }}" maxlength="120" placeholder="e.g. Region VII">
                </label>
                <label>Proposal Code
                    <input name="proposal_code" value="{{ old('proposal_code') }}" required maxlength="4" placeholder="e.g. C">
                </label>
                <label>Sort order
                    <input type="number" name="sort_order" value="{{ old('sort_order') }}" min="0" max="9999" placeholder="Automatic">
                </label>
                <label class="province-active-check">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', '1') === '1')>
                    <span><b>Active for new records</b><small>Active provinces are selectable on new proposals and province-scoped user accounts.</small></span>
                </label>
            </div>
            <div class="province-modal-actions">
                <button type="button" class="btn" data-province-add-close>Cancel</button>
                <button class="btn primary" type="submit"><i class="fa-solid fa-plus"></i> Add province</button>
            </div>
        </form>
    </section>
</div>
@endif

@if(auth()->user()->hasPermission('provinces.edit'))
<div class="province-modal" id="provinceEditModal" aria-hidden="true">
    <div class="province-modal-backdrop" data-province-edit-close></div>
    <section class="province-modal-card" role="dialog" aria-modal="true" aria-labelledby="provinceEditTitle">
        <div class="province-modal-head">
            <div><span class="eyebrow">PROVINCE MASTER</span><h2 id="provinceEditTitle">Edit province</h2></div>
            <button type="button" class="province-modal-close" data-province-edit-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" id="provinceEditForm" class="province-master-form">
            @csrf @method('PUT')
            <div class="province-form-grid">
                <label>Province name
                    <input id="provinceEditName" name="name" required maxlength="100">
                    <small>Renaming also updates proposal and user province references, including archived records.</small>
                </label>
                <label>Administrative region
                    <input id="provinceEditRegion" name="region" maxlength="120">
                </label>
                <label>Proposal Code
                    <input id="provinceEditProposalCode" name="proposal_code" required maxlength="4">
                    <small id="provinceEditProposalCodeHelp">Used as the final component of generated Proposal Codes.</small>
                </label>
                <label>Sort order
                    <input id="provinceEditSortOrder" type="number" name="sort_order" min="0" max="9999" required>
                </label>
            </div>
            <div class="province-modal-actions">
                <button type="button" class="btn" data-province-edit-close>Cancel</button>
                <button class="btn primary" type="submit"><i class="fa-solid fa-check"></i> Save changes</button>
            </div>
        </form>
    </section>
</div>
@endif

<script>
document.addEventListener('DOMContentLoaded', () => {
    const setModal = (modal, open) => {
        if (!modal) return;
        modal.classList.toggle('is-open', open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.style.overflow = open ? 'hidden' : '';
    };

    const addModal = document.getElementById('provinceAddModal');
    document.querySelector('[data-province-add]')?.addEventListener('click', () => setModal(addModal, true));
    addModal?.querySelectorAll('[data-province-add-close]').forEach(el => el.addEventListener('click', () => setModal(addModal, false)));

    const editModal = document.getElementById('provinceEditModal');
    const editForm = document.getElementById('provinceEditForm');
    const editName = document.getElementById('provinceEditName');
    const editRegion = document.getElementById('provinceEditRegion');
    const editProposalCode = document.getElementById('provinceEditProposalCode');
    const editProposalCodeHelp = document.getElementById('provinceEditProposalCodeHelp');
    const editSort = document.getElementById('provinceEditSortOrder');
    document.querySelectorAll('[data-province-edit]').forEach(button => button.addEventListener('click', () => {
        editForm.action = button.dataset.action;
        editName.value = button.dataset.name || '';
        editRegion.value = button.dataset.region || '';
        editProposalCode.value = button.dataset.proposalCode || '';
        editProposalCode.readOnly = button.dataset.proposalCodeLocked === '1';
        editProposalCodeHelp.textContent = editProposalCode.readOnly
            ? 'Locked because generated Proposal Codes already use this value.'
            : 'Used as the final component of generated Proposal Codes.';
        editSort.value = button.dataset.sortOrder || '0';
        setModal(editModal, true);
        window.setTimeout(() => editName.focus(), 30);
    }));
    editModal?.querySelectorAll('[data-province-edit-close]').forEach(el => el.addEventListener('click', () => setModal(editModal, false)));

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        setModal(addModal, false);
        setModal(editModal, false);
    });
});
</script>
@endsection
