<?php

namespace App\Http\Controllers;

use App\Enums\{DocumentStatus, ProposalStatus, Sector};
use App\Http\Requests\ProposalRequest;
use App\Models\{Evaluator, IndustrySector, Proposal, Province, ProposalDocumentStatus, RequiredDocument};
use App\Services\{ActivityService, ProposalCodeGenerator};
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProposalController extends Controller
{
    public function __construct(private ActivityService $activity, private ProposalCodeGenerator $proposalCodes) {}

    public function index(Request $request)
    {

        $range = new \App\Support\DateRangeFilter($request);        $query = Proposal::query()
            ->visibleTo($request->user())
            ->whereNull('date_funded')->where('status', '!=', 'Funded');

        $range->apply($query, 'date_submitted');

        foreach (['sector', 'province', 'status', 'document_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }

        if ($request->filled('industry_sector_id')) {
            $query->where('industry_sector_id', $request->integer('industry_sector_id'));
        }

        if ($request->filled('year') && ! $range->active()) {
            $query->whereYear('date_submitted', $request->year);
        }

        if ($request->filled('quarter') && ! $range->active()) {
            $query->where('quarter_submitted', $request->quarter);
        }

        if ($request->filled('search')) {
            $search = '%'.$request->search.'%';
            $query->where(fn ($builder) => $builder
                ->where('project_title', 'like', $search)
                ->orWhere('proponent', 'like', $search)
                ->orWhere('proposal_code', 'like', $search));
        }

        $proposals = $query->latest()->paginate(8, [
            'id', 'proposal_code', 'project_title', 'proponent', 'sector', 'industry_sector_id', 'province', 'date_submitted',
            'quarter_submitted', 'document_status', 'status', 'amount', 'created_at',
        ])->withQueryString();

        return view('proposals.index', [
            'proposals' => $proposals,
            'sectors' => Sector::values(),
            'provinces' => Province::allValues(),
            'industrySectors' => IndustrySector::query()->ordered()->get(['id', 'code', 'name', 'is_active']),
            'statuses' => ProposalStatus::values(),
            'documentStatuses' => DocumentStatus::values(),
        ]);
    }

    private function formData(Proposal $proposal, Request $request): array
    {
        if ($proposal->exists) {
            $proposal->loadMissing(['evaluators', 'missingDocuments', 'documentStatuses']);
        }

        $requiredDocuments = RequiredDocument::query()->orderBy('id')->get();

        return [
            'proposal' => $proposal,
            'sectors' => Sector::values(),
            'provinces' => Province::selectionValues($proposal->province),
            'statuses' => $proposal->proposal_code ? ['Approved'] : ProposalStatus::values(),
            'documentStatuses' => DocumentStatus::values(),
            'industrySectors' => IndustrySector::query()
                ->where(function ($query) use ($proposal) {
                    $query->where('is_active', true);
                    if ($proposal->industry_sector_id) {
                        $query->orWhere('id', $proposal->industry_sector_id);
                    }
                })
                ->ordered()
                ->get(),
            'evaluators' => Evaluator::orderBy('last_name')->orderBy('first_name')->get(),
            'requiredDocuments' => $requiredDocuments,
            'selectedRequiredDocumentIds' => $proposal->exists
                ? $this->selectedRequiredDocumentIds($proposal, $requiredDocuments)
                : [],
            'storedRequiredDocumentStatuses' => $proposal->exists
                ? $proposal->documentStatuses->pluck('status', 'required_document_id')->all()
                : [],
            'canManageChecklist' => $request->user()->hasPermission('proposals.document_checklist'),
            'canAssignEvaluators' => $request->user()->hasPermission('proposals.assign_evaluators'),
        ];
    }

    public function create(Request $request)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts have read-only proposal record access.');
        if ($request->user()->dataScope() === 'province') {
            abort_unless(Province::isActiveValue($request->user()->province), 403, 'Your assigned province is inactive and cannot be used for new Region VII proposal records.');
        }

        return view('proposals.form', $this->formData(new Proposal, $request));
    }

    public function store(ProposalRequest $request)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot create proposal records.');
        if ($request->user()->dataScope() === 'province') {
            abort_unless(Province::isActiveValue($request->user()->province), 403, 'Your assigned province is inactive and cannot be used for new Region VII proposal records.');
        }

        $data = $this->withLocationMetadata($request->validated());
        $canAssignEvaluators = $request->user()->hasPermission('proposals.assign_evaluators');
        $canManageChecklist = $request->user()->hasPermission('proposals.document_checklist');
        $evaluatorIds = $canAssignEvaluators ? ($data['evaluators'] ?? []) : [];
        $submittedDocumentIds = $canManageChecklist ? ($data['required_documents'] ?? []) : [];
        unset($data['evaluators'], $data['required_documents']);

        $requiredDocuments = RequiredDocument::query()->orderBy('id')->get();
        [$submittedDocumentIds, $lackingDocuments] = $this->normalizeChecklist(
            $requiredDocuments,
            $submittedDocumentIds
        );

        if ($requiredDocuments->isNotEmpty()) {
            $data['document_status'] = $canManageChecklist && $lackingDocuments->isEmpty() ? 'Complete' : 'Incomplete';
        }

        if ($request->user()->dataScope() === 'province') {
            $data['province'] = $request->user()->province;
        }



        $data['user_id'] = $request->user()->id;
        $data['date_updated'] = today();

        $proposal = Proposal::create($data);
        $generatedCode = $this->proposalCodes->generateIfReady($proposal);
        $proposal->evaluators()->syncWithPivotValues($evaluatorIds, ['evaluation_status' => 'Assigned']);

        if ($canManageChecklist && $requiredDocuments->isNotEmpty()) {
            $this->syncDocumentStatuses($proposal, $requiredDocuments, $submittedDocumentIds);
            $this->recordChecklistSnapshot($proposal, $lackingDocuments, $request);
        }

        $this->activity->record($proposal, $request->user(), 'Created proposal');
        if ($generatedCode) {
            $this->activity->record($proposal, $request->user(), 'Generated Proposal Code', ['proposal_code' => $generatedCode]);
        }

        return redirect()
            ->route('proposals.show', $proposal)
            ->with('success', 'Proposal created successfully.');
    }

    public function show(Request $request, Proposal $proposal)
    {
        $this->guard($request, $proposal);

        if ($request->user()->isEvaluator()) {
            abort_unless($request->user()->hasPermission('evaluator_portal.view'), 403);
            return redirect()->route('evaluator.proposals.show', $proposal);
        }

        abort_unless(
            $request->user()->hasPermission('proposals.view')
                || ($proposal->isFunded() && $request->user()->hasPermission('funded.view')),
            403
        );

        $proposal->load([
            'owner',
            'industrySector',
            'evaluators',
            'evaluatorRemarks.evaluator',
            'evaluatorRemarks.user',
            'evaluatorRemarks.replies.user',
            'missingDocuments.creator',
            'documentStatuses.requiredDocument',
            'comments.user',
            'comments.replies.user',
            'activities.user',
            'files.uploader',
        ]);

        $proposal->activities->each(function ($activity) {
            if ($activity->action === 'Evaluator status changed' && ! empty($activity->metadata['to'])) {
                $evaluatorName = ! empty($activity->metadata['evaluator_name'])
                    ? " for {$activity->metadata['evaluator_name']}"
                    : '';
                $from = ! empty($activity->metadata['from']) ? " from {$activity->metadata['from']}" : '';
                $activity->action = "Evaluator status changed{$evaluatorName}{$from} to {$activity->metadata['to']}";
            }
        });

        $requiredDocumentRecords = RequiredDocument::query()->orderBy('id')->get();
        $requiredDocuments = $requiredDocumentRecords->pluck('name')->values();

        return view('proposals.show', compact('proposal', 'requiredDocuments', 'requiredDocumentRecords'));
    }

    public function documentChecklist(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot generate proposal document checklists.');
        $this->guard($request, $proposal);

        abort_unless(
            $request->user()->hasPermission('proposals.view')
                || ($proposal->isFunded() && $request->user()->hasPermission('funded.view')),
            403
        );

        $proposal->loadMissing(['documentStatuses.requiredDocument', 'missingDocuments']);
        $requiredDocuments = RequiredDocument::query()->orderBy('id')->get();
        $storedDocumentStates = $proposal->documentStatuses->keyBy('required_document_id');
        $latestDocumentReview = $proposal->missingDocuments->sortByDesc('created_at')->first();
        $latestLackingDocuments = collect(preg_split('/\r\n|\r|\n/', $latestDocumentReview?->description ?? ''))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->reject(fn ($name) => strcasecmp($name, 'No lacking documents') === 0)
            ->values();

        $checklistItems = $requiredDocuments->map(function (RequiredDocument $document) use ($proposal, $storedDocumentStates, $latestDocumentReview, $latestLackingDocuments) {
            $stored = $storedDocumentStates->get($document->id)?->status;

            if ($stored) {
                $status = $stored;
            } else {
                $changedAfterReview = $latestDocumentReview
                    && $document->updated_at
                    && $document->updated_at->gt($latestDocumentReview->created_at);

                if ($proposal->document_status === 'Complete' && ! $changedAfterReview) {
                    $status = ProposalDocumentStatus::SUBMITTED;
                } elseif (! $latestDocumentReview || $changedAfterReview) {
                    $status = ProposalDocumentStatus::PENDING;
                } elseif ($latestLackingDocuments->contains($document->name)) {
                    $status = ProposalDocumentStatus::LACKING;
                } else {
                    $status = ProposalDocumentStatus::SUBMITTED;
                }
            }

            return [
                'document' => $document,
                'status' => $status,
                'complied' => $status === ProposalDocumentStatus::SUBMITTED,
                'not_complied' => $status === ProposalDocumentStatus::LACKING,
                'remarks' => match ($status) {
                    ProposalDocumentStatus::LACKING => 'Lacking',
                    ProposalDocumentStatus::NOT_APPLICABLE => 'Not Applicable',
                    ProposalDocumentStatus::PENDING => 'Pending review',
                    default => '',
                },
            ];
        });

        return view('proposals.required-document-checklist', compact('proposal', 'checklistItems'));
    }

    public function edit(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot edit proposal records.');
        $this->guard($request, $proposal);

        return view('proposals.form', $this->formData($proposal, $request));
    }

    public function update(ProposalRequest $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot edit proposal records.');
        $this->guard($request, $proposal);

        $proposal->loadMissing(['evaluators', 'missingDocuments', 'documentStatuses']);
        $requiredDocuments = RequiredDocument::query()->orderBy('id')->get();
        $previousSubmittedDocumentIds = $this->selectedRequiredDocumentIds($proposal, $requiredDocuments);

        $before = $proposal->only(['status', 'document_status', 'amount', 'proposal_code']);
        $data = $this->withLocationMetadata($request->validated(), $proposal);
        $canAssignEvaluators = $request->user()->hasPermission('proposals.assign_evaluators');
        $canManageChecklist = $request->user()->hasPermission('proposals.document_checklist');
        $evaluatorIds = $canAssignEvaluators ? ($data['evaluators'] ?? []) : [];
        $submittedDocumentIds = $canManageChecklist ? ($data['required_documents'] ?? []) : [];
        unset($data['evaluators'], $data['required_documents']);

        [$submittedDocumentIds, $lackingDocuments] = $this->normalizeChecklist(
            $requiredDocuments,
            $submittedDocumentIds
        );

        if ($requiredDocuments->isNotEmpty()) {
            if ($canManageChecklist) {
                $data['document_status'] = $lackingDocuments->isEmpty() ? 'Complete' : 'Incomplete';
            } else {
                $data['document_status'] = $proposal->document_status;
                $submittedDocumentIds = $previousSubmittedDocumentIds;
                [, $lackingDocuments] = $this->normalizeChecklist($requiredDocuments, $submittedDocumentIds);
            }
        }



        // Include archived evaluators while rebuilding pivot assignments. An
        // archived evaluator is intentionally hidden from the edit form, but its
        // existing assignment must remain intact so restoring that evaluator also
        // restores the original proposal workload.
        $existingEvaluators = $proposal->evaluators()->withTrashed()->get()->keyBy('id');
        $previousEvaluatorIds = $existingEvaluators->keys()->map(fn ($id) => (int) $id)->sort()->values();
        $archivedEvaluatorIds = $existingEvaluators
            ->filter(fn ($evaluator) => $evaluator->trashed())
            ->keys()
            ->map(fn ($id) => (int) $id);

        $effectiveEvaluatorIds = ($canAssignEvaluators ? collect($evaluatorIds) : $existingEvaluators->keys())
            ->map(fn ($id) => (int) $id)
            ->merge($archivedEvaluatorIds)
            ->unique()
            ->values();

        $assignments = [];
        foreach ($effectiveEvaluatorIds as $id) {
            $assignments[$id] = [
                'evaluation_status' => $existingEvaluators->get($id)?->pivot->evaluation_status ?? 'Assigned',
            ];
        }

        $proposal->update($data + ['date_updated' => today()]);
        $proposalChangedFields = collect(array_keys($proposal->getChanges()))
            ->reject(fn ($field) => in_array($field, ['updated_at', 'date_updated'], true))
            ->values();
        $proposal->evaluators()->sync($assignments);

        $currentEvaluatorIds = $effectiveEvaluatorIds->sort()->values();
        $evaluatorAssignmentsChanged = $canAssignEvaluators
            && $previousEvaluatorIds->all() !== $currentEvaluatorIds->all();

        $checklistChanged = $canManageChecklist
            && $requiredDocuments->isNotEmpty()
            && collect($previousSubmittedDocumentIds)->sort()->values()->all()
                !== collect($submittedDocumentIds)->sort()->values()->all();

        if ($checklistChanged) {
            $proposalChangedFields = $proposalChangedFields->reject(fn ($field) => $field === 'document_status')->values();
        }

        if ($checklistChanged) {
            $this->syncDocumentStatuses($proposal, $requiredDocuments, $submittedDocumentIds);
            $proposal->load('documentStatuses');
            $storedLackingIds = $proposal->documentStatuses
                ->where('status', ProposalDocumentStatus::LACKING)
                ->pluck('required_document_id')
                ->map(fn ($id) => (int) $id);
            $snapshotLackingDocuments = $requiredDocuments
                ->filter(fn ($document) => $storedLackingIds->contains((int) $document->id))
                ->values();

            $this->recordChecklistSnapshot($proposal, $snapshotLackingDocuments, $request);
            $this->activity->record($proposal, $request->user(), 'Updated required documents checklist', [
                'submitted' => count($submittedDocumentIds),
                'required' => $requiredDocuments->count(),
                'lacking' => $snapshotLackingDocuments->count(),
            ]);
        }

        if ($proposalChangedFields->isNotEmpty()) {
            $proposalBecameFunded = $proposalChangedFields->contains('date_funded')
                && empty($before['date_funded'])
                && $proposal->date_funded !== null;

            $this->activity->record($proposal, $request->user(), $proposalBecameFunded ? 'Proposal marked as Funded' : 'Updated proposal', [
                'before' => $before,
                'fields' => $proposalChangedFields->implode(', '),
            ]);
        }

        if ($evaluatorAssignmentsChanged) {
            $this->activity->record($proposal, $request->user(), 'Updated evaluator assignments', [
                'assigned' => $currentEvaluatorIds->diff($previousEvaluatorIds)->count(),
                'removed' => $previousEvaluatorIds->diff($currentEvaluatorIds)->count(),
                'total' => $currentEvaluatorIds->count(),
            ]);
        }

        $generatedCode = $this->proposalCodes->generateIfReady($proposal);
        if ($generatedCode && ($before['proposal_code'] ?? null) !== $generatedCode) {
            $this->activity->record($proposal, $request->user(), 'Generated Proposal Code', ['proposal_code' => $generatedCode]);
        }

        return redirect()
            ->route('proposals.show', $proposal)
            ->with('success', 'Proposal updated successfully.');
    }

    public function updateEvaluatorStatus(Request $request, Proposal $proposal, Evaluator $evaluator)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts can only update their own status from the Evaluator Portal.');
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.manage_evaluator_status'), 403);
        abort_unless(
            $request->user()->hasPermission('proposals.view')
                || ($proposal->isFunded() && $request->user()->hasPermission('funded.view')),
            403
        );

        abort_unless(
            $proposal->evaluators()->whereKey($evaluator->id)->exists(),
            422,
            'This evaluator is not assigned to the proposal.'
        );

        $data = $request->validate([
            'evaluation_status' => ['required', Rule::in(['Assigned', 'Started', 'Ongoing', 'Approved'])],
        ]);

        $pivot = DB::table('evaluator_proposal')
            ->where('proposal_id', $proposal->id)
            ->where('evaluator_id', $evaluator->id)
            ->first(['evaluation_status', 'evaluated_at']);

        $oldStatus = $pivot?->evaluation_status ?: 'Assigned';
        $newStatus = $data['evaluation_status'];

        if ($oldStatus === $newStatus) {
            return back()->with('info', $evaluator->full_name.' is already '.$newStatus.'.');
        }

        $pivotUpdate = ['evaluation_status' => $newStatus];

        // RTEC Monitoring uses the first Approved timestamp as the evaluator's
        // completion date. Preserve that original completion date if a review is
        // reopened later, matching the evaluator portal's existing behavior.
        if ($newStatus === 'Approved' && empty($pivot?->evaluated_at)) {
            $pivotUpdate['evaluated_at'] = now();
        }

        $proposal->evaluators()->updateExistingPivot($evaluator->id, $pivotUpdate);

        $this->activity->record(
            $proposal,
            $request->user(),
            'Evaluator status changed',
            [
                'evaluator_id' => $evaluator->id,
                'evaluator_name' => $evaluator->full_name,
                'from' => $oldStatus,
                'to' => $newStatus,
                'source' => 'manual_override',
            ]
        );

        return back()->with('success', $evaluator->full_name.' status updated from '.$oldStatus.' to '.$newStatus.'.');
    }

    public function updateMoaStatus(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot modify proposal workflow status.');
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.workflow'), 403);

        $data = $request->validate([
            'moa_status' => ['required', 'in:Pending,Forwarded to PSTO for MOA signing,Returned to RPMO for signing,For Notarization,Forwarded to accounting for obligation,Completed'],
        ]);

        $proposal->update([
            'moa_status' => $data['moa_status'],
            'moa_status_updated_at' => now(),
        ]);
        $this->activity->record($proposal, $request->user(), 'Updated MOA status', [
            'to' => $data['moa_status'],
        ]);

        return back()->with('success', 'MOA status updated.');
    }

    public function updateSummary(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot edit proposal records.');
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.edit'), 403);

        $field = $request->validate([
            'field' => ['required', Rule::in(['status', 'funded_project_status', 'date_approved', 'date_funded'])],
        ])['field'];

        $value = $request->validate([
            'value' => $field === 'status'
                ? ['required', Rule::in(ProposalStatus::values())]
                : ($field === 'funded_project_status' ? ['required', Rule::in(\App\Enums\FundedProjectStatus::values())] : ['nullable', 'date']),
        ])['value'] ?? null;

        if ($field === 'funded_project_status' && !$proposal->date_funded) {
            return back()->withErrors(['value' => 'Record a Date Funded before setting Funded Project Status.']);
        }

        if ($field === 'date_approved' && $proposal->proposal_code) {
            $currentApprovalDate = $proposal->date_approved?->format('Y-m-d');
            if (($value ?: null) !== $currentApprovalDate) {
                return back()->with('error', 'The approval date is locked because the Proposal Code has already been generated.');
            }
        }

        if ($field === 'status' && $proposal->proposal_code && $value !== 'Approved') {
            return back()->with('error', 'A coded proposal must remain Approved.');
        }

        $before = $proposal->only(['status', 'funded_project_status', 'date_approved', 'date_funded', 'proposal_code']);
        $updates = [$field => $value === '' ? null : $value];

        if ($field === 'date_approved') {
            $updates['quarter_approved'] = $value ? (int) ceil((int) date('n', strtotime($value)) / 3) : null;
        } elseif ($field === 'date_funded') {
            $updates['quarter_funded'] = $value ? (int) ceil((int) date('n', strtotime($value)) / 3) : null;

        }

        $proposal->update($updates + ['date_updated' => today()]);
        $changedFields = collect(array_keys($proposal->getChanges()))
            ->reject(fn ($changedField) => in_array($changedField, ['updated_at', 'date_updated'], true))
            ->values();

        if ($changedFields->isNotEmpty()) {
            $proposalBecameFunded = empty($before['date_funded'])
                && $proposal->date_funded !== null;

            $this->activity->record(
                $proposal,
                $request->user(),
                $proposalBecameFunded ? 'Proposal marked as Funded' : 'Updated proposal',
                ['before' => $before, 'fields' => $changedFields->implode(', ')]
            );
        }

        $generatedCode = $this->proposalCodes->generateIfReady($proposal);
        if ($generatedCode && ($before['proposal_code'] ?? null) !== $generatedCode) {
            $this->activity->record($proposal, $request->user(), 'Generated Proposal Code', ['proposal_code' => $generatedCode]);
        }

        return back()->with('success', match ($field) {
            'status' => 'Proposal status updated.',
            'funded_project_status' => 'Funded Project Status updated.',
            'date_approved' => 'Approval date updated.',
            'date_funded' => 'Funding date updated.',
        });
    }

    public function updateCheckStatus(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot modify proposal workflow status.');
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.workflow'), 403);

        $data = $request->validate([
            'check_status' => ['required', 'in:Pending,Obligated,Check available for pick up,Completed'],
        ]);

        $proposal->update([
            'check_status' => $data['check_status'],
            'check_status_updated_at' => now(),
        ]);
        $this->activity->record($proposal, $request->user(), 'Updated check status', [
            'to' => $data['check_status'],
        ]);

        return back()->with('success', 'Check status updated.');
    }

    public function destroy(Request $request, Proposal $proposal)
    {
        abort_if($request->user()->isEvaluator(), 403, 'Evaluator accounts cannot archive proposal records.');
        $this->guard($request, $proposal);
        abort_unless($request->user()->hasPermission('proposals.archive'), 403);

        $this->activity->record($proposal, $request->user(), 'Archived proposal');
        $proposal->forceFill(['deleted_by' => $request->user()->id])->save();
        $proposal->delete();

        return redirect()->route('proposals.index')->with('success', 'Proposal moved to Archives.');
    }

    /**
     * Return the required-document IDs currently considered submitted for a
     * proposal. This mirrors the proposal-side checklist logic so the Edit form
     * opens with the same state the user already sees on the record page.
     */
    private function selectedRequiredDocumentIds(Proposal $proposal, Collection $requiredDocuments): array
    {
        if ($requiredDocuments->isEmpty()) {
            return [];
        }

        $proposal->loadMissing('documentStatuses');
        if ($proposal->documentStatuses->isNotEmpty()) {
            return $proposal->documentStatuses
                ->filter(fn ($status) => in_array($status->status, [
                    ProposalDocumentStatus::SUBMITTED,
                    ProposalDocumentStatus::NOT_APPLICABLE,
                ], true))
                ->pluck('required_document_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $latestReview = $proposal->missingDocuments->sortByDesc('created_at')->first();
        $latestLacking = $this->lackingNamesFromReview($latestReview?->description);

        return $requiredDocuments
            ->filter(function (RequiredDocument $requiredDocument) use ($proposal, $latestReview, $latestLacking) {
                $changedAfterReview = $latestReview
                    && $requiredDocument->updated_at
                    && $requiredDocument->updated_at->gt($latestReview->created_at);

                if ($changedAfterReview) {
                    return false;
                }

                if ($proposal->document_status === 'Complete') {
                    return true;
                }

                if (! $latestReview) {
                    return false;
                }

                return ! $latestLacking->contains($requiredDocument->name);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function withLocationMetadata(array $data, ?Proposal $proposal = null): array
    {
        $hasCoordinates = ($data['latitude'] ?? null) !== null && ($data['longitude'] ?? null) !== null;
        if (! $hasCoordinates) {
            return $data + [
                'latitude' => null,
                'longitude' => null,
                'location_source' => null,
                'geocoded_at' => null,
            ];
        }

        $coordinatesUnchanged = $proposal
            && (string) $proposal->latitude === number_format((float) $data['latitude'], 7, '.', '')
            && (string) $proposal->longitude === number_format((float) $data['longitude'], 7, '.', '')
            && $proposal->location_source === $data['location_source'];

        $data['geocoded_at'] = $coordinatesUnchanged && $proposal->geocoded_at
            ? $proposal->geocoded_at
            : now();

        return $data;
    }

    /**
     * Restrict submitted IDs to the current master list and return both the
     * normalized submitted IDs and the RequiredDocument models still lacking.
     */
    private function normalizeChecklist(Collection $requiredDocuments, array $submittedDocumentIds): array
    {
        $validIds = $requiredDocuments->pluck('id')->map(fn ($id) => (int) $id);
        $submitted = collect($submittedDocumentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $validIds->contains($id))
            ->unique()
            ->values();

        $lacking = $requiredDocuments
            ->reject(fn (RequiredDocument $document) => $submitted->contains((int) $document->id))
            ->values();

        return [$submitted->all(), $lacking];
    }

    /**
     * Persist checklist state in a stable proposal/document table. Imported N/A
     * and Pending states are preserved when the corresponding checkbox was not
     * changed; deliberate checkbox changes become Submitted/Lacking.
     */
    private function syncDocumentStatuses(Proposal $proposal, Collection $requiredDocuments, array $submittedDocumentIds, string $source = 'manual'): void
    {
        $proposal->loadMissing('documentStatuses');
        $existing = $proposal->documentStatuses->keyBy('required_document_id');
        $submitted = collect($submittedDocumentIds)->map(fn ($id) => (int) $id)->flip();

        foreach ($requiredDocuments as $document) {
            $documentId = (int) $document->id;
            $isSubmitted = $submitted->has($documentId);
            $previous = $existing->get($documentId)?->status;
            $previousSatisfied = in_array($previous, [
                ProposalDocumentStatus::SUBMITTED,
                ProposalDocumentStatus::NOT_APPLICABLE,
            ], true);

            if ($previous && $previousSatisfied === $isSubmitted) {
                $status = $previous;
            } else {
                $status = $isSubmitted
                    ? ProposalDocumentStatus::SUBMITTED
                    : ProposalDocumentStatus::LACKING;
            }

            ProposalDocumentStatus::updateOrCreate(
                ['proposal_id' => $proposal->id, 'required_document_id' => $documentId],
                ['status' => $status, 'source' => $source]
            );
        }
    }

    /**
     * Store a document-review snapshot using the existing missing_documents
     * history. This avoids a second competing checklist table while preserving
     * every checklist change as historical review data.
     */
    private function recordChecklistSnapshot(Proposal $proposal, Collection $lackingDocuments, Request $request): void
    {
        $proposal->load('evaluators');

        $evaluationStatus = $proposal->evaluators->isNotEmpty()
            ? $proposal->evaluators
                ->map(fn ($evaluator) => sprintf(
                    '%s (%s): %s',
                    $evaluator->full_name,
                    $evaluator->classification,
                    $evaluator->pivot?->evaluation_status ?: 'Assigned'
                ))
                ->implode("\n")
            : 'No evaluator assigned';

        $description = $lackingDocuments->isEmpty()
            ? 'No lacking documents'
            : $lackingDocuments->pluck('name')->implode("\n");

        $proposal->missingDocuments()->create([
            'description' => $description,
            'evaluation_status' => $evaluationStatus,
            'moa_check' => null,
            'created_by' => $request->user()->id,
        ]);
    }

    private function lackingNamesFromReview(?string $description): Collection
    {
        return collect(preg_split('/\r\n|\r|\n/', $description ?? ''))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->reject(fn ($name) => strcasecmp($name, 'No lacking documents') === 0)
            ->values();
    }

    private function guard(Request $request, Proposal $proposal): void
    {
        if ($request->user()->dataScope() === 'province') {
            abort_unless($proposal->province === $request->user()->province, 403);
        }

        if ($request->user()->isEvaluator()) {
            abort_unless(
                $proposal->evaluators()->where('email', $request->user()->email)->exists(),
                403
            );
        }
    }
}
