@extends('layouts.app')
@section('title',$proposal->project_title)
@section('page-title', auth()->user()->isEvaluator() ? 'Evaluator Review' : 'Proposal Record')

@section('top-back')
    @php
        $headerIsEvaluator = auth()->user()->isEvaluator();
        $headerFromFunded = !$headerIsEvaluator
            && $proposal->isFunded()
            && auth()->user()->hasPermission('funded.view')
            && (request('from') === 'funded' || !auth()->user()->hasPermission('proposals.view'));
        $headerBackUrl = $headerIsEvaluator
            ? route('evaluator.proposals.index')
            : ($headerFromFunded
                ? route('funded.index')
                : (auth()->user()->hasPermission('proposals.view') ? route('proposals.index') : route(auth()->user()->homeRouteName())));
        $headerBackLabel = $headerIsEvaluator
            ? 'Back to my assigned proposals'
            : ($headerFromFunded ? 'Back to funded proposals' : 'Back to proposals');
    @endphp
    <a class="back topbar-back" href="{{ $headerBackUrl }}">← {{ $headerBackLabel }}</a>
@endsection

@section('top-actions')
    @if($proposal->date_funded && auth()->user()->hasPermission('liquidation.view'))
        <a class="btn" href="{{ route('proposals.liquidation.index',$proposal) }}"><i class="fa-solid fa-boxes-stacked"></i> Acquisition & Liquidation</a>
    @endif
    @if(auth()->user()->hasPermission('rental_records.view'))
        <a class="btn" href="{{ route('rental-records.index',$proposal) }}"><i class="fa-solid fa-receipt"></i> Rental Records</a>
    @endif
    @if(!auth()->user()->isEvaluator() && auth()->user()->hasPermission('proposals.edit'))
        <a class="btn" href="{{ route('proposals.edit',$proposal) }}">Edit record</a>
    @endif
@endsection

@section('content')
@if($proposal->project_code)<div class="summary-pill"><span>Project Code</span><b>{{ $proposal->project_code }}</b></div>@endif
@php
    $isEvaluator = auth()->user()->isEvaluator();
    $fromFunded = !$isEvaluator
        && $proposal->isFunded()
        && auth()->user()->hasPermission('funded.view')
        && (request('from') === 'funded' || !auth()->user()->hasPermission('proposals.view'));
    $backUrl = $isEvaluator
        ? route('evaluator.proposals.index')
        : ($fromFunded
            ? route('funded.index')
            : (auth()->user()->hasPermission('proposals.view') ? route('proposals.index') : route(auth()->user()->homeRouteName())));
    $currentEvaluator = $isEvaluator
        ? ($evaluator ?? $proposal->evaluators->firstWhere('email', auth()->user()->email))
        : null;
    $currentEvaluatorStatus = $currentEvaluator
        ? $proposal->evaluators->firstWhere('id', $currentEvaluator->id)?->pivot?->evaluation_status
        : null;
    $visibleEvaluatorStatuses = $isEvaluator && $currentEvaluator
        ? $proposal->evaluators->where('id', $currentEvaluator->id)
        : $proposal->evaluators;
    $canManageEvaluatorStatus = !$isEvaluator && auth()->user()->hasPermission('proposals.manage_evaluator_status');

    // Newer records keep per-document states by Required Document ID, allowing
    // imported historical records to distinguish Submitted, Lacking, N/A and
    // Pending. The legacy document-review snapshot remains as a fallback.
    $latestDocumentReview = $proposal->missingDocuments->sortByDesc('created_at')->first();
    $latestLackingDocuments = collect(preg_split('/\r\n|\r|\n/', $latestDocumentReview?->description ?? ''))
        ->map(fn ($name) => trim($name))
        ->filter()
        ->reject(fn ($name) => strcasecmp($name, 'No lacking documents') === 0)
        ->values();
    $storedDocumentStates = $proposal->documentStatuses->keyBy('required_document_id');
    $requiredChecklistItems = collect($requiredDocumentRecords ?? [])->map(function ($requiredDocument) use ($latestDocumentReview, $latestLackingDocuments, $storedDocumentStates, $proposal) {
        $stored = $storedDocumentStates->get($requiredDocument->id)?->status;

        if ($stored) {
            $state = match ($stored) {
                'Submitted' => 'submitted',
                'Lacking' => 'lacking',
                'Not Applicable' => 'na',
                default => 'pending',
            };
        } else {
            $changedAfterReview = $latestDocumentReview
                && $requiredDocument->updated_at
                && $requiredDocument->updated_at->gt($latestDocumentReview->created_at);

            if ($proposal->document_status === 'Complete' && ! $changedAfterReview) {
                $state = 'submitted';
            } elseif (! $latestDocumentReview || $changedAfterReview) {
                $state = 'pending';
            } elseif ($latestLackingDocuments->contains($requiredDocument->name)) {
                $state = 'lacking';
            } else {
                $state = 'submitted';
            }
        }

        return [
            'document' => $requiredDocument,
            'state' => $state,
            'label' => match ($state) {
                'submitted' => 'Submitted',
                'lacking' => 'Lacking',
                'na' => 'Not applicable',
                default => 'Pending review',
            },
        ];
    })->sortBy(fn ($item) => match ($item['state']) {
        'lacking' => 0,
        'pending' => 1,
        'na' => 2,
        default => 3,
    })->values();
    $requiredChecklistTotal = $requiredChecklistItems->count();
    $requiredChecklistSubmitted = $requiredChecklistItems->where('state', 'submitted')->count();
    $requiredChecklistLacking = $requiredChecklistItems->where('state', 'lacking')->count();
    $requiredChecklistPending = $requiredChecklistItems->where('state', 'pending')->count();
    $requiredChecklistNa = $requiredChecklistItems->where('state', 'na')->count();
