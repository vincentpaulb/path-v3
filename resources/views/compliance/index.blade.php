@extends('layouts.app')
@section('title', 'Compliance Center')
@section('page-title', 'Compliance Center')

@section('content')
<div class="compliance-page">
    <section class="compliance-hero">
        <div>
            <span class="eyebrow light">GOVERNANCE &amp; TRUST</span>
            <h1>Compliance and accessibility</h1>
            <p>Policies, current technical assessments, and organizational approvals for PATH.</p>
        </div>
        <span class="compliance-review-status"><i class="fa-solid fa-clock" aria-hidden="true"></i>{{ $overallStatus }}</span>
    </section>

    <section class="compliance-policy-grid" aria-label="Policies and statements">
        <a href="{{ route('legal.privacy') }}" class="compliance-policy-card">
            <span class="compliance-policy-icon privacy"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
            <span><b>Privacy Policy</b><small>Personal data, purposes, access, retention, rights, and DPO contact.</small></span>
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
        <a href="{{ route('legal.terms') }}" class="compliance-policy-card">
            <span class="compliance-policy-icon terms"><i class="fa-solid fa-file-contract" aria-hidden="true"></i></span>
            <span><b>Terms and Conditions</b><small>Authorized use, confidentiality, records, uploads, and user responsibilities.</small></span>
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
        <a href="{{ route('legal.cookies') }}" class="compliance-policy-card">
            <span class="compliance-policy-icon cookies"><i class="fa-solid fa-cookie-bite" aria-hidden="true"></i></span>
            <span><b>Cookie Policy</b><small>Necessary session cookies, remember-me behavior, and consent assessment.</small></span>
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
        <a href="{{ route('legal.accessibility') }}" class="compliance-policy-card">
            <span class="compliance-policy-icon accessibility"><i class="fa-solid fa-universal-access" aria-hidden="true"></i></span>
            <span><b>Accessibility Statement</b><small>Implemented measures, known limitations, feedback, and testing approach.</small></span>
            <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
    </section>

    <div class="compliance-layout">
        <div class="compliance-main-column">
            <section class="panel compliance-section">
                <div class="panel-head"><div><span class="eyebrow">CURRENT ASSESSMENT</span><h2>What PATH currently does</h2></div><span class="compliance-as-of">Reviewed Sep 8, 2026</span></div>
                <div class="compliance-assessment-grid">
                    <article><span class="compliance-result good"><i class="fa-solid fa-check" aria-hidden="true"></i>No optional-cookie banner needed</span><h3>Cookies</h3><p>Only authentication/security cookies and an optional user-requested remember-me token were found.</p></article>
                    <article><span class="compliance-result good"><i class="fa-solid fa-check" aria-hidden="true"></i>No behavioral tracker found</span><h3>Analytics</h3><p>The Analytics module summarizes proposal records; it does not profile user browsing or serve advertising.</p></article>
                    <article><span class="compliance-result attention"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>External services present</span><h3>Third parties</h3><p>Fonts, icons, chart/map libraries, OpenStreetMap tiles, and Nominatim remain external-service and supply-chain considerations.</p></article>
                    <article><span class="compliance-result attention"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>Formal audit pending</span><h3>Accessibility</h3><p>Keyboard and semantic improvements are implemented, but complete WCAG testing and independent verification are still required.</p></article>
                    <article><span class="compliance-result good"><i class="fa-solid fa-check" aria-hidden="true"></i>No embeds found</span><h3>Embedded content</h3><p>No iframe, social-media, video, or advertising embed was found in the reviewed PATH views.</p></article>
                    <article><span class="compliance-result attention"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>Proof required</span><h3>Copyright assets</h3><p>The PATH logo is the only local image found. Written ownership or agency authorization should be retained.</p></article>
                </div>
            </section>

            <section class="panel compliance-section">
                <div class="panel-head"><div><span class="eyebrow">IMPLEMENTED CONTROLS</span><h2>Technical safeguards now present</h2></div></div>
                <ul class="compliance-control-list">
                    <li><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span><b>Access protection</b><small>Authentication, role/data scopes, CSRF protection, password hashing, and login rate limiting.</small></span></li>
                    <li><i class="fa-solid fa-lock" aria-hidden="true"></i><span><b>Browser and session protection</b><small>Encrypted session payloads, CSP, clickjacking protection, referrer controls, and private-cache restrictions.</small></span></li>
                    <li><i class="fa-solid fa-file-shield" aria-hidden="true"></i><span><b>Upload and download protection</b><small>Authenticated downloads, size limits, and an allowlist of common document, image, and archive formats.</small></span></li>
                    <li><i class="fa-solid fa-keyboard" aria-hidden="true"></i><span><b>Accessibility baseline</b><small>Skip navigation, visible keyboard focus, meaningful image text, form labels, reduced-motion support, and clearer icon actions.</small></span></li>
                </ul>
            </section>

            <section class="panel compliance-section">
                <div class="panel-head"><div><span class="eyebrow">LEGAL FRAMEWORK</span><h2>Primary references for review</h2></div></div>
                <div class="compliance-law-list">
                    <a href="https://privacy.gov.ph/implementing-rules-regulations-data-privacy-act-2012/" target="_blank" rel="noopener noreferrer"><b>Data Privacy Act of 2012 and IRR</b><small>Transparency, lawful processing, rights, security, and accountability.</small></a>
                    <a href="https://privacy.gov.ph/npc-issues-circulars-to-strengthen-personal-data-protection-in-ph/" target="_blank" rel="noopener noreferrer"><b>NPC Circular No. 2023-06</b><small>Current personal-data security requirements for government and private sectors.</small></a>
                    <a href="https://ncda.gov.ph/memorandum-circular-no-2017-004-prescribing-the-philippine-web-accessibility-policy-and-adopting-for-this-purpose-iso-iec-405002012-information-technology-w3c-web-content-accessibility-gu/" target="_blank" rel="noopener noreferrer"><b>Philippine Web Accessibility Policy</b><small>Government accessibility policy adopting WCAG 2.0 / ISO/IEC 40500.</small></a>
                    <a href="https://lawphil.net/statutes/repacts/ra2007/ra_9470_2007.html" target="_blank" rel="noopener noreferrer"><b>National Archives Act</b><small>Government-record retention, disposition schedules, and authorized disposal.</small></a>
                    <a href="https://lawphil.net/statutes/repacts/ra2026/irr_12254_2026.html" target="_blank" rel="noopener noreferrer"><b>E-Governance Act IRR</b><small>Government digital-service security, privacy, accessibility, and resilience.</small></a>
                </div>
            </section>
        </div>

        <aside class="compliance-side-column">
            <section class="panel compliance-section compliance-approval-card">
                <span class="eyebrow">REQUIRED SIGN-OFF</span><h2>Not completed by code alone</h2>
                <ul class="compliance-requirement-list">
                    @foreach($requirements as $requirement)
                    <li class="compliance-requirement-row">
                        <span class="compliance-requirement-copy">
                            <strong>{{ $requirement->title }}</strong>
                            <small>
                                {{ $requirement->responsible_office ?: 'No responsible office yet' }}
                                @if($requirement->evidence_count ?? $requirement->evidence->count()) · {{ $requirement->evidence_count ?? $requirement->evidence->count() }} evidence {{ ($requirement->evidence_count ?? $requirement->evidence->count()) === 1 ? 'file' : 'files' }}@endif
                            </small>
                        </span>
                        <span class="compliance-requirement-actions">
                            <span class="compliance-status {{ str_replace('_', '-', $requirement->status) }}">{{ $requirement->statusLabel() }}</span>
                            @if(auth()->user()->isAdmin())
                            <button type="button" class="compliance-edit-button" aria-label="Update {{ $requirement->title }}"
                                data-compliance-edit
                                data-id="{{ $requirement->id }}"
                                data-action="{{ route('compliance.requirements.update', $requirement) }}"
                                data-title="{{ $requirement->title }}"
                                data-description="{{ $requirement->description }}"
                                data-status="{{ $requirement->status }}"
                                data-office="{{ $requirement->responsible_office }}"
                                data-reviewed="{{ $requirement->reviewed_at?->format('Y-m-d') }}"
                                data-approved="{{ $requirement->approved_at?->format('Y-m-d') }}"
                                data-remarks="{{ $requirement->remarks }}"
                                data-evidence="{{ $requirement->evidence->count() }}">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                            </button>
                            @endif
                        </span>
                    </li>
                    @endforeach
                </ul>
            </section>

            <section class="panel compliance-section compliance-contact-card">
                <span class="eyebrow">PRIVACY CONTACT</span>
                <h2>{{ config('compliance.dpo_name') }}</h2>
                <p>Data Protection Officer<br>{{ config('compliance.organization') }}</p>
                <a href="mailto:{{ config('compliance.privacy_email') }}">{{ config('compliance.privacy_email') }}</a>
                <small>{{ config('compliance.office_address') }}</small>
            </section>

            @if(auth()->user()->isAdmin())
            <section class="panel compliance-section compliance-admin-card">
                <span class="eyebrow">ADMINISTRATOR</span><h2>Internal risk register</h2>
                <p>Review production blockers, technical risks, applicable laws, and the required sign-off checklist.</p>
                <a class="btn primary wide" href="{{ route('compliance.risks') }}"><i class="fa-solid fa-list-check" aria-hidden="true"></i> View risk register</a>
            </section>
            @endif
        </aside>
    </div>
