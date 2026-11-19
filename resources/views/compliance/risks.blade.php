@extends('layouts.app')
@section('title', 'Compliance Risk Register')
@section('page-title', 'Compliance Risk Register')

@section('content')
<div class="compliance-page compliance-risk-page">
    <section class="compliance-hero compliance-risk-hero">
        <div>
            <span class="eyebrow light">ADMINISTRATOR ONLY</span>
            <h1>Compliance risk register</h1>
            <p>Operational follow-ups that require technical work, documented decisions, or formal approval.</p>
        </div>
        <a class="btn compliance-back-button" href="{{ route('compliance.index') }}">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Compliance Center
        </a>
    </section>

    <div class="compliance-risk-summary" aria-label="Risk summary">
        <article><span class="risk-count critical">{{ $requirements->whereIn('status', ['pending', 'rejected'])->count() }}</span><div><b>Action required</b><small>Pending or rejected requirements</small></div></article>
        <article><span class="risk-count high">{{ $requirements->where('status', 'under_review')->count() }}</span><div><b>Under review</b><small>Assigned work awaiting a decision</small></div></article>
        <article><span class="risk-count review">{{ $requirements->where('status', 'approved')->count() }}</span><div><b>Approved</b><small>Requirements with recorded evidence</small></div></article>
    </div>

    <section class="panel compliance-section">
        <div class="panel-head"><div><span class="eyebrow">LIVE REGISTER</span><h2>Current sign-off status and evidence</h2></div></div>
        <div class="compliance-register-table-wrap">
            <table class="compliance-register-table">
                <thead><tr><th>Requirement</th><th>Status</th><th>Responsible office</th><th>Dates</th><th>Evidence</th><th>Last updated</th></tr></thead>
                <tbody>
                @foreach($requirements as $requirement)
                    <tr>
                        <td><b>{{ $requirement->title }}</b><small>{{ $requirement->description }}</small></td>
                        <td><span class="compliance-status {{ str_replace('_', '-', $requirement->status) }}">{{ $requirement->statusLabel() }}</span></td>
                        <td>{{ $requirement->responsible_office ?: 'Not assigned' }}</td>
                        <td><small>Reviewed: {{ $requirement->reviewed_at?->format('M d, Y') ?: '—' }}</small><small>Approved: {{ $requirement->approved_at?->format('M d, Y') ?: '—' }}</small></td>
                        <td>
                            @forelse($requirement->evidence as $evidence)
                                <a class="compliance-evidence-link" href="{{ route('compliance.evidence.download', $evidence) }}"><i class="fa-solid fa-paperclip" aria-hidden="true"></i>{{ $evidence->name }}</a>
                            @empty
                                <small>No evidence</small>
                            @endforelse
                        </td>
                        <td><small>{{ $requirement->updatedBy?->full_name ?: 'Not updated' }}</small><small>{{ $requirement->updated_by ? $requirement->updated_at->format('M d, Y g:i A') : '—' }}</small></td>
                    </tr>
                    @if($requirement->remarks)
                    <tr class="compliance-register-remarks"><td colspan="6"><b>Remarks:</b> {{ $requirement->remarks }}</td></tr>
                    @endif
                @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel compliance-section">
        <div class="panel-head"><div><span class="eyebrow">AUDIT HISTORY</span><h2>Latest compliance changes</h2></div></div>
        <div class="compliance-history-list">
            @forelse($historyEntries as $entry)
            <article>
                <span class="compliance-history-icon"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                <div><b>{{ $entry->requirement->title }}</b><p>{{ $entry->action }} · {{ $entry->from_status ? ucwords(str_replace('_', ' ', $entry->from_status)) : 'New' }} → {{ ucwords(str_replace('_', ' ', $entry->to_status)) }}</p><small>{{ $entry->user?->full_name ?: 'Deleted user' }} · {{ $entry->created_at->format('M d, Y g:i A') }}</small></div>
            </article>
            @empty
            <p class="empty">No compliance updates have been recorded yet.</p>
            @endforelse
        </div>
    </section>

    <section class="panel compliance-section">
        <div class="panel-head">
            <div><span class="eyebrow">PRIORITY ACTIONS</span><h2>Items requiring an accountable owner and evidence</h2></div>
            <span class="compliance-as-of">Reviewed Sep 8, 2026</span>
        </div>
        <div class="compliance-risk-list">
            <article class="compliance-risk-item">
                <span class="risk-severity critical">Priority</span>
                <div><h3>Approve the policies and notices</h3><p>The published drafts must be checked against actual DOST VII processing practices and approved by the DPO and authorized legal reviewer.</p></div>
                <span class="risk-owner">DPO + Legal</span>
            </article>
            <article class="compliance-risk-item">
                <span class="risk-severity critical">Priority</span>
                <div><h3>Complete a Privacy Impact Assessment</h3><p>Document the personal data inventory, lawful purposes, data flows, recipients, risks, safeguards, retention, and incident handling before wider deployment.</p></div>
                <span class="risk-owner">DPO + System owner</span>
            </article>
            <article class="compliance-risk-item">
                <span class="risk-severity critical">Priority</span>
                <div><h3>Harden and test the production environment</h3><p>Verify HTTPS, production configuration, least privilege, logging, backups, recovery, patching, secrets management, and vulnerability remediation.</p></div>
                <span class="risk-owner">ICT administrator</span>
            </article>
            <article class="compliance-risk-item">
                <span class="risk-severity critical">Priority</span>
                <div><h3>Approve a records retention and disposal schedule</h3><p>Map proposals, evaluations, attachments, financial records, notifications, audit logs, and backups to approved retention and disposition rules.</p></div>
                <span class="risk-owner">Records officer</span>
            </article>
            <article class="compliance-risk-item">
                <span class="risk-severity critical">Priority</span>
                <div><h3>Confirm regulatory registration and public-portal requirements</h3><p>The DPO must determine applicable NPC registration obligations and reassess notices, consent, accessibility, security, and publication rules before a public portal launches.</p></div>
                <span class="risk-owner">DPO + Legal</span>
            </article>
        </div>
    </section>

    <section class="panel compliance-section">
        <div class="panel-head"><div><span class="eyebrow">IMPORTANT SAFEGUARDS</span><h2>Technical and operational work still to verify</h2></div></div>
        <div class="compliance-risk-grid">
            <article><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i><div><h3>Third-party services</h3><p>Inventory external fonts, icons, chart and map libraries, map tiles, and geocoding. Record terms, privacy behavior, availability, and approved fallbacks.</p></div></article>
            <article><i class="fa-solid fa-file-circle-check" aria-hidden="true"></i><div><h3>Uploaded-file safety</h3><p>Add malware scanning, quarantine or review procedures, and documented handling for suspicious files in addition to MIME and size checks.</p></div></article>
            <article><i class="fa-solid fa-user-lock" aria-hidden="true"></i><div><h3>Account assurance</h3><p>Consider multi-factor authentication for privileged users and formalize provisioning, periodic access review, lockout, and offboarding.</p></div></article>
            <article><i class="fa-solid fa-file-export" aria-hidden="true"></i><div><h3>Exports and sharing</h3><p>Review exported reports for unnecessary personal data and establish authorized sharing, storage, labeling, and deletion procedures.</p></div></article>
            <article><i class="fa-solid fa-universal-access" aria-hidden="true"></i><div><h3>Accessibility verification</h3><p>Run automated and manual keyboard, screen-reader, zoom, reflow, form-error, alternative-text, and color-contrast testing with users where possible.</p></div></article>
        </div>
    </section>

    <div class="compliance-risk-footer-grid">
        <section class="panel compliance-section">
            <span class="eyebrow">EVIDENCE CHECKLIST</span>
            <h2>Keep these records with the release</h2>
            <ul class="compliance-evidence-list">
                <li>Signed policy and notice approvals</li>
                <li>Privacy Impact Assessment and data inventory</li>
                <li>Security review and remediation report</li>
                <li>Accessibility test report and issue log</li>
                <li>Retention schedule and disposal authority</li>
                <li>Backup restore and incident-response exercises</li>
                <li>Third-party and copyright/license records</li>
            </ul>
        </section>
        <section class="panel compliance-section compliance-record-note">
            <span class="eyebrow">ENGINEERING RECORD</span>
            <h2>Detailed audit notes</h2>
            <p>The canonical technical assessment remains in the repository so changes can be reviewed and versioned with the application.</p>
            <code>docs/COMPLIANCE-RISK-REGISTER.md</code>
            <p class="compliance-caution"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> This register supports review; it is not legal advice, certification, or proof that every control is operating effectively.</p>
        </section>
    </div>
</div>
@endsection