@endphp


@php
    $proposalCodePendingReason = null;
    if (!$proposal->proposal_code && ($proposal->date_approved || $proposal->industry_sector_id)) {
        $proposalCodePendingReason = !$proposal->date_approved
            ? 'add Approved Date'
            : (!$proposal->industry_sector_id
                ? 'select an Industry Sector'
                : (!in_array($proposal->status, ['Approved', 'Funded'], true)
                    ? 'set Proposal Status to Approved'
                    : 'verify the Province Code'));
    }

    $hasProposalContactDetails = filled($proposal->address)
        || filled($proposal->contact_person)
        || filled($proposal->contact_phone)
        || filled($proposal->contact_email);
@endphp

<div class="record-hero">
    <div class="record-hero-main">
        <div class="record-kicker">
            <span class="sector-badge {{ strtolower($proposal->sector) }}">{{ $proposal->sector }}</span>
            <span>{{ $proposal->province }}</span>
            <span>Q{{ $proposal->quarter_submitted }} {{ $proposal->date_submitted?->year }}</span>
            @if($proposal->proposal_code)
                <span class="proposal-code-chip"><i class="fa-solid fa-barcode"></i> {{ $proposal->proposal_code }}</span>
            @elseif($proposalCodePendingReason)
                <span class="proposal-code-pending"><i class="fa-regular fa-clock"></i> Proposal Code pending: {{ $proposalCodePendingReason }}.</span>
            @endif
            @if($proposal->industrySector)<span title="{{ $proposal->industrySector->name }}">{{ $proposal->industrySector->code }} industry</span>@endif
            @if($isEvaluator && $currentEvaluatorStatus)
                <span class="evaluator-review-chip eval-status-{{ strtolower($currentEvaluatorStatus) }}">My evaluation: {{ $currentEvaluatorStatus }}</span>
            @endif
        </div>
        <h1>{{ $proposal->project_title }}</h1>
        <p class="record-proponent">{{ $proposal->proponent }}</p>

        @if($hasProposalContactDetails)
            <div class="proposal-contact-strip" aria-label="Proposal contact information">
                @if($proposal->address)
                    <div class="proposal-contact-item proposal-contact-address">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        <span><small>Address</small><b>{{ $proposal->address }}</b></span>
                    </div>
                @endif
                @if($proposal->contact_person)
                    <div class="proposal-contact-item">
                        <i class="fa-solid fa-user" aria-hidden="true"></i>
                        <span><small>Contact Person</small><b>{{ $proposal->contact_person }}</b></span>
                    </div>
                @endif
                @if($proposal->contact_phone)
                    <div class="proposal-contact-item">
                        <i class="fa-solid fa-phone" aria-hidden="true"></i>
                        <span><small>Phone</small><b><a href="tel:{{ preg_replace('/[^0-9+]/', '', $proposal->contact_phone) }}">{{ $proposal->contact_phone }}</a></b></span>
                    </div>
                @endif
                @if($proposal->contact_email)
                    <div class="proposal-contact-item">
                        <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                        <span><small>Email</small><b><a href="mailto:{{ $proposal->contact_email }}">{{ $proposal->contact_email }}</a></b></span>
                    </div>
                @endif
            </div>
        @endif
    </div>
    <div class="record-amount">
        <small>Proposal amount</small>
        <b class="project-money">₱{{ number_format($proposal->amount,2) }}</b>
        <x-status :value="$proposal->status" />
    </div>
</div>

@if($isEvaluator)
<section class="panel evaluator-review-banner">
    <div>
        <span class="eyebrow">READ-ONLY EVALUATION ACCESS</span>
        <h2>Your evaluator workspace</h2>
        <p>You may review this proposal, update your evaluation status, add discussion comments, and record Forward/Return remarks. Proposal record fields cannot be edited from an Evaluator account.</p>
    </div>
    @if(auth()->user()->hasPermission('evaluator_portal.update_status'))
    <form method="POST" action="{{ route('evaluator.proposals.status',$proposal) }}" class="evaluator-status-inline">
        @csrf
        @method('PATCH')
        <label>My evaluation status
            <select class="evaluator-status-field eval-status-{{ strtolower($currentEvaluatorStatus ?: 'Assigned') }}" name="evaluation_status" onchange="this.form.submit()">
                @foreach(['Started','Ongoing','Approved'] as $status)
                    <option value="{{ $status }}" @selected($currentEvaluatorStatus === $status)>{{ $status }}</option>
                @endforeach
                @if($currentEvaluatorStatus === 'Assigned')
                    <option value="" selected disabled>Assigned · select status</option>
                @endif
            </select>
        </label>
    </form>
    @else
        <span class="evaluator-state eval-status-{{ strtolower($currentEvaluatorStatus ?: 'Assigned') }}"><i></i>{{ $currentEvaluatorStatus ?: 'Assigned' }}</span>
    @endif