</div>

@if(auth()->user()->isAdmin())
@php
    $oldComplianceValues = [
        'status' => old('status'),
        'office' => old('responsible_office'),
        'reviewed' => old('reviewed_at'),
        'approved' => old('approved_at'),
        'remarks' => old('remarks'),
    ];
@endphp
<div class="compliance-modal" id="complianceRequirementModal" aria-hidden="true">
    <div class="compliance-modal-backdrop" data-compliance-close></div>
    <section class="compliance-modal-card" role="dialog" aria-modal="true" aria-labelledby="complianceModalTitle">
        <div class="compliance-modal-head">
            <div><span class="eyebrow">COMPLIANCE WORKFLOW</span><h2 id="complianceModalTitle">Update requirement</h2><p id="complianceModalDescription"></p></div>
            <button type="button" class="compliance-modal-close" data-compliance-close aria-label="Close compliance requirement dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="complianceRequirementForm" class="compliance-update-form">
            @csrf @method('PUT')
            <input type="hidden" id="complianceRequirementId" name="requirement_id">
            <div class="compliance-form-grid">
                <label>Status
                    <select name="status" id="complianceStatus" required>
                        <option value="pending">Pending</option><option value="under_review">Under Review</option><option value="approved">Approved</option><option value="rejected">Rejected</option>
                    </select>
                </label>
                <label>Responsible office or approver
                    <input name="responsible_office" id="complianceOffice" maxlength="255" placeholder="e.g. DPO, Legal Unit, ICT Unit">
                </label>
                <label>Review date<input type="date" name="reviewed_at" id="complianceReviewed"></label>
                <label>Approval date<input type="date" name="approved_at" id="complianceApproved"></label>
                <label class="span-2">Remarks<textarea name="remarks" id="complianceRemarks" maxlength="5000" placeholder="Record the decision, remaining work, or conditions."></textarea></label>
                <label class="span-2">Add supporting evidence
                    <input type="file" name="evidence" id="complianceEvidence" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.webp">
                    <small id="complianceEvidenceHelp">PDF, Office document, CSV, text, or image; maximum 10 MB.</small>
                </label>
            </div>
            <div class="compliance-approval-rule"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Approval requires a responsible office, review date, approval date, and at least one evidence file. Existing evidence remains part of the audit record.</span></div>
            @if($errors->any())
            <div class="alert danger compliance-form-errors" role="alert"><b>Could not update the requirement.</b><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <div class="compliance-modal-actions"><button type="button" class="btn" data-compliance-close>Cancel</button><button class="btn primary" type="submit">Save compliance update</button></div>
        </form>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('complianceRequirementModal');
    const form = document.getElementById('complianceRequirementForm');
    const fields = {
        id: document.getElementById('complianceRequirementId'), status: document.getElementById('complianceStatus'),
        office: document.getElementById('complianceOffice'), reviewed: document.getElementById('complianceReviewed'),
        approved: document.getElementById('complianceApproved'), remarks: document.getElementById('complianceRemarks')
    };
    const setOpen = open => { modal.classList.toggle('is-open', open); modal.setAttribute('aria-hidden', open ? 'false' : 'true'); document.body.style.overflow = open ? 'hidden' : ''; if (open) fields.status.focus(); };
    const load = (button, oldValues = null) => {
        form.action = button.dataset.action;
        fields.id.value = button.dataset.id;
        document.getElementById('complianceModalTitle').textContent = button.dataset.title;
        document.getElementById('complianceModalDescription').textContent = button.dataset.description;
        fields.status.value = oldValues?.status ?? button.dataset.status;
        fields.office.value = oldValues?.office ?? button.dataset.office ?? '';
        fields.reviewed.value = oldValues?.reviewed ?? button.dataset.reviewed ?? '';
        fields.approved.value = oldValues?.approved ?? button.dataset.approved ?? '';
        fields.remarks.value = oldValues?.remarks ?? button.dataset.remarks ?? '';
        const count = Number(button.dataset.evidence || 0);
        document.getElementById('complianceEvidenceHelp').textContent = count
            ? `${count} evidence file${count === 1 ? '' : 's'} already on record. Add another file if needed.`
            : 'No evidence on record. PDF, Office document, CSV, text, or image; maximum 10 MB.';
        setOpen(true);
    };
    document.querySelectorAll('[data-compliance-edit]').forEach(button => button.addEventListener('click', () => load(button)));
    modal.querySelectorAll('[data-compliance-close]').forEach(button => button.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && modal.classList.contains('is-open')) setOpen(false); });

    @if($errors->any() && old('requirement_id'))
    const failedButton = document.querySelector('[data-compliance-edit][data-id="{{ old('requirement_id') }}"]');
    if (failedButton) load(failedButton, {{ Illuminate\Support\Js::from($oldComplianceValues) }});
    @endif
});
</script>
@endif
@endsection
