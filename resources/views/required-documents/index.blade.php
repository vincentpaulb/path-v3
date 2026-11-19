@extends('layouts.app')
@section('title','Required Documents')
@section('page-title','Required Documents')

@section('content')
<div class="page-intro">
    <div>
        <h1>Required documents</h1>
    </div>
    <div class="summary-pill">
        <span>ACTIVE CHECKLIST ITEMS</span>
        <b>{{ number_format($totalDocuments) }}</b>
    </div>
</div>

@if(auth()->user()->hasPermission('required_documents.create'))
<section class="panel required-document-create">
    <div class="panel-head">
        <div>
            <span class="eyebrow">ADD CHECKLIST ITEM</span>
            <h2>New required document</h2>
        </div>
    </div>

    <form method="POST" action="{{ route('required-documents.store') }}" class="required-document-add">
        @csrf
        <label>
            Document name
            <input
                name="name"
                value="{{ old('name') }}"
                required
                maxlength="500"
                placeholder="e.g. Updated Financial Statement"
                autocomplete="off"
            >
            @error('name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <button class="btn primary" type="submit">
            <i class="fa-solid fa-plus" aria-hidden="true"></i>
            Add document
        </button>
    </form>

    <p class="required-document-note">
        Additions and edits immediately update the active master checklist. Archiving removes an item from active proposal Document Review while keeping it available for restoration; historical lacking-document entries already saved on proposals remain unchanged.
    </p>
</section>
@endif

<section class="panel table-panel">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:70px">#</th>
                    <th>Required document</th>
                    <th>Added</th>
                    <th style="width:130px;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($documents as $document)
                    <tr>
                        <td class="required-document-number">{{ ($documents->firstItem() ?? 1) + $loop->index }}</td>
                        <td>
                            <b>{{ $document->name }}</b>
                            <small>Included in the proposal document-review checklist</small>
                        </td>
                        <td>{{ $document->created_at->format('M d, Y') }}</td>
                        <td>
                            <div class="required-document-actions">
                                @if(auth()->user()->hasPermission('required_documents.edit'))
                                <button
                                    class="icon-action edit"
                                    type="button"
                                    title="Edit"
                                    aria-label="Edit {{ $document->name }}"
                                    data-required-document-edit
                                    data-name="{{ $document->name }}"
                                    data-action="{{ route('required-documents.update', $document) }}"
                                >
                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                </button>
                                @endif

                                @if(auth()->user()->hasPermission('required_documents.archive'))
                                <form
                                    method="POST"
                                    action="{{ route('required-documents.destroy',$document) }}"
                                    data-confirm-title="Move required document to Archives?"
                                    data-confirm-message="{{ $document->name }} will be removed from the active master checklist and moved to Archives. Existing proposal review history is preserved and the document can be restored later."
                                    data-confirm-button="Move to Archives"
                                >
                                    @csrf
                                    @method('DELETE')
                                    <button class="icon-action danger" type="submit" title="Move to Archives" aria-label="Move {{ $document->name }} to Archives">
                                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="empty">
                            No required documents are configured. Add the first checklist item above.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($documents->hasPages())
        <div class="required-document-pagination">{{ $documents->links() }}</div>
    @endif
</section>

@if(auth()->user()->hasPermission('required_documents.edit'))
<div class="required-document-modal" id="requiredDocumentEditModal" aria-hidden="true">
    <div class="required-document-modal-backdrop" data-required-document-close></div>
    <section class="required-document-modal-card" role="dialog" aria-modal="true" aria-labelledby="requiredDocumentEditTitle">
        <div class="required-document-modal-head">
            <div>
                <span class="eyebrow">EDIT CHECKLIST ITEM</span>
                <h2 id="requiredDocumentEditTitle">Edit required document</h2>
            </div>
            <button type="button" class="required-document-modal-close" data-required-document-close aria-label="Close edit dialog">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>

        <form method="POST" id="requiredDocumentEditForm" class="required-document-edit-form">
            @csrf
            @method('PUT')
            <label>
                Document name
                <input
                    id="requiredDocumentEditName"
                    name="name"
                    required
                    maxlength="500"
                    autocomplete="off"
                >
            </label>

            <p class="required-document-edit-help">
                Saving this change updates the name shown in the master checklist and future proposal document reviews. Existing historical lacking-document entries are not rewritten.
            </p>

            <div class="required-document-modal-actions">
                <button type="button" class="btn" data-required-document-close>Cancel</button>
                <button type="submit" class="btn primary">
                    <i class="fa-solid fa-check" aria-hidden="true"></i>
                    Save changes
                </button>
            </div>
        </form>
    </section>
</div>

<style>
.required-document-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}.required-document-actions form{margin:0}.icon-action.edit{color:#0b6cae}.required-document-modal{position:fixed;inset:0;z-index:1200;display:none;align-items:center;justify-content:center;padding:24px}.required-document-modal.is-open{display:flex}.required-document-modal-backdrop{position:absolute;inset:0;background:rgba(6,24,39,.58);backdrop-filter:blur(3px)}.required-document-modal-card{position:relative;width:min(620px,100%);background:#fff;border:1px solid #dbe5ec;border-radius:18px;box-shadow:0 24px 70px rgba(5,32,51,.24);padding:24px}.required-document-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding-bottom:18px;border-bottom:1px solid #edf2f5}.required-document-modal-head h2{margin:4px 0 0}.required-document-modal-close{width:38px;height:38px;border:1px solid #dce6ec;border-radius:10px;background:#fff;color:#667985;cursor:pointer}.required-document-modal-close:hover{background:#f4f8fa;color:#102f45}.required-document-edit-form{display:grid;gap:18px;padding-top:20px}.required-document-edit-form label{display:grid;gap:8px;font-size:.82rem;font-weight:700;color:#29475a}.required-document-edit-form input{width:100%;border:1px solid #cfdde5;border-radius:10px;padding:12px 14px;font:inherit;color:#102f45;background:#fff;outline:none}.required-document-edit-form input:focus{border-color:#2389c4;box-shadow:0 0 0 3px rgba(35,137,196,.12)}.required-document-edit-help{margin:0;padding:12px 14px;border-radius:10px;background:#f5f9fb;color:#607786;font-size:.82rem;line-height:1.55}.required-document-modal-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:2px}@media(max-width:640px){.required-document-modal{padding:14px}.required-document-modal-card{padding:18px}.required-document-modal-actions{flex-direction:column-reverse}.required-document-modal-actions .btn{width:100%;justify-content:center}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('requiredDocumentEditModal');
    const form = document.getElementById('requiredDocumentEditForm');
    const input = document.getElementById('requiredDocumentEditName');

    if (!modal || !form || !input) return;

    const openModal = (button) => {
        form.action = button.dataset.action;
        input.value = button.dataset.name || '';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => {
            input.focus();
            input.select();
        }, 30);
    };

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        form.action = '';
    };

    document.addEventListener('click', (event) => {
        const editButton = event.target.closest('[data-required-document-edit]');
        if (editButton) return openModal(editButton);

        if (event.target.closest('[data-required-document-close]')) closeModal();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
});
</script>
@endif
@endsection