</section>
@endif

<div class="record-grid">
    <div class="record-main">
        <section class="panel notification-target" id="proposal-details">
            <div class="panel-head">
                <div><span class="eyebrow">PROPOSAL PROFILE</span><h2>Record details</h2></div>
                @if($proposal->proposal_link)
                    <a target="_blank" rel="noopener" href="{{ $proposal->proposal_link }}">Open proposal ↗</a>
                @endif
            </div>
            <dl class="detail-grid notification-target" id="proposal-details-grid">
                <div><dt>Document status</dt><dd><x-status :value="$proposal->document_status" /></dd></div>
                <div data-record-inline-edit>
                    <dt>Proposal status</dt>
                    <dd class="record-status">
                        <x-status :value="$proposal->status" />
                        @if(!$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                            <button type="button" class="status-edit" data-record-inline-toggle title="Edit Proposal status" aria-label="Edit Proposal status"><i class="fa-solid fa-pen"></i></button>
                        @endif
                    </dd>
                    @if(!$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                        <form class="status-edit-form" method="POST" action="{{ route('proposals.summary', $proposal) }}" data-record-inline-form data-document-incomplete="{{ ($requiredChecklistLacking + $requiredChecklistPending) > 0 ? 'true' : 'false' }}" hidden>
                            @csrf @method('PATCH')
                            <input type="hidden" name="field" value="status">
                            <select name="value" aria-label="Proposal status" required>
                                @foreach(\App\Enums\ProposalStatus::values() as $status)
                                    <option value="{{ $status }}" @selected($proposal->status === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <button class="status-action confirm" type="submit" title="Save" aria-label="Save"><i class="fa-solid fa-check"></i></button>
                            <button class="status-action cancel" type="button" data-record-inline-cancel title="Cancel" aria-label="Cancel"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    @endif
                </div>
                <div data-record-inline-edit>
                    <dt>Funded Project Status</dt>
                    <dd class="record-status"><x-status :value="$proposal->funded_project_status ?: 'Not funded'" />
                    @if($proposal->date_funded && !$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                        <button type="button" class="status-edit" data-record-inline-toggle title="Edit Funded Project Status" aria-label="Edit Funded Project Status"><i class="fa-solid fa-pen"></i></button>
                    @endif
                    </dd>
                    @if($proposal->date_funded && !$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                    <form class="status-edit-form" method="POST" action="{{ route('proposals.summary', $proposal) }}" data-record-inline-form hidden>
                        @csrf @method('PATCH')<input type="hidden" name="field" value="funded_project_status">
                        <select name="value" aria-label="Funded Project Status" required>
                            @foreach(\App\Enums\FundedProjectStatus::values() as $status)<option @selected($proposal->funded_project_status === $status)>{{ $status }}</option>@endforeach
                        </select>
                        <button class="status-action confirm" type="submit" aria-label="Save"><i class="fa-solid fa-check"></i></button>
                        <button class="status-action cancel" type="button" data-record-inline-cancel aria-label="Cancel"><i class="fa-solid fa-xmark"></i></button>
                    </form>
                    @endif
                </div>
                <div><dt>Date submitted</dt><dd>{{ $proposal->date_submitted?->format('F d, Y') }}</dd></div>
                <div><dt>Last updated</dt><dd>{{ $proposal->date_updated?->format('F d, Y') ?: '—' }}</dd></div>
                <div data-record-inline-edit>
                    <dt>MOA status</dt>
                    <dd class="record-status">
                        <span>{{ $proposal->moa_status ?: 'Pending' }}</span>
                        @if(!$isEvaluator && auth()->user()->hasPermission('proposals.workflow'))
                            <button type="button" class="status-edit" data-record-inline-toggle title="Edit MOA status" aria-label="Edit MOA status"><i class="fa-solid fa-pen"></i></button>
                        @endif
                    </dd>
                    @if(!$isEvaluator && auth()->user()->hasPermission('proposals.workflow'))
                        <form class="status-edit-form" method="POST" action="{{ route('proposals.moa-status', $proposal) }}" data-record-inline-form hidden>
                            @csrf @method('PATCH')
                            <select name="moa_status" aria-label="MOA status" required>
                                @foreach(['Pending', 'Forwarded to PSTO for MOA signing', 'Returned to RPMO for signing', 'For Notarization', 'Forwarded to accounting for obligation', 'Completed'] as $status)
                                    <option value="{{ $status }}" @selected(($proposal->moa_status ?: 'Pending') === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <button class="status-action confirm" type="submit" title="Save" aria-label="Save"><i class="fa-solid fa-check"></i></button>
                            <button class="status-action cancel" type="button" data-record-inline-cancel title="Cancel" aria-label="Cancel"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    @endif
                    <small>Last updated: {{ $proposal->moa_status_updated_at?->format('M d, Y g:i A') ?: 'Not updated' }}</small>
                </div>
                <div data-record-inline-edit>
                    <dt>Approved</dt>
                    <dd class="record-status">
                        <span>{{ $proposal->date_approved?->format('F d, Y') ?: '—' }}</span>
                        @if(!$proposal->proposal_code && !$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                            <button type="button" class="status-edit" data-record-inline-toggle title="Edit approval date" aria-label="Edit approval date"><i class="fa-solid fa-pen"></i></button>
                        @endif
                    </dd>
                    @if(!$proposal->proposal_code && !$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                        <form class="status-edit-form" method="POST" action="{{ route('proposals.summary', $proposal) }}" data-record-inline-form hidden>
                            @csrf @method('PATCH')
                            <input type="hidden" name="field" value="date_approved">
                            <input type="date" name="value" value="{{ $proposal->date_approved?->format('Y-m-d') }}" aria-label="Approval date">
                            <button class="status-action confirm" type="submit" title="Save" aria-label="Save"><i class="fa-solid fa-check"></i></button>
                            <button class="status-action cancel" type="button" data-record-inline-cancel title="Cancel" aria-label="Cancel"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    @endif
                </div>
                <div data-record-inline-edit>
                    <dt>Funded</dt>
                    <dd class="record-status">
                        <span>{{ $proposal->date_funded?->format('F d, Y') ?: '—' }}</span>
                        @if(!$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                            <button type="button" class="status-edit" data-record-inline-toggle title="Edit funding date" aria-label="Edit funding date"><i class="fa-solid fa-pen"></i></button>
                        @endif
                    </dd>
                    @if(!$isEvaluator && auth()->user()->hasPermission('proposals.edit'))
                        <form class="status-edit-form" method="POST" action="{{ route('proposals.summary', $proposal) }}" data-record-inline-form hidden>
                            @csrf @method('PATCH')
                            <input type="hidden" name="field" value="date_funded">
                            <input type="date" name="value" value="{{ $proposal->date_funded?->format('Y-m-d') }}" aria-label="Funding date">
                            <button class="status-action confirm" type="submit" title="Save" aria-label="Save"><i class="fa-solid fa-check"></i></button>
                            <button class="status-action cancel" type="button" data-record-inline-cancel title="Cancel" aria-label="Cancel"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    @endif
                </div>
                <div><dt>Created by</dt><dd>{{ $proposal->owner->full_name }}</dd></div>
                <div><dt>Funding processing</dt><dd><x-status :value="$proposal->funding_status" /></dd></div>
                <div data-record-inline-edit>
                    <dt>Check status</dt>
                    <dd class="record-status">
                        <span>{{ $proposal->check_status ?: 'Pending' }}</span>
                        @if(!$isEvaluator && auth()->user()->hasPermission('proposals.workflow'))
                            <button type="button" class="status-edit" data-record-inline-toggle title="Edit check status" aria-label="Edit check status"><i class="fa-solid fa-pen"></i></button>
                        @endif
                    </dd>
                    @if(!$isEvaluator && auth()->user()->hasPermission('proposals.workflow'))
                        <form class="status-edit-form" method="POST" action="{{ route('proposals.check-status', $proposal) }}" data-record-inline-form hidden>
                            @csrf @method('PATCH')
                            <select name="check_status" aria-label="Check status" required>
                                @foreach(['Pending', 'Obligated', 'Check available for pick up', 'Completed'] as $status)
                                    <option value="{{ $status }}" @selected(($proposal->check_status ?: 'Pending') === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <button class="status-action confirm" type="submit" title="Save" aria-label="Save"><i class="fa-solid fa-check"></i></button>
                            <button class="status-action cancel" type="button" data-record-inline-cancel title="Cancel" aria-label="Cancel"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    @endif
                    <small>Last updated: {{ $proposal->check_status_updated_at?->format('M d, Y g:i A') ?: 'Not updated' }}</small>
                </div>
            </dl>
        </section>

        <section class="panel notification-target" id="document-review">
            <div class="panel-head">
                <div><span class="eyebrow">DOCUMENT REVIEW</span><h2>Lacking documents</h2></div>
                @if($proposal->document_status === 'Complete')
                    <span class="document-complete-state"><i class="fa-solid fa-circle-check"></i> Documents complete</span>
                @elseif(!$isEvaluator && $requiredChecklistTotal > 0 && auth()->user()->hasPermission('proposals.missing_evaluation'))
                    <form method="POST" action="{{ route('documents.complete', $proposal) }}" class="document-complete-form"
                          data-confirm-title="Complete required documents?"
                          data-confirm-message="This will mark every current required document as submitted, preserve Not Applicable items, and set the proposal's document status to Complete."
                          data-confirm-button="Complete Documents"
                          data-confirm-variant="success">
                        @csrf
                        <button type="submit" class="btn document-complete-btn"><i class="fa-solid fa-check-double"></i> Complete Documents</button>
                    </form>
                @endif
            </div>
            <div class="timeline notification-target" id="document-review-history">
                @forelse($proposal->missingDocuments->sortByDesc('created_at') as $m)
                    <article>
                        <span class="timeline-pin"></span>
                        <div>
                            <b>{{ $m->description }}</b>
                            <p>{{ $m->evaluation_status }}</p>
                            @if($m->moa_check)<small>MOA / Check: {{ $m->moa_check }}</small>@endif
                            <time>{{ $m->creator->full_name }} · {{ $m->created_at->format('M d, Y g:i A') }}</time>
                        </div>
                    </article>
                @empty
                    <p class="empty">No lacking-document entries recorded.</p>
                @endforelse
            </div>

            @if(!$isEvaluator && auth()->user()->hasPermission('proposals.missing_evaluation'))
                <details class="inline-form">
                    <summary>+ Add evaluation entry</summary>
                    <form method="POST" action="{{ route('missing.store',$proposal) }}" class="stack">
                        @csrf
                        <label>Lacking documents<textarea name="description" required></textarea></label>
                        <label>Evaluation status<textarea name="evaluation_status" required></textarea></label>
                        <label>MOA and check<textarea name="moa_check"></textarea></label>
                        <button class="btn primary">Save evaluation</button>
                    </form>
                </details>
            @endif

            <div class="evaluator-remarks-section notification-target" id="evaluator-remarks">
                <div class="subsection-head">
                    <div>
                        <span class="eyebrow">EVALUATOR REMARKS</span>
                        <h3>Forward & Return trail</h3>
                        <p>Each action is numbered automatically so repeated document returns and forwards remain traceable.</p>
                    </div>
                </div>

                @if($isEvaluator && $currentEvaluator && auth()->user()->hasPermission('evaluator_portal.remarks'))
                    <form method="POST" action="{{ route('evaluator.proposals.remarks',$proposal) }}" class="evaluator-remark-form">
                        @csrf
                        <div class="remark-action-grid">
                            <label>Action
                                <select name="action" required>
                                    <option value="Forward">Forward</option>
                                    <option value="Return">Return</option>
                                </select>
                            </label>
                            <label class="remark-text">Comment / remark
                                <textarea name="remark" rows="3" maxlength="3000" placeholder="State what is being forwarded, or explain what must be corrected/resubmitted…" required>{{ old('remark') }}</textarea>
                            </label>
                        </div>
                        @error('action')<small class="field-error">{{ $message }}</small>@enderror
                        @error('remark')<small class="field-error">{{ $message }}</small>@enderror
                        <div class="remark-form-actions">
                            <small>Returning a proposal automatically keeps your evaluation status at Ongoing.</small>
                            <button class="btn primary" type="submit">Record evaluator remark</button>
                        </div>
                    </form>
                @endif

                <div class="evaluator-remark-timeline">
                    @php $actionCounts = []; @endphp
                    @forelse($proposal->evaluatorRemarks->sortBy('created_at') as $remark)
                        @php
                            $key = $remark->evaluator_id.'|'.$remark->action;
                            $actionCounts[$key] = ($actionCounts[$key] ?? 0) + 1;
                            $sequence = $actionCounts[$key];
                        @endphp
                        <article class="evaluator-remark-item {{ strtolower($remark->action) }}">
                            <span class="remark-action">{{ $remark->action }} #{{ $sequence }}</span>
                            <div>
                                <div class="remark-meta">
                                    <b>{{ $remark->evaluator->full_name }}</b>
                                    <span>{{ $remark->evaluator->classification }} Evaluator</span>
                                    <time>{{ $remark->created_at->format('M d, Y g:i A') }}</time>
                                </div>
                                <p>{{ $remark->remark }}</p>
                            </div>
                        </article>
                    @empty
                        <p class="empty evaluator-remark-empty">No evaluator Forward/Return remarks have been recorded yet.</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="panel notification-target" id="proposal-discussion">
            <div class="panel-head"><div><span class="eyebrow">COLLABORATION</span><h2>Discussion</h2></div></div>
            @if(auth()->user()->hasPermission('proposals.comment'))
                <form method="POST" action="{{ route('comments.store',$proposal) }}" class="comment-form">
                    @csrf
                    <div class="comment-form-shell">
                        <span class="comment-field-label">Write a collaboration note</span>
                        <textarea name="content" rows="4" maxlength="3000" placeholder="Add a note, update, decision, or next step for this proposal…" required></textarea>
                        <div class="comment-form-meta">
                            <small>Use this space for updates, action items, clarifications, and coordination notes.</small>
                            <button class="btn primary" type="submit">Post comment</button>
                        </div>
                    </div>
                </form>
            @endif
            <div class="comments notification-target" id="proposal-discussion-updates">
                @forelse($proposal->comments->sortByDesc('created_at') as $c)
                    <article>
                        <div class="avatar small">{{ strtoupper(substr($c->user->first_name,0,1).substr($c->user->last_name,0,1)) }}</div>
                        <div class="comment-body">
                            <div class="comment-head">
                                <div class="comment-author">
                                    <b>{{ $c->user->full_name }}</b>
                                    <time>{{ $c->created_at->diffForHumans() }}</time>
                                </div>
                                @if((int) $c->user_id === (int) auth()->id() && auth()->user()->hasPermission('proposals.comment'))
                                    <form method="POST" action="{{ route('comments.destroy',$c) }}" class="comment-delete-form"
                                          data-confirm-title="Delete your comment?"
                                          data-confirm-message="This comment will be permanently removed. Any replies under this comment will also be removed."
                                          data-confirm-button="Delete comment">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="comment-delete-action" aria-label="Delete your comment">
                                            <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
                                            <span>Delete</span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                            <p>{{ $c->content }}</p>
                            @foreach($c->replies as $reply)
                                <div class="reply">
                                    <div class="reply-head">
                                        <div class="reply-author">
                                            <b>{{ $reply->user->full_name }}</b>
                                            <time>{{ $reply->created_at->diffForHumans() }}</time>
                                        </div>
                                        @if((int) $reply->user_id === (int) auth()->id() && auth()->user()->hasPermission('proposals.comment'))
                                            <form method="POST" action="{{ route('replies.destroy',$reply) }}" class="reply-delete-form"
                                                  data-confirm-title="Delete your reply?"
                                                  data-confirm-message="This reply will be permanently removed from the discussion."
                                                  data-confirm-button="Delete reply">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="reply-delete-action" aria-label="Delete your reply">
                                                    <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
                                                    <span>Delete</span>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                    <span class="reply-content">{{ $reply->content }}</span>
                                </div>
                            @endforeach
                            @if(auth()->user()->hasPermission('proposals.comment'))
                                <form method="POST" action="{{ route('replies.store',$c) }}" class="reply-form">
                                    @csrf
                                    <input name="content" placeholder="Reply…" required>
                                    <button>Send</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="empty">Start the discussion for this record.</p>
                @endforelse
            </div>
        </section>
    </div>

    <aside class="record-side">
        <section class="panel evaluator-status-panel notification-target" id="evaluator-status">
            <div class="panel-head evaluator-status-panel-head">
                <div>
                    <span class="eyebrow">{{ $isEvaluator ? 'MY EVALUATION' : 'EVALUATORS' }}</span>
                    <h2>{{ $isEvaluator ? 'Evaluator status' : 'Evaluators status' }}</h2>
                </div>
                <span class="evaluator-count">{{ $visibleEvaluatorStatuses->count() }}</span>
            </div>

            <div class="evaluator-status-cards notification-target" id="evaluator-assignments">
                @forelse($visibleEvaluatorStatuses as $assignedEvaluator)
                    @php
                        $evaluationStatus = $assignedEvaluator->pivot?->evaluation_status ?: 'Assigned';
                        $initials = strtoupper(substr($assignedEvaluator->first_name, 0, 1).substr($assignedEvaluator->last_name, 0, 1));
                    @endphp
                    <article class="evaluator-status-card notification-target" id="evaluator-status-{{ $assignedEvaluator->id }}">
                        <div class="evaluator-mini-avatar">{{ $initials }}</div>
                        <div class="evaluator-status-person">
                            <b>{{ $assignedEvaluator->full_name }}</b>
                            <small>{{ $assignedEvaluator->classification }} Evaluator{{ $assignedEvaluator->designation ? ' · '.$assignedEvaluator->designation : '' }}</small>
                        </div>
                        @if($canManageEvaluatorStatus)
                            <div class="evaluator-manual-status-editor" data-evaluator-status-edit>
                                <div class="evaluator-manual-status-display" data-evaluator-status-display>
                                    <span class="evaluator-state eval-status-{{ strtolower($evaluationStatus) }}">
                                        <i></i>{{ $evaluationStatus }}
                                    </span>
                                    <button type="button"
                                            class="evaluator-status-edit-trigger"
                                            data-evaluator-status-toggle
                                            title="Edit evaluator status"
                                            aria-label="Edit evaluator status for {{ $assignedEvaluator->full_name }}">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                    </button>
                                </div>
                                <form method="POST"
                                      action="{{ route('proposals.evaluators.status', [$proposal, $assignedEvaluator]) }}"
                                      class="evaluator-manual-status-form"
                                      data-evaluator-status-form
                                      data-confirm-title="Update evaluator status?"
                                      data-confirm-message="This will manually update {{ $assignedEvaluator->full_name }}'s evaluator status. The change and the user who made it will be recorded in the proposal Audit Trail."
                                      data-confirm-button="Update status"
                                      data-confirm-variant="success"
                                      data-document-incomplete="{{ ($requiredChecklistLacking + $requiredChecklistPending) > 0 ? 'true' : 'false' }}"
                                      hidden>
                                    @csrf
                                    @method('PATCH')
                                    <select name="evaluation_status"
                                            class="evaluator-manual-status-select eval-status-{{ strtolower($evaluationStatus) }}"
                                            aria-label="Evaluator status for {{ $assignedEvaluator->full_name }}"
                                            title="Manual evaluator status override">
                                        @foreach(['Assigned','Started','Ongoing','Approved'] as $status)
                                            <option value="{{ $status }}" @selected($evaluationStatus === $status)>{{ $status }}</option>
                                        @endforeach
                                    </select>
                                    <button class="evaluator-manual-status-save" type="submit" title="Save evaluator status" aria-label="Save evaluator status for {{ $assignedEvaluator->full_name }}">
                                        <i class="fa-solid fa-check" aria-hidden="true"></i>
                                    </button>
                                    <button class="evaluator-manual-status-cancel" type="button" data-evaluator-status-cancel title="Cancel" aria-label="Cancel evaluator status edit">
                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        @else
                            <span class="evaluator-state eval-status-{{ strtolower($evaluationStatus) }}">
                                <i></i>{{ $evaluationStatus }}
                            </span>
                        @endif
                    </article>
                @empty
                    <div class="evaluator-status-empty">
                        <i class="fa-solid fa-user-check"></i>
                        <p>No evaluator is assigned to this proposal yet.</p>
                    </div>
                @endforelse
            </div>

            @if($canManageEvaluatorStatus && $visibleEvaluatorStatuses->isNotEmpty())
                <p class="evaluator-status-override-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Authorized manual status override. Every change is recorded in the Audit Trail.</p>
            @endif

            @if(!$isEvaluator && auth()->user()->hasPermission('proposals.assign_evaluators'))
                <a class="evaluator-manage-link" href="{{ route('proposals.edit',$proposal) }}">Manage evaluator assignments <span>→</span></a>
            @endif
        </section>

        <section class="panel required-checklist-panel notification-target" id="documents-checklist">
            <div class="panel-head required-checklist-head">
                <div>
                    <span class="eyebrow">REQUIRED DOCUMENTS</span>
                    <h2>Documents checklist</h2>
                </div>
                <span class="required-checklist-count">{{ $requiredChecklistTotal }}</span>
            </div>

            @if($requiredChecklistTotal)
                <div class="required-checklist-summary">
                    <span class="submitted"><b>{{ $requiredChecklistSubmitted }}</b> submitted</span>
                    <span class="lacking"><b>{{ $requiredChecklistLacking }}</b> lacking</span>
                    <span class="pending"><b>{{ $requiredChecklistPending }}</b> pending</span>
                    @if($requiredChecklistNa)<span class="na"><b>{{ $requiredChecklistNa }}</b> N/A</span>@endif
                </div>
                <div class="required-checklist-list notification-target" id="documents-checklist-items">
                    @foreach($requiredChecklistItems as $item)
                        <div class="required-checklist-item state-{{ $item['state'] }}">
                            <span class="required-checklist-icon">
                                @if($item['state'] === 'submitted')
                                    <i class="fa-solid fa-check"></i>
                                @elseif($item['state'] === 'lacking')
                                    <i class="fa-solid fa-exclamation"></i>
                                @elseif($item['state'] === 'na')
                                    <i class="fa-solid fa-minus"></i>
                                @else
                                    <i class="fa-regular fa-clock"></i>
                                @endif
                            </span>
                            <div>
                                <b>{{ $item['document']->name }}</b>
                                <small>{{ $item['label'] }}</small>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if(!$latestDocumentReview && $proposal->document_status !== 'Complete')
                    <p class="required-checklist-note">No document-review snapshot has been recorded yet. Items remain pending until the proposal is evaluated.</p>
                @elseif($requiredChecklistPending)
                    <p class="required-checklist-note">Pending items were added or changed after the latest document review and should be checked again.</p>
                @endif
            @else
                <div class="required-checklist-empty">
                    <i class="fa-regular fa-folder-open"></i>
                    <p>No Required Documents are configured yet.</p>
                </div>
            @endif

            @if(!$isEvaluator && $requiredChecklistTotal)
                <div class="required-checklist-actions">
                    <a class="required-checklist-manage" href="{{ route('proposals.required-documents.checklist', $proposal) }}" target="_blank" rel="noopener">
                        <i class="fa-solid fa-file-lines" aria-hidden="true"></i> Generate checklist <span>→</span>
                    </a>
                    @if(auth()->user()->hasPermission('required_documents.view'))
                        <a class="required-checklist-manage" href="{{ route('required-documents.index') }}">Manage required documents <span>→</span></a>
                    @endif
                </div>
            @elseif(!$isEvaluator && auth()->user()->hasPermission('required_documents.view'))
                <a class="required-checklist-manage" href="{{ route('required-documents.index') }}">Manage required documents <span>→</span></a>
            @endif
        </section>

        <section class="panel audit-trail-panel notification-target" id="audit-trail">
            <div class="panel-head"><div><span class="eyebrow">{{ $isEvaluator ? 'MY HISTORY' : 'HISTORY' }}</span><h2>{{ $isEvaluator ? 'My audit trail' : 'Audit trail' }}</h2></div></div>
            <div class="audit-trail-scroll notification-target" id="audit-trail-entries">
                <div class="mini-timeline">
                    @forelse($proposal->activities->sortByDesc('created_at') as $a)
                        <div><i></i><b>{{ $a->action }}</b><small>{{ $isEvaluator ? 'You' : ($a->user?->full_name ?? 'System') }} · {{ $a->created_at->diffForHumans() }}</small></div>
                    @empty
                        <p class="empty">{{ $isEvaluator ? 'You have no audit entries for this proposal yet.' : 'No audit entries yet.' }}</p>
                    @endforelse
                </div>
            </div>
        </section>

        <section class="panel project-files-panel notification-target" id="project-files">
            <div class="panel-head"><div><span class="eyebrow">FILES</span><h2>Proposal documents</h2></div><span class="file-count">{{ $proposal->files->count() }}</span></div>
            @if(!$isEvaluator && auth()->user()->hasPermission('proposals.upload_files'))
                <form method="POST" action="{{ route('files.store',$proposal) }}" enctype="multipart/form-data" class="upload-box">
                    @csrf
                                <input type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.webp,.zip" required>
                    <small>PDF, Office files, images, and archives up to 10 MB.</small>
                    <button class="btn wide">Upload file</button>
                </form>
            @elseif(auth()->user()->hasPermission('proposals.download_files'))
                <p class="readonly-note"><i class="fa-solid fa-eye"></i> Files are available in read-only mode.</p>
            @endif
            <div class="file-list notification-target" id="project-files-list">
                @forelse($proposal->files->sortByDesc('created_at') as $f)
                    @if(auth()->user()->hasPermission('proposals.download_files'))
                        <a href="{{ route('files.download',$f) }}"><span>▱</span><div><b>{{ $f->name }}</b><small>{{ number_format($f->size/1024,0) }} KB · {{ $f->created_at->format('M d') }}</small></div></a>
                    @else
                        <div class="file-readonly-row"><span>▱</span><div><b>{{ $f->name }}</b><small>{{ number_format($f->size/1024,0) }} KB · {{ $f->created_at->format('M d') }}</small></div></div>
                    @endif
                @empty
                    <p class="empty">No proposal files uploaded.</p>
                @endforelse
            </div>
        </section>

        @if(!$isEvaluator && auth()->user()->hasPermission('proposals.archive'))
            <form method="POST" action="{{ route('proposals.destroy',$proposal) }}"
                  data-confirm-title="Move proposal to Archives?"
                  data-confirm-message="This proposal will be removed from active records and moved to Archives. Its evaluations, files, comments, and history will be preserved and can be restored later."
                  data-confirm-button="Move to Archives">
                @csrf @method('DELETE')
                <button class="btn danger wide"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Move to Archives</button>
            </form>
        @endif
    </aside>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-record-inline-edit]').forEach((item) => {
        const toggle = item.querySelector('[data-record-inline-toggle]');
        const form = item.querySelector('[data-record-inline-form]');
        const cancel = item.querySelector('[data-record-inline-cancel]');
        if (!toggle || !form) return;

        toggle.addEventListener('click', () => {
            form.hidden = false;
            form.querySelector('select, input:not([type="hidden"])')?.focus();
        });

        cancel?.addEventListener('click', () => {
            form.reset();
            form.hidden = true;
        });

        form.addEventListener('submit', () => {
            const status = form.querySelector('select[name="value"]')?.value;
            if (form.dataset.documentIncomplete === 'true' && ['Approved', 'Funded'].includes(status)) {
                form.dataset.confirmTitle = 'Approve with lacking documents?';
                form.dataset.confirmMessage = 'There are still required documents lacking or pending for this proposal. Are you sure you want to continue?';
                form.dataset.confirmButton = 'Continue approval';
                form.dataset.confirmVariant = 'success';
            } else {
                delete form.dataset.confirmTitle; delete form.dataset.confirmMessage;
                delete form.dataset.confirmButton; delete form.dataset.confirmVariant;
            }
        });
    });

    document.querySelectorAll('[data-evaluator-status-edit]').forEach((editor) => {
        const display = editor.querySelector('[data-evaluator-status-display]');
        const toggle = editor.querySelector('[data-evaluator-status-toggle]');
        const form = editor.querySelector('[data-evaluator-status-form]');
        const cancel = editor.querySelector('[data-evaluator-status-cancel]');
        if (!display || !toggle || !form) return;
        const normalTitle = form.dataset.confirmTitle;
        const normalMessage = form.dataset.confirmMessage;

        toggle.addEventListener('click', () => {
            display.hidden = true;
            form.hidden = false;
            form.querySelector('select')?.focus();
        });

        cancel?.addEventListener('click', () => {
            form.reset();
            form.hidden = true;
            display.hidden = false;
            toggle.focus();
        });
        form.addEventListener('submit', () => {
            const approving = form.querySelector('select[name="evaluation_status"]')?.value === 'Approved';
            form.dataset.confirmTitle = approving && form.dataset.documentIncomplete === 'true' ? 'Approve with lacking documents?' : normalTitle;
            form.dataset.confirmMessage = approving && form.dataset.documentIncomplete === 'true'
                ? 'There are still required documents lacking or pending for this proposal. Are you sure you want to approve this evaluator status?'
                : normalMessage;
            form.dataset.confirmButton = approving && form.dataset.documentIncomplete === 'true' ? 'Continue approval' : 'Update status';
        });
    });
});
</script>
@endpush
